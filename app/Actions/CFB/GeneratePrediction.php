<?php

namespace App\Actions\CFB;

use App\Actions\Sports\AbstractAmericanFootballPredictionGenerator;
use App\Models\CFB\EloRating;
use App\Models\CFB\Prediction;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Support\CfbSeasonAffiliationResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class GeneratePrediction extends AbstractAmericanFootballPredictionGenerator
{
    protected const SPORT_KEY = 'cfb';

    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const PREDICTION_MODEL = Prediction::class;

    public function __construct(
        private readonly CfbSeasonAffiliationResolver $seasonAffiliationResolver = new CfbSeasonAffiliationResolver,
    ) {}

    protected function calculatePredictedSpread(
        int $homeElo,
        int $awayElo,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        $baseSpread = parent::calculatePredictedSpread($homeElo, $awayElo, $homeMetrics, $awayMetrics, $game);

        $fpiDiff = (float) ($homeMetrics?->fpi ?? 0.0) - (float) ($awayMetrics?->fpi ?? 0.0);
        $wepaNetDiff = (float) ($homeMetrics?->cfbd_wepa_net ?? 0.0) - (float) ($awayMetrics?->cfbd_wepa_net ?? 0.0);
        $efficiencyDiff = (float) ($homeMetrics?->net_rating ?? 0.0) - (float) ($awayMetrics?->net_rating ?? 0.0);

        $spread = $baseSpread
            + ($fpiDiff * $this->predictionWeight('fpi_spread_weight', 0.18))
            + ($wepaNetDiff * $this->predictionWeight('wepa_spread_weight', 4.5))
            + ($efficiencyDiff * $this->predictionWeight('efficiency_spread_weight', 0.04));

        $maxSpread = (float) config('cfb.predictions.max_spread', 40);
        $minSpread = (float) config('cfb.predictions.min_spread', -40);

        return round(max($minSpread, min($maxSpread, $spread)), 1);
    }

    protected function calculatePredictedTotal(
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        $baseTotal = $this->baselineTotal($homeMetrics, $awayMetrics);

        $offenseWepa = (float) ($homeMetrics?->cfbd_wepa_offense ?? 0.0) + (float) ($awayMetrics?->cfbd_wepa_offense ?? 0.0);
        $defenseWepa = (float) ($homeMetrics?->cfbd_wepa_defense ?? 0.0) + (float) ($awayMetrics?->cfbd_wepa_defense ?? 0.0);
        $fpiTotal = (float) ($homeMetrics?->fpi ?? 0.0) + (float) ($awayMetrics?->fpi ?? 0.0);

        $total = $baseTotal
            + ($offenseWepa * $this->predictionWeight('wepa_total_offense_weight', 2.2))
            - ($defenseWepa * $this->predictionWeight('wepa_total_defense_weight', 1.4))
            + ($fpiTotal * $this->predictionWeight('fpi_total_weight', 0.08));

        $minTotal = (float) config('cfb.predictions.min_total', 28);
        $maxTotal = (float) config('cfb.predictions.max_total', 88);

        return round(max($minTotal, min($maxTotal, $total)), 1);
    }

    protected function buildPredictionData(
        int $homeElo,
        int $awayElo,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        float $predictedSpread,
        float $predictedTotal,
        float $winProbability,
        float $confidenceScore
    ): array {
        return [
            'home_elo' => $homeElo,
            'away_elo' => $awayElo,
            'home_fpi' => $homeMetrics?->fpi,
            'away_fpi' => $awayMetrics?->fpi,
            'predicted_spread' => $predictedSpread,
            'predicted_total' => $predictedTotal,
            'win_probability' => $winProbability,
            'confidence_score' => $confidenceScore,
            'model_version' => $this->modelVersion(),
            'feature_version' => $this->featureVersion(),
            'blend_version' => $this->blendVersion(),
        ];
    }

    protected function baselineTotal(?Model $homeMetrics, ?Model $awayMetrics): float
    {
        $averageTotal = (float) config('cfb.predictions.average_total', 52);

        $homeScoring = (float) ($homeMetrics?->points_per_game ?? 0.0);
        $awayScoring = (float) ($awayMetrics?->points_per_game ?? 0.0);
        $homeAllowed = (float) ($homeMetrics?->points_allowed_per_game ?? 0.0);
        $awayAllowed = (float) ($awayMetrics?->points_allowed_per_game ?? 0.0);

        if ($homeScoring <= 0 || $awayScoring <= 0 || $homeAllowed <= 0 || $awayAllowed <= 0) {
            return $averageTotal;
        }

        $derivedTotal = (($homeScoring + $awayAllowed) / 2) + (($awayScoring + $homeAllowed) / 2);

        return ($averageTotal * 0.35) + ($derivedTotal * 0.65);
    }

    protected function predictionWeight(string $key, float $default): float
    {
        $value = config("cfb.predictions.{$key}");

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @return array{0:int,1:int}
     */
    protected function eloRatingsForGame(Model $game, Model $homeTeam, Model $awayTeam, int $defaultElo): array
    {
        if (! (bool) config('cfb.predictions.use_previous_season_elo_fallback', true)) {
            return parent::eloRatingsForGame($game, $homeTeam, $awayTeam, $defaultElo);
        }

        $homeElo = $this->pointInTimeEloForGame((int) $homeTeam->id, $game, $defaultElo)
            ?? (int) round((float) ($homeTeam->elo_rating ?? $defaultElo));
        $awayElo = $this->pointInTimeEloForGame((int) $awayTeam->id, $game, $defaultElo)
            ?? (int) round((float) ($awayTeam->elo_rating ?? $defaultElo));

        return [$homeElo, $awayElo];
    }

    protected function pointInTimeEloForGame(int $teamId, Model $game, int $defaultElo): ?int
    {
        $sameSeasonElo = $this->latestSameSeasonEloBeforeGame($teamId, $game);

        if ($sameSeasonElo !== null) {
            return (int) round($sameSeasonElo);
        }

        if (! $this->shouldUsePriorSeasonEloFallback($game)) {
            return null;
        }

        $priorSeasonElo = $this->latestPriorSeasonElo($teamId, (int) $game->season);

        if ($priorSeasonElo === null) {
            return null;
        }

        $regressionFactor = (float) config(
            'cfb.predictions.previous_season_elo_regression_factor',
            config('cfb.elo.offseason_regression_factor', 0.30)
        );
        $regressionFactor = max(0.0, min(1.0, $regressionFactor));

        return (int) round($priorSeasonElo + (($defaultElo - $priorSeasonElo) * $regressionFactor));
    }

    protected function latestSameSeasonEloBeforeGame(int $teamId, Model $game): ?float
    {
        $query = EloRating::query()
            ->where('team_id', $teamId)
            ->where('season', (int) $game->season);

        if (Schema::hasColumn((new EloRating)->getTable(), 'date') && $game->game_date) {
            $gameDate = Carbon::parse($game->game_date)->toDateString();

            return $query
                ->whereDate('date', '<', $gameDate)
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->value('elo_rating');
        }

        return $query
            ->where('week', '<', (int) ($game->week ?? 0))
            ->orderByDesc('week')
            ->orderByDesc('id')
            ->value('elo_rating');
    }

    protected function latestPriorSeasonElo(int $teamId, int $season): ?float
    {
        $query = EloRating::query()
            ->where('team_id', $teamId)
            ->where('season', '<', $season)
            ->orderByDesc('season');

        if (Schema::hasColumn((new EloRating)->getTable(), 'date')) {
            $query->orderByDesc('date');
        }

        return $query
            ->orderByDesc('week')
            ->orderByDesc('id')
            ->value('elo_rating');
    }

    protected function shouldUsePriorSeasonEloFallback(Model $game): bool
    {
        $throughWeek = (int) config('cfb.predictions.previous_season_elo_fallback_through_week', 4);

        if ($throughWeek < 0) {
            return true;
        }

        return (int) ($game->week ?? 0) <= $throughWeek;
    }

    protected function latestPriorSeasonMetric(string $teamMetricModel, int $teamId, int $season, ?Model $game = null): ?Model
    {
        $team = Team::query()->find($teamId);
        if (! $team || ! $this->seasonAffiliationResolver->isFbs($team, $season)) {
            return null;
        }

        return $teamMetricModel::query()
            ->where('team_id', $teamId)
            ->where('season', '<', $season)
            ->orderByDesc('season')
            ->get()
            ->first(function (Model $metric) use ($team) {
                return $this->seasonAffiliationResolver->isFbs($team, (int) $metric->season);
            });
    }
}
