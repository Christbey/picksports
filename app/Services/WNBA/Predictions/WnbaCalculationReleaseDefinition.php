<?php

namespace App\Services\WNBA\Predictions;

use App\Contracts\Predictions\BasketballReleaseDefinition;

class WnbaCalculationReleaseDefinition implements BasketballReleaseDefinition
{
    public const CALCULATOR_NAME = 'wnba-pregame-rules';

    public const INPUT_SCHEMA_VERSION = 'wnba-pregame-v1';

    public const SEMANTIC_VERSION = '1.0.0';

    public function sport(): string
    {
        return 'wnba';
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
                'default' => (float) config('wnba.elo.default', 1500),
                'home_court_advantage' => (float) config('wnba.elo.home_court_advantage', 80),
                'points_per_spread_point' => (float) config('wnba.prediction.elo_to_spread_divisor', 28),
            ],
            'spread' => [
                'metric_weight' => (float) config('wnba.prediction.metric_spread_weight', 0.25),
                'minimum_metric_games' => (int) config('wnba.prediction.metric_spread_min_games', 10),
                'output_regression_weight' => (float) config('wnba.prediction.spread_output_regression_weight', 0.08),
                'probability_coefficient' => (float) config('wnba.prediction.spread_to_probability_coefficient', 6.5),
            ],
            'total' => [
                'default_efficiency' => (float) config('wnba.prediction.default_efficiency', 98),
                'average_pace' => (float) config('wnba.prediction.average_pace', 88),
                'average_total' => (float) config('wnba.prediction.average_total', 166.5),
                'tempo_regression_weight' => (float) config('wnba.prediction.total_tempo_regression_weight', 0.5),
                'output_regression_weight' => (float) config('wnba.prediction.total_output_regression_weight', 0.25),
            ],
            'context' => [
                'recent_spread_weight' => (float) config('wnba.prediction.recent_spread_weight', 0.10),
                'fatigue_spread_weight' => (float) config('wnba.prediction.fatigue_spread_weight', 0.20),
                'injury_rating_spread_weight' => (float) config('wnba.prediction.injury_spread_weight', 0.03),
                'recent_total_weight' => (float) config('wnba.prediction.recent_total_weight', 0.08),
                'fatigue_total_weight' => (float) config('wnba.prediction.fatigue_total_weight', 0.10),
                'injury_rating_total_weight' => (float) config('wnba.prediction.injury_total_weight', 0.01),
            ],
            'injuries' => [
                'out_spread_penalty' => (float) config('wnba.prediction.injury_out_spread_penalty', 0.75),
                'questionable_spread_penalty' => (float) config('wnba.prediction.injury_questionable_spread_penalty', 0.30),
                'out_total_penalty' => (float) config('wnba.prediction.injury_out_total_penalty', 0.40),
                'questionable_total_penalty' => (float) config('wnba.prediction.injury_questionable_total_penalty', 0.15),
            ],
            'inputs' => [
                'use_previous_season_metrics_fallback' => (bool) config(
                    'wnba.prediction.use_previous_season_metrics_fallback',
                    true,
                ),
            ],
        ];
    }
}
