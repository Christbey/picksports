<?php

namespace App\Actions\WCBB;

use App\Actions\Sports\AbstractCollegeBasketballPredictionGenerator;
use App\Models\WCBB\Game;
use App\Models\WCBB\Prediction;
use App\Models\WCBB\TeamMetric;
use App\Models\WCBB\TeamStat;
use App\Services\Predictions\Basketball\CollegeBasketballPregameEligibility;
use App\Services\WCBB\WcbbMetricSourceEvidence;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;

class GeneratePrediction extends AbstractCollegeBasketballPredictionGenerator
{
    private ?WcbbMetricSourceEvidence $sourceEvidence = null;

    public function usingSourceEvidence(WcbbMetricSourceEvidence $evidence, Closure $callback): mixed
    {
        $previous = $this->sourceEvidence;
        try {
            $this->sourceEvidence = $evidence;

            return $callback();
        } finally {
            $this->sourceEvidence = $previous;
        }
    }

    private function sourceEvidence(): WcbbMetricSourceEvidence
    {
        return $this->sourceEvidence ?? app(WcbbMetricSourceEvidence::class);
    }

    /** @return array<string, mixed>|null */
    public function preview(Game $game): ?array
    {
        return $this->makePredictionData($game);
    }

    protected function shouldGeneratePredictionForGame(Model $game, Model $homeTeam, Model $awayTeam): bool
    {
        return app(CollegeBasketballPregameEligibility::class)->teamsAreKnown($homeTeam, $awayTeam);
    }

    protected function teamMetricsForGame(Model $game, int $homeTeamId, int $awayTeamId): array
    {
        if (! config('wcbb.prediction.recover_invalid_team_metrics', false)) {
            return parent::teamMetricsForGame($game, $homeTeamId, $awayTeamId);
        }

        $capturedAt = now();
        $throughDate = $game->game_date->toDateString();

        return [
            $this->metricWithPreviousSeasonFallback($homeTeamId, (int) $game->season, $capturedAt, $throughDate),
            $this->metricWithPreviousSeasonFallback($awayTeamId, (int) $game->season, $capturedAt, $throughDate),
        ];
    }

    public function metricWithPreviousSeasonFallback(int $teamId, int $season, CarbonInterface $capturedAt, string $throughDate): ?TeamMetric
    {
        $evidence = $this->sourceEvidence();
        $candidates = $evidence->metricCandidates($teamId, $season, $capturedAt, $throughDate);
        $current = $candidates['current'];
        $coverage = $evidence->coverage($teamId, $season, $capturedAt, $throughDate);
        $currentSourcesComplete = $coverage['completed_games'] === $coverage['usable_paired_games'];
        if ($current !== null && $this->unusableMetricFields($current) === [] && $currentSourcesComplete) {
            return $current;
        }

        $previous = $candidates['previous'];

        return $previous !== null && $this->unusableMetricFields($previous) === [] ? $previous : ($current ?? $previous);
    }

    /** @return array{home: ?TeamMetric, away: ?TeamMetric} */
    public function selectedMetricsForGame(Game $game): array
    {
        $metrics = $this->teamMetricsForGame($game, $game->home_team_id, $game->away_team_id);

        return ['home' => $metrics[0], 'away' => $metrics[1]];
    }

    /** @return list<array{side: string, metric_id: ?int, reason: string}> */
    public function metricInputHolds(Game $game, ?array $selectedMetrics = null): array
    {
        $holds = [];
        $metrics = $selectedMetrics ?? $this->selectedMetricsForGame($game);
        foreach (['home', 'away'] as $index => $side) {
            $metric = $metrics[$side];
            $teamId = $side === 'home' ? $game->home_team_id : $game->away_team_id;
            if ($metric === null || (int) $metric->season === (int) $game->season) {
                $coverage = $this->sourceEvidence()->coverage((int) $teamId, (int) $game->season, now(), $game->game_date->toDateString());
                if ($coverage['completed_games'] > $coverage['usable_paired_games']) {
                    $holds[] = ['side' => $side, 'metric_id' => $metric?->getKey(), 'reason' => 'incomplete_metric_source_stats'];
                }
            }
            if ($metric === null) {
                continue;
            }
            foreach ($this->unusableMetricFields($metric) as $field) {
                $holds[] = ['side' => $side, 'metric_id' => (int) $metric->getKey(), 'reason' => 'unusable_'.$field];
            }
        }

        return $holds;
    }

    /** @return list<string> */
    private function unusableMetricFields(Model $metric): array
    {
        $unusable = [];
        foreach (['offensive_efficiency', 'defensive_efficiency', 'tempo'] as $field) {
            $value = $metric->getAttribute($field);
            if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0) {
                $unusable[] = $field;
            }
        }

        return $unusable;
    }

    protected const SPORT_KEY = 'wcbb';

    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const PREDICTION_MODEL = Prediction::class;

    protected const GAME_MODEL = Game::class;

    protected const TEAM_STAT_MODEL = TeamStat::class;
}
