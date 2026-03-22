<?php

namespace App\Actions\Sports;

use App\Services\PlayerStats\NbaPlayerEpaCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class AbstractPredictionGenerator
{
    protected const SPORT_KEY = '';

    protected const TEAM_METRIC_MODEL = '';

    protected const PREDICTION_MODEL = '';

    /**
     * Get the sport identifier for config lookups
     */
    protected function getSport(): string
    {
        if (static::SPORT_KEY === '') {
            throw new \RuntimeException('SPORT_KEY must be defined on prediction action.');
        }

        return static::SPORT_KEY;
    }

    /**
     * Get the TeamMetric model class
     */
    protected function getTeamMetricModel(): string
    {
        if (static::TEAM_METRIC_MODEL === '') {
            throw new \RuntimeException('TEAM_METRIC_MODEL must be defined on prediction action.');
        }

        return static::TEAM_METRIC_MODEL;
    }

    /**
     * Get the Prediction model class
     */
    protected function getPredictionModel(): string
    {
        if (static::PREDICTION_MODEL === '') {
            throw new \RuntimeException('PREDICTION_MODEL must be defined on prediction action.');
        }

        return static::PREDICTION_MODEL;
    }

    /**
     * Calculate sport-specific predicted spread
     */
    abstract protected function calculatePredictedSpread(
        int $homeElo,
        int $awayElo,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float;

    /**
     * Calculate sport-specific predicted total
     */
    abstract protected function calculatePredictedTotal(
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float;

    /**
     * Execute prediction generation for a game
     */
    public function execute(Model $game): ?Model
    {
        $predictionData = $this->makePredictionData($game);
        if ($predictionData === null) {
            return null;
        }

        $predictionModel = $this->getPredictionModel();

        return $predictionModel::updateOrCreate(
            ['game_id' => $game->id],
            $predictionData
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function makePredictionData(Model $game): ?array
    {
        // Don't predict games that are already completed
        if ($game->status === 'STATUS_FINAL') {
            return null;
        }

        $homeTeam = $game->homeTeam;
        $awayTeam = $game->awayTeam;

        if (! $homeTeam || ! $awayTeam) {
            return null;
        }

        if (! $this->shouldGeneratePredictionForGame($game, $homeTeam, $awayTeam)) {
            return null;
        }

        // Get current Elo ratings
        $sport = $this->getSport();
        $defaultElo = config("{$sport}.elo.default") ?? config("{$sport}.elo.default_rating");
        $homeElo = $homeTeam->elo_rating ?? $defaultElo;
        $awayElo = $awayTeam->elo_rating ?? $defaultElo;

        // Get team metrics for the season
        [$homeMetrics, $awayMetrics] = $this->teamMetricsForGame($game, $homeTeam->id, $awayTeam->id);

        // Calculate predictions using sport-specific logic
        $predictedSpread = $this->calculatePredictedSpread($homeElo, $awayElo, $homeMetrics, $awayMetrics, $game);
        $predictedTotal = $this->calculatePredictedTotal($homeMetrics, $awayMetrics, $game);
        [$contextSpreadAdj, $contextTotalAdj] = $this->applyContextMetricAdjustments(
            $homeMetrics,
            $awayMetrics,
            $homeElo,
            $awayElo
        );
        $predictedSpread = round($predictedSpread + $contextSpreadAdj, 1);
        $predictedTotal = round($predictedTotal + $contextTotalAdj, 1);

        if (! $this->hasPersistedInjuryAdjustedRating($homeMetrics, $awayMetrics)) {
            [$predictedSpread, $predictedTotal] = $this->applyInjuryAdjustments(
                $game,
                $predictedSpread,
                $predictedTotal
            );
        }

        // Calculate win probability from spread
        $winProbability = $this->calculateWinProbability($predictedSpread);
        // Calculate confidence score based on win probability
        $confidenceScore = $this->calculateConfidence($winProbability);

        // Build prediction data
        return $this->buildPredictionData(
            $homeElo,
            $awayElo,
            $homeMetrics,
            $awayMetrics,
            $predictedSpread,
            $predictedTotal,
            $winProbability,
            $confidenceScore
        );
    }

    /**
     * Calculate win probability from spread using logistic function
     */
    protected function calculateWinProbability(float $spread): float
    {
        $sport = $this->getSport();
        $coefficient = config("{$sport}.prediction.spread_to_probability_coefficient") ?? 7.0;
        $probability = 1 / (1 + exp(-$spread / $coefficient));

        return round($probability, 3);
    }

    /**
     * Calculate confidence score from win probability.
     *
     * Maps the predicted winner's probability to a 50-100 scale:
     * 95% WP → 95 confidence, 55% WP → 55 confidence, 30% WP (away favored) → 70 confidence
     */
    protected function calculateConfidence(float $winProbability): float
    {
        return round(max($winProbability, 1 - $winProbability) * 100, 2);
    }

    /**
     * Build prediction data array (can be overridden for sport-specific fields)
     */
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
            'predicted_spread' => $predictedSpread,
            'predicted_total' => $predictedTotal,
            'win_probability' => $winProbability,
            'confidence_score' => $confidenceScore,
        ];
    }

    /**
     * @return array<string, float|int|null>
     */
    protected function efficiencyPredictionData(
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        float $defaultEfficiency
    ): array {
        return [
            'home_off_eff' => $homeMetrics?->offensive_efficiency ?? $defaultEfficiency,
            'home_def_eff' => $homeMetrics?->defensive_efficiency ?? $defaultEfficiency,
            'away_off_eff' => $awayMetrics?->offensive_efficiency ?? $defaultEfficiency,
            'away_def_eff' => $awayMetrics?->defensive_efficiency ?? $defaultEfficiency,
        ];
    }

    /**
     * @return array{0:?Model,1:?Model}
     */
    protected function teamMetricsForGame(Model $game, int $homeTeamId, int $awayTeamId): array
    {
        $teamMetricModel = $this->getTeamMetricModel();

        $metrics = $teamMetricModel::query()
            ->where('season', $game->season)
            ->whereIn('team_id', [$homeTeamId, $awayTeamId])
            ->get()
            ->keyBy('team_id');

        if ($this->shouldUsePriorSeasonMetricFallback()) {
            foreach ([$homeTeamId, $awayTeamId] as $teamId) {
                if ($metrics->has($teamId)) {
                    continue;
                }

                $fallbackMetric = $this->latestPriorSeasonMetric($teamMetricModel, $teamId, (int) $game->season, $game);

                if ($fallbackMetric) {
                    $metrics->put($teamId, $fallbackMetric);
                }
            }
        }

        return [
            $metrics->get($homeTeamId),
            $metrics->get($awayTeamId),
        ];
    }

    protected function shouldUsePriorSeasonMetricFallback(): bool
    {
        $sport = $this->getSport();

        $predictionConfig = config("{$sport}.prediction.use_previous_season_metrics_fallback");
        if (is_bool($predictionConfig)) {
            return $predictionConfig;
        }

        return (bool) config("{$sport}.predictions.use_previous_season_metrics_fallback", false);
    }

    protected function shouldGeneratePredictionForGame(Model $game, Model $homeTeam, Model $awayTeam): bool
    {
        return true;
    }

    protected function latestPriorSeasonMetric(string $teamMetricModel, int $teamId, int $season, ?Model $game = null): ?Model
    {
        return $teamMetricModel::query()
            ->where('team_id', $teamId)
            ->where('season', '<', $season)
            ->orderByDesc('season')
            ->first();
    }

    /**
     * @return array{0:float,1:float}
     */
    protected function applyInjuryAdjustments(Model $game, float $predictedSpread, float $predictedTotal): array
    {
        $sport = $this->getSport();

        // NBA applies its own richer injury logic inside its action.
        if ($sport === 'nba') {
            return [$predictedSpread, $predictedTotal];
        }

        $injuryTable = $this->injuryTableName($sport);
        if (! Schema::hasTable($injuryTable)) {
            return [$predictedSpread, $predictedTotal];
        }

        $homeCounts = $this->injuryCountsForTeam($injuryTable, (int) ($game->home_team_id ?? 0), $sport);
        $awayCounts = $this->injuryCountsForTeam($injuryTable, (int) ($game->away_team_id ?? 0), $sport);

        $outSpreadPenalty = $this->injuryPenaltyConfig($sport, 'injury_out_spread_penalty', $this->defaultOutSpreadPenalty($sport));
        $questionableSpreadPenalty = $this->injuryPenaltyConfig($sport, 'injury_questionable_spread_penalty', $this->defaultQuestionableSpreadPenalty($sport));
        $outTotalPenalty = $this->injuryPenaltyConfig($sport, 'injury_out_total_penalty', $this->defaultOutTotalPenalty($sport));
        $questionableTotalPenalty = $this->injuryPenaltyConfig($sport, 'injury_questionable_total_penalty', $this->defaultQuestionableTotalPenalty($sport));

        $homePenalty = ($homeCounts['out'] * $outSpreadPenalty) + ($homeCounts['questionable'] * $questionableSpreadPenalty);
        $awayPenalty = ($awayCounts['out'] * $outSpreadPenalty) + ($awayCounts['questionable'] * $questionableSpreadPenalty);

        $injurySpreadAdj = $awayPenalty - $homePenalty;
        $injuryTotalAdj = -(
            (($homeCounts['out'] + $awayCounts['out']) * $outTotalPenalty)
            + (($homeCounts['questionable'] + $awayCounts['questionable']) * $questionableTotalPenalty)
        );

        return [
            round($predictedSpread + $injurySpreadAdj, 1),
            round($predictedTotal + $injuryTotalAdj, 1),
        ];
    }

    /**
     * @return array{0:float,1:float}
     */
    protected function applyContextMetricAdjustments(
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        int $homeElo,
        int $awayElo
    ): array {
        $sport = $this->getSport();

        $homeRecent = (float) ($homeMetrics?->recent_form_rating ?? 0.0);
        $awayRecent = (float) ($awayMetrics?->recent_form_rating ?? 0.0);
        $recentDiff = $homeRecent - $awayRecent;

        $homeFatigue = max(0.0, (float) ($homeMetrics?->rest_travel_fatigue ?? 0.0));
        $awayFatigue = max(0.0, (float) ($awayMetrics?->rest_travel_fatigue ?? 0.0));
        $fatigueDiff = $awayFatigue - $homeFatigue;

        $homeInjuryAdjusted = (float) ($homeMetrics?->injury_adjusted_team_rating ?? $homeElo);
        $awayInjuryAdjusted = (float) ($awayMetrics?->injury_adjusted_team_rating ?? $awayElo);
        $baseEloDiff = $homeElo - $awayElo;
        $injuryDelta = ($homeInjuryAdjusted - $awayInjuryAdjusted) - $baseEloDiff;
        $injuryLoss = max(0.0, $homeElo - $homeInjuryAdjusted) + max(0.0, $awayElo - $awayInjuryAdjusted);

        $spreadAdj = ($recentDiff * $this->contextWeight($sport, 'recent_spread_weight'))
            + ($fatigueDiff * $this->contextWeight($sport, 'fatigue_spread_weight'))
            + ($injuryDelta * $this->contextWeight($sport, 'injury_spread_weight'));

        $totalAdj = (($homeRecent + $awayRecent) * $this->contextWeight($sport, 'recent_total_weight'))
            - (($homeFatigue + $awayFatigue) * $this->contextWeight($sport, 'fatigue_total_weight'))
            - ($injuryLoss * $this->contextWeight($sport, 'injury_total_weight'));

        return [round($spreadAdj, 2), round($totalAdj, 2)];
    }

    protected function hasPersistedInjuryAdjustedRating(?Model $homeMetrics, ?Model $awayMetrics): bool
    {
        return $homeMetrics?->injury_adjusted_team_rating !== null
            || $awayMetrics?->injury_adjusted_team_rating !== null;
    }

    protected function contextWeight(string $sport, string $key): float
    {
        $prediction = config("{$sport}.prediction.{$key}");
        if (is_numeric($prediction)) {
            return (float) $prediction;
        }

        $predictions = config("{$sport}.predictions.{$key}");
        if (is_numeric($predictions)) {
            return (float) $predictions;
        }

        return match ($key) {
            'recent_spread_weight' => match ($sport) {
                'nfl', 'cfb' => 0.06,
                'mlb' => 0.08,
                'wnba' => 0.10,
                default => 0.00,
            },
            'fatigue_spread_weight' => match ($sport) {
                'nfl', 'cfb' => 0.12,
                'mlb' => 0.10,
                'wnba' => 0.20,
                default => 0.00,
            },
            'injury_spread_weight' => match ($sport) {
                'nfl', 'cfb' => 0.025,
                'mlb' => 0.020,
                'wnba' => 0.030,
                default => 0.00,
            },
            'recent_total_weight' => match ($sport) {
                'nfl', 'cfb' => 0.10,
                'mlb' => 0.06,
                'wnba' => 0.12,
                default => 0.00,
            },
            'fatigue_total_weight' => match ($sport) {
                'nfl', 'cfb' => 0.18,
                'mlb' => 0.12,
                'wnba' => 0.25,
                default => 0.00,
            },
            'injury_total_weight' => match ($sport) {
                'nfl', 'cfb' => 0.012,
                'mlb' => 0.010,
                'wnba' => 0.015,
                default => 0.00,
            },
            default => 0.00,
        };
    }

    protected function injuryTableName(string $sport): string
    {
        return "{$sport}_player_injuries";
    }

    /**
     * @return array{out:float,questionable:float}
     */
    protected function injuryCountsForTeam(string $injuryTable, int $teamId, ?string $sport = null): array
    {
        $counts = ['out' => 0, 'questionable' => 0];
        if ($teamId <= 0) {
            return $counts;
        }

        $injuries = DB::table($injuryTable)
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->get(['player_id', 'status']);

        foreach ($injuries as $injury) {
            $bucket = $this->injuryStatusBucket((string) ($injury->status ?? ''));
            if ($bucket !== null) {
                $counts[$bucket] += $this->injuryImpactMultiplier($sport, (int) ($injury->player_id ?? 0));
            }
        }

        $counts['out'] = round((float) $counts['out'], 2);
        $counts['questionable'] = round((float) $counts['questionable'], 2);

        return $counts;
    }

    protected function injuryImpactMultiplier(?string $sport, int $playerId): float
    {
        if (! $this->supportsEpaWeightedInjuryImpact($sport) || $playerId <= 0) {
            return 1.0;
        }

        $sportKey = (string) $sport;
        $statTable = "{$sportKey}_player_stats";

        if (! Schema::hasTable($statTable)) {
            return 1.0;
        }

        $lookback = (int) (config("{$sportKey}.prediction.injury_epa_lookback_games") ?? 10);
        $lookback = max(3, min(30, $lookback));

        $rows = DB::table($statTable)
            ->where('player_id', $playerId)
            ->orderByDesc('game_id')
            ->limit($lookback)
            ->get([
                'points',
                'assists',
                'rebounds_total',
                'steals',
                'blocks',
                'turnovers',
                'field_goals_made',
                'field_goals_attempted',
                'free_throws_made',
                'free_throws_attempted',
            ]);

        if ($rows->isEmpty()) {
            return 1.0;
        }

        $calculator = app(NbaPlayerEpaCalculator::class);
        $profile = $this->epaProfileForSport($sportKey);
        $sum = 0.0;

        foreach ($rows as $row) {
            $sum += $calculator->estimateFromBoxScore(
                $row->points,
                $row->assists,
                $row->rebounds_total,
                $row->steals,
                $row->blocks,
                $row->turnovers,
                $row->field_goals_made,
                $row->field_goals_attempted,
                $row->free_throws_made,
                $row->free_throws_attempted,
                $profile
            );
        }

        $avgEpa = $sum / max(1, $rows->count());
        $baseline = (float) (config("{$sportKey}.prediction.injury_epa_baseline") ?? 12.0);
        $baseline = max(1.0, $baseline);

        $multiplier = $avgEpa / $baseline;
        $min = (float) (config("{$sportKey}.prediction.injury_epa_min_multiplier") ?? 0.5);
        $max = (float) (config("{$sportKey}.prediction.injury_epa_max_multiplier") ?? 2.0);
        $fallback = (float) (config("{$sportKey}.prediction.injury_epa_fallback_multiplier") ?? 1.0);

        if (! is_finite($multiplier) || $multiplier <= 0) {
            return max(0.1, $fallback);
        }

        return round(max($min, min($max, $multiplier)), 2);
    }

    protected function supportsEpaWeightedInjuryImpact(?string $sport): bool
    {
        if (! is_string($sport) || $sport === '') {
            return false;
        }

        $enabled = config("{$sport}.prediction.injury_epa_weighting_enabled");
        if (is_bool($enabled)) {
            return $enabled;
        }

        return in_array($sport, ['nba', 'wnba', 'cbb', 'wcbb'], true);
    }

    protected function epaProfileForSport(string $sport): string
    {
        $configured = config("{$sport}.prediction.injury_epa_profile");
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $sport === 'nba' ? NbaPlayerEpaCalculator::PROFILE_NBA : NbaPlayerEpaCalculator::PROFILE_CBB;
    }

    protected function injuryStatusBucket(string $status): ?string
    {
        $normalized = strtolower(trim($status));
        if ($normalized === '') {
            return null;
        }

        if (
            str_contains($normalized, 'out')
            || str_contains($normalized, 'doubtful')
            || str_contains($normalized, 'inactive')
            || str_contains($normalized, 'suspended')
            || str_contains($normalized, 'ir')
        ) {
            return 'out';
        }

        if (
            str_contains($normalized, 'questionable')
            || str_contains($normalized, 'game-time')
            || str_contains($normalized, 'gtd')
            || str_contains($normalized, 'probable')
            || str_contains($normalized, 'day-to-day')
        ) {
            return 'questionable';
        }

        return null;
    }

    protected function injuryPenaltyConfig(string $sport, string $key, float $default): float
    {
        $predictionConfig = config("{$sport}.prediction.{$key}");
        if (is_numeric($predictionConfig)) {
            return (float) $predictionConfig;
        }

        $predictionsConfig = config("{$sport}.predictions.{$key}");
        if (is_numeric($predictionsConfig)) {
            return (float) $predictionsConfig;
        }

        return $default;
    }

    protected function defaultOutSpreadPenalty(string $sport): float
    {
        return match ($sport) {
            'nfl', 'cfb' => 0.50,
            'mlb' => 0.30,
            default => 0.75,
        };
    }

    protected function defaultQuestionableSpreadPenalty(string $sport): float
    {
        return match ($sport) {
            'nfl', 'cfb' => 0.20,
            'mlb' => 0.10,
            default => 0.30,
        };
    }

    protected function defaultOutTotalPenalty(string $sport): float
    {
        return match ($sport) {
            'nfl', 'cfb' => 0.30,
            'mlb' => 0.15,
            default => 0.40,
        };
    }

    protected function defaultQuestionableTotalPenalty(string $sport): float
    {
        return match ($sport) {
            'nfl', 'cfb' => 0.10,
            'mlb' => 0.05,
            default => 0.15,
        };
    }
}
