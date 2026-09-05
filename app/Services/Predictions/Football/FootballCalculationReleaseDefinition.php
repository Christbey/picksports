<?php

namespace App\Services\Predictions\Football;

use App\Contracts\Predictions\FootballReleaseDefinition;

abstract class FootballCalculationReleaseDefinition implements FootballReleaseDefinition
{
    /** @return array<string, mixed> */
    public function configuration(): array
    {
        $sport = $this->sport();

        return [
            'elo' => [
                'default' => (float) config("{$sport}.elo.default", 1500),
                'home_field_advantage' => (float) config("{$sport}.elo.home_field_advantage", $this->homeFieldAdvantage()),
                'points_per_spread_point' => (float) config("{$sport}.predictions.canonical.elo_points_per_spread_point", 25),
            ],
            'spread' => [
                'metric_weight' => (float) config("{$sport}.predictions.canonical.metric_weight", 0.50),
                'minimum_metric_games' => (int) config("{$sport}.predictions.canonical.minimum_metric_games", 6),
                'power_rating_weight' => (float) config("{$sport}.predictions.canonical.power_rating_weight", 0.15),
                'output_regression_weight' => (float) config("{$sport}.predictions.canonical.spread_output_regression_weight", 0.10),
                'probability_coefficient' => (float) config("{$sport}.predictions.canonical.spread_to_probability_coefficient", 6.0),
            ],
            'total' => [
                'default_team_points' => (float) config("{$sport}.predictions.canonical.default_team_points", $this->defaultTeamPoints()),
                'average_total' => (float) config("{$sport}.predictions.canonical.average_total", $this->averageTotal()),
                'output_regression_weight' => (float) config("{$sport}.predictions.canonical.total_output_regression_weight", 0.20),
            ],
            'context' => [
                'recent_spread_weight' => (float) config("{$sport}.predictions.canonical.recent_spread_weight", 0.10),
                'turnover_spread_weight' => (float) config("{$sport}.predictions.canonical.turnover_spread_weight", 0.20),
                'fatigue_spread_weight' => (float) config("{$sport}.predictions.canonical.fatigue_spread_weight", 0.15),
                'injury_rating_spread_weight' => (float) config("{$sport}.predictions.canonical.injury_rating_spread_weight", 0.025),
                'recent_total_weight' => (float) config("{$sport}.predictions.canonical.recent_total_weight", 0.08),
                'fatigue_total_weight' => (float) config("{$sport}.predictions.canonical.fatigue_total_weight", 0.15),
            ],
            'injuries' => [
                'out_spread_penalty' => (float) config("{$sport}.predictions.canonical.injury_out_spread_penalty", 0.60),
                'questionable_spread_penalty' => (float) config("{$sport}.predictions.canonical.injury_questionable_spread_penalty", 0.20),
                'out_total_penalty' => (float) config("{$sport}.predictions.canonical.injury_out_total_penalty", 0.25),
                'questionable_total_penalty' => (float) config("{$sport}.predictions.canonical.injury_questionable_total_penalty", 0.10),
            ],
            'inputs' => [
                'use_previous_season_metrics_fallback' => true,
            ],
        ];
    }

    abstract protected function homeFieldAdvantage(): float;

    abstract protected function defaultTeamPoints(): float;

    abstract protected function averageTotal(): float;
}
