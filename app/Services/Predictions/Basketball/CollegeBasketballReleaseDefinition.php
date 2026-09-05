<?php

namespace App\Services\Predictions\Basketball;

use App\Contracts\Predictions\BasketballReleaseDefinition;

abstract class CollegeBasketballReleaseDefinition implements BasketballReleaseDefinition
{
    /** @return array<string, mixed> */
    public function configuration(): array
    {
        $sport = $this->sport();

        return [
            'elo' => [
                'default' => (float) config("{$sport}.elo.default", 1500),
                'home_court_advantage' => (float) config("{$sport}.elo.home_court_advantage", 35),
                'points_per_spread_point' => (float) config("{$sport}.prediction.elo_to_spread_divisor", 30),
            ],
            'spread' => [
                'metric_weight' => (float) config("{$sport}.prediction.canonical.metric_spread_weight", 0.55),
                'minimum_metric_games' => (int) config("{$sport}.prediction.canonical.metric_spread_min_games", 8),
                'output_regression_weight' => (float) config("{$sport}.prediction.canonical.spread_output_regression_weight", 0.12),
                'probability_coefficient' => (float) config(
                    "{$sport}.prediction.canonical.spread_to_probability_coefficient",
                    config("{$sport}.prediction.spread_to_probability_coefficient", 5.5),
                ),
            ],
            'total' => [
                'default_efficiency' => (float) config("{$sport}.prediction.default_efficiency", 100),
                'average_pace' => (float) config("{$sport}.prediction.average_pace", 70),
                'average_total' => (float) config("{$sport}.prediction.canonical.average_total", $this->averageTotal()),
                'tempo_regression_weight' => (float) config("{$sport}.prediction.canonical.total_tempo_regression_weight", 0.30),
                'output_regression_weight' => (float) config("{$sport}.prediction.canonical.total_output_regression_weight", 0.18),
            ],
            'context' => [
                'recent_spread_weight' => (float) config("{$sport}.prediction.canonical.recent_spread_weight", 0.10),
                'fatigue_spread_weight' => (float) config("{$sport}.prediction.canonical.fatigue_spread_weight", 0.18),
                'injury_rating_spread_weight' => (float) config("{$sport}.prediction.canonical.injury_rating_spread_weight", 0.025),
                'recent_total_weight' => (float) config("{$sport}.prediction.canonical.recent_total_weight", 0.10),
                'fatigue_total_weight' => (float) config("{$sport}.prediction.canonical.fatigue_total_weight", 0.18),
                'injury_rating_total_weight' => (float) config("{$sport}.prediction.canonical.injury_rating_total_weight", 0.012),
            ],
            'injuries' => [
                'out_spread_penalty' => (float) config("{$sport}.prediction.injury_out_spread_penalty", 0.75),
                'questionable_spread_penalty' => (float) config("{$sport}.prediction.injury_questionable_spread_penalty", 0.30),
                'out_total_penalty' => (float) config("{$sport}.prediction.injury_out_total_penalty", 0.40),
                'questionable_total_penalty' => (float) config("{$sport}.prediction.injury_questionable_total_penalty", 0.15),
            ],
            'inputs' => [
                'use_previous_season_metrics_fallback' => (bool) config(
                    "{$sport}.prediction.canonical.use_previous_season_metrics_fallback",
                    true,
                ),
            ],
        ];
    }

    abstract protected function averageTotal(): float;
}
