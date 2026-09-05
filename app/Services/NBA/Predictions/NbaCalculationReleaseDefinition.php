<?php

namespace App\Services\NBA\Predictions;

use App\Contracts\Predictions\BasketballReleaseDefinition;

class NbaCalculationReleaseDefinition implements BasketballReleaseDefinition
{
    public const CALCULATOR_NAME = 'nba-pregame-rules';

    public const INPUT_SCHEMA_VERSION = 'nba-pregame-v1';

    public const SEMANTIC_VERSION = '1.0.0';

    public function sport(): string
    {
        return 'nba';
    }

    public function calculatorName(): string
    {
        return self::CALCULATOR_NAME;
    }

    public function inputSchemaVersion(): string
    {
        return self::INPUT_SCHEMA_VERSION;
    }

    public function semanticVersion(): string
    {
        return self::SEMANTIC_VERSION;
    }

    /** @return array<string, mixed> */
    public function configuration(): array
    {
        return [
            'elo' => [
                'default' => (float) config('nba.elo.default', 1500),
                'home_court_advantage' => (float) config('nba.elo.home_court_advantage', 100),
                'points_per_spread_point' => (float) config('nba.prediction.elo_to_spread_divisor', 26),
            ],
            'spread' => [
                'metric_weight' => (float) config('nba.prediction.canonical.metric_spread_weight', 0.45),
                'minimum_metric_games' => (int) config('nba.prediction.canonical.metric_spread_min_games', 10),
                'output_regression_weight' => (float) config('nba.prediction.canonical.spread_output_regression_weight', 0.08),
                'probability_coefficient' => (float) config('nba.prediction.canonical.spread_to_probability_coefficient', 6.5),
            ],
            'total' => [
                'default_efficiency' => (float) config('nba.prediction.default_efficiency', 110),
                'average_pace' => (float) config('nba.prediction.average_pace', 100),
                'average_total' => (float) config('nba.prediction.canonical.average_total', 228.5),
                'tempo_regression_weight' => (float) config('nba.prediction.canonical.total_tempo_regression_weight', 0.35),
                'output_regression_weight' => (float) config('nba.prediction.canonical.total_output_regression_weight', 0.15),
            ],
            'context' => [
                'recent_spread_weight' => (float) config('nba.prediction.recent_spread_weight', 0.08),
                'fatigue_spread_weight' => (float) config('nba.prediction.fatigue_spread_weight', 0.18),
                'injury_rating_spread_weight' => (float) config('nba.prediction.injury_spread_weight', 0.028),
                'recent_total_weight' => (float) config('nba.prediction.recent_total_weight', 0.12),
                'fatigue_total_weight' => (float) config('nba.prediction.fatigue_total_weight', 0.20),
                'injury_rating_total_weight' => (float) config('nba.prediction.injury_total_weight', 0.015),
            ],
            'injuries' => [
                'out_spread_penalty' => (float) config('nba.prediction.injury_out_spread_penalty', 0.75),
                'questionable_spread_penalty' => (float) config('nba.prediction.injury_questionable_spread_penalty', 0.30),
                'out_total_penalty' => (float) config('nba.prediction.injury_out_total_penalty', 0.40),
                'questionable_total_penalty' => (float) config('nba.prediction.injury_questionable_total_penalty', 0.15),
            ],
            'inputs' => [
                'use_previous_season_metrics_fallback' => (bool) config(
                    'nba.prediction.use_previous_season_metrics_fallback',
                    true,
                ),
            ],
        ];
    }
}
