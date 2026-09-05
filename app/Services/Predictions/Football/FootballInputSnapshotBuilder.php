<?php

namespace App\Services\Predictions\Football;

use App\Services\Predictions\CanonicalTeamInputSnapshotBuilder;
use Illuminate\Database\Eloquent\Model;

abstract class FootballInputSnapshotBuilder extends CanonicalTeamInputSnapshotBuilder
{
    /** @return array<string, mixed> */
    protected function metricInputs(Model $metric): array
    {
        return [
            'record_season' => (int) $metric->getAttribute('season'),
            'wins' => (int) $metric->getAttribute('wins'),
            'losses' => (int) $metric->getAttribute('losses'),
            'offensive_rating' => (float) ($metric->getAttribute('offensive_rating') ?? 0),
            'defensive_rating' => (float) ($metric->getAttribute('defensive_rating') ?? 0),
            'net_rating' => (float) ($metric->getAttribute('net_rating') ?? 0),
            'points_per_game' => (float) ($metric->getAttribute('points_per_game') ?? 0),
            'points_allowed_per_game' => (float) ($metric->getAttribute('points_allowed_per_game') ?? 0),
            'turnover_differential' => (float) ($metric->getAttribute('turnover_differential') ?? 0),
            'recent_form_rating' => (float) ($metric->getAttribute('recent_form_rating') ?? 0),
            'injury_adjusted_team_rating' => $this->nullableFloat($metric->getAttribute('injury_adjusted_team_rating')),
            'injury_total_adjustment' => $this->nullableFloat($metric->getAttribute('injury_total_adjustment')),
            'rest_travel_fatigue' => (float) ($metric->getAttribute('rest_travel_fatigue') ?? 0),
            'power_rating' => $this->nullableFloat($metric->getAttribute('power_rating')),
            'fpi' => $this->nullableFloat($metric->getAttribute('fpi')),
            'predictive_rating' => $this->nullableFloat($metric->getAttribute('predictive_rating')),
        ];
    }
}
