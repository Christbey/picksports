<?php

namespace App\Services\MLB\Predictions;

use App\Contracts\Predictions\CanonicalReleaseDefinition;

class MlbCalculationReleaseDefinition implements CanonicalReleaseDefinition
{
    public const CALCULATOR_NAME = 'mlb-pregame-rules';

    public const INPUT_SCHEMA_VERSION = 'mlb-pregame-v1';

    public const SEMANTIC_VERSION = '1.0.0';

    public function sport(): string
    {
        return 'mlb';
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

    /** @return array<string,mixed> */
    public function configuration(): array
    {
        return [
            'elo' => [
                'default_team' => (float) config('mlb.elo.default', 1500),
                'default_pitcher' => (float) config('mlb.prediction.canonical.default_pitcher_elo', 1500),
                'home_field_advantage' => (float) config('mlb.elo.home_field_advantage', 24),
                'team_weight' => (float) config('mlb.prediction.canonical.team_elo_weight', 0.70),
                'pitcher_weight' => (float) config('mlb.prediction.canonical.pitcher_elo_weight', 0.30),
                'points_per_run' => (float) config('mlb.prediction.canonical.elo_points_per_run', 35),
            ],
            'spread' => [
                'metric_weight' => (float) config('mlb.prediction.canonical.metric_weight', 0.45),
                'minimum_metric_games' => (int) config('mlb.prediction.canonical.minimum_metric_games', 20),
                'output_regression_weight' => (float) config('mlb.prediction.canonical.spread_output_regression_weight', 0.10),
                'probability_coefficient' => (float) config('mlb.prediction.canonical.run_margin_probability_coefficient', 1.45),
            ],
            'total' => [
                'default_team_runs' => (float) config('mlb.prediction.canonical.default_team_runs', 4.4),
                'average_total' => (float) config('mlb.prediction.canonical.average_total', 8.8),
                'output_regression_weight' => (float) config('mlb.prediction.canonical.total_output_regression_weight', 0.20),
            ],
            'context' => [
                'recent_spread_weight' => 0.10,
                'fatigue_spread_weight' => 0.12,
                'injury_rating_spread_weight' => 0.018,
                'recent_total_weight' => 0.06,
                'fatigue_total_weight' => 0.10,
                'temperature_total_per_degree' => 0.012,
                'wind_total_per_mph' => 0.025,
                'weather_temperature_baseline' => 72.0,
            ],
            'injuries' => [
                'out_spread_penalty' => 0.12,
                'questionable_spread_penalty' => 0.05,
                'out_total_penalty' => 0.06,
                'questionable_total_penalty' => 0.02,
            ],
            'parks' => (array) config('mlb.prediction.park_factors', []),
            'inputs' => ['use_previous_season_metrics_fallback' => true],
        ];
    }
}
