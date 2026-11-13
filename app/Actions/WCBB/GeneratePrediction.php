<?php

namespace App\Actions\WCBB;

use App\Actions\Sports\AbstractCollegeBasketballPredictionGenerator;
use App\Models\WCBB\Game;
use App\Models\WCBB\Prediction;
use App\Models\WCBB\TeamMetric;
use App\Models\WCBB\TeamStat;
use App\Services\Predictions\Basketball\CollegeBasketballPregameEligibility;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class GeneratePrediction extends AbstractCollegeBasketballPredictionGenerator
{
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
        $query = TeamMetric::query()
            ->where('team_id', $teamId)
            ->where('updated_at', '<=', $capturedAt)
            ->whereDate('calculation_date', '<=', $throughDate)
            ->orderByDesc('calculation_date')
            ->orderByDesc('id');
        $current = (clone $query)->where('season', $season)->first();
        if ($current !== null && $this->unusableMetricFields($current) === []) {
            return $current;
        }

        $previous = $query->where('season', $season - 1)->first();

        return $previous !== null && $this->unusableMetricFields($previous) === [] ? $previous : ($current ?? $previous);
    }

    /** @return list<array{side: string, metric_id: int, reason: string}> */
    public function metricInputHolds(Game $game): array
    {
        $holds = [];
        $metrics = $this->teamMetricsForGame($game, $game->home_team_id, $game->away_team_id);
        foreach (['home', 'away'] as $index => $side) {
            $metric = $metrics[$index];
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
