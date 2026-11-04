<?php

namespace App\Actions\CBB;

use App\Actions\Sports\AbstractCollegeBasketballPredictionGenerator;
use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use App\Models\CBB\TeamMetric;
use App\Models\CBB\TeamStat;
use App\Services\CBB\CbbDataQuality;
use App\Services\CBB\CbbPregameEligibility;
use App\Services\CBB\CbbSeasonInitializer;
use App\Services\CBB\CbbSeasonInputs;
use App\Services\Predictions\PredictionDeliverySource;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

class GeneratePrediction extends AbstractCollegeBasketballPredictionGenerator
{
    protected const SPORT_KEY = 'cbb';

    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const PREDICTION_MODEL = Prediction::class;

    protected const GAME_MODEL = Game::class;

    protected const TEAM_STAT_MODEL = TeamStat::class;

    protected const MODEL_VERSION = 'cbb-season-v2';

    private array $seasonEvidence = [];

    public function execute(Model $game, bool $dispatchNarratives = true): ?Model
    {
        app(PredictionDeliverySource::class)->assertLegacyWriterAvailable('cbb');
        $start = $game->sportEvent?->starts_at ?? app(SportsDateWindowService::class)->gameDateTimeUtc($game->getRawOriginal('game_date'), $game->game_time);
        if (! ctype_digit((string) $game->espn_event_id) || ! $game->homeTeam || ! $game->awayTeam
            || ! $this->shouldGeneratePredictionForGame($game, $game->homeTeam, $game->awayTeam)
            || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true) || $start === null || $start->lte(now())) {
            return null;
        }
        app(CbbSeasonInitializer::class)->initialize((int) $game->season);

        return DB::transaction(function () use ($game, $dispatchNarratives): ?Model {
            $locked = Game::query()->with(['homeTeam', 'awayTeam', 'sportEvent'])->lockForUpdate()->findOrFail($game->getKey());
            $start = $locked->sportEvent?->starts_at
                ?? app(SportsDateWindowService::class)->gameDateTimeUtc($locked->getRawOriginal('game_date'), $locked->game_time);
            if (! in_array($locked->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true) || $start === null
                || ($locked->sportEvent?->starts_at === null && blank($locked->game_time)) || $start->lte(now())) {
                return null;
            }
            $prediction = parent::execute($locked, false);
            if ($start->lte(now())) {
                throw new LogicException('CBB pregame window closed during generation; prediction was rolled back.');
            }
            if ($prediction !== null && $dispatchNarratives) {
                DB::afterCommit(fn () => $this->dispatchNarrativeGeneration($prediction));
            }

            return $prediction;
        });
    }

    public function executeHistorical(Model $game, bool $dispatchNarratives = false, array $snapshotOverrides = []): ?Model
    {
        return $game->prediction()->first() ?? parent::executeHistorical($game, $dispatchNarratives, $snapshotOverrides);
    }

    protected function teamMetricsForGame(Model $game, int $homeTeamId, int $awayTeamId): array
    {
        if (! $game instanceof Game) {
            throw new LogicException('CBB season inputs require a CBB game.');
        }
        $inputs = app(CbbSeasonInputs::class);
        $home = $inputs->forTeam($homeTeamId, $game);
        $away = $inputs->forTeam($awayTeamId, $game);
        $this->seasonEvidence = ['home' => $home['evidence'], 'away' => $away['evidence']];

        return [$home['metric'], $away['metric']];
    }

    protected function constrainStatGames(Builder $query): void
    {
        app(CbbDataQuality::class)->reconciledGames($query);
    }

    protected function spreadComponentWeights(?Model $homeMetrics, ?Model $awayMetrics, array $homeForm, array $awayForm, array $config): array
    {
        $weights = [
            'elo' => (float) $config['elo_weight'],
            'efficiency' => $homeMetrics !== null && $awayMetrics !== null ? (float) $config['efficiency_weight'] : 0.0,
            'form' => ($homeForm['games_count'] ?? 0) > 0 && ($awayForm['games_count'] ?? 0) > 0 ? (float) $config['form_weight'] : 0.0,
        ];
        $total = array_sum($weights);

        return array_map(fn (float $weight): float => $weight / $total, $weights);
    }

    protected function getVegasSpread(Model $game): ?float
    {
        if ($game->odds_updated_at === null || $game->odds_updated_at->lt(now()->subHours(36))) {
            return null;
        }

        return parent::getVegasSpread($game);
    }

    protected function winProbabilityCalibration(float $baseline): array
    {
        $result = parent::winProbabilityCalibration($baseline);
        if (($result['active_source'] ?? 'baseline') !== 'baseline') {
            $result['active_source'] = 'baseline';
            $result['active_win_probability'] = $result['baseline_win_probability'];
            $result['apply_to_live_output'] = false;
            $result['reason'] = 'cbb_season_v2_requires_validation';
        }

        return $result;
    }

    protected function makePredictionData(Model $game, bool $allowCompleted = false): ?array
    {
        if (! ctype_digit((string) $game->espn_event_id) || ! $game->homeTeam || ! $game->awayTeam
            || ! $this->shouldGeneratePredictionForGame($game, $game->homeTeam, $game->awayTeam)) {
            return null;
        }
        $data = parent::makePredictionData($game, $allowCompleted);
        if ($data === null) {
            return null;
        }
        $metadata = $data['model_metadata'] ?? [];
        $metadata['season_inputs'] = $this->seasonEvidence;
        $metadata['provisional'] = collect($this->seasonEvidence)->contains(fn (array $team): bool => $team['provisional']);
        $metadata['statistical_inputs_available'] = collect($this->seasonEvidence)->every(fn (array $team): bool => $team['source'] !== 'baseline');
        $metadata['probability_validation'] = 'uncalibrated';
        $data['model_metadata'] = $metadata;
        $data['_snapshot']['model_metadata'] = $metadata;

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function preview(Game $game): ?array
    {
        return $this->makePredictionData($game);
    }

    protected function shouldGeneratePredictionForGame(Model $game, Model $homeTeam, Model $awayTeam): bool
    {
        return app(CbbPregameEligibility::class)->teamsAreKnown($homeTeam, $awayTeam);
    }
}
