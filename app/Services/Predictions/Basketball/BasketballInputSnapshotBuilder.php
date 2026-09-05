<?php

namespace App\Services\Predictions\Basketball;

use App\Services\Predictions\CanonicalTeamInputSnapshotBuilder;
use Illuminate\Database\Eloquent\Model;

abstract class BasketballInputSnapshotBuilder extends CanonicalTeamInputSnapshotBuilder
{
    /** @return array<string, mixed> */
    protected function metricInputs(Model $metric): array
    {
        return [
            'record_season' => (int) $metric->getAttribute('season'),
            'wins' => (int) $metric->getAttribute('wins'),
            'losses' => (int) $metric->getAttribute('losses'),
            'offensive_efficiency' => (float) $metric->getAttribute('offensive_efficiency'),
            'defensive_efficiency' => (float) $metric->getAttribute('defensive_efficiency'),
            'net_rating' => (float) $metric->getAttribute('net_rating'),
            'tempo' => (float) $metric->getAttribute('tempo'),
            'recent_form_rating' => (float) ($metric->getAttribute('recent_form_rating') ?? 0),
            'injury_adjusted_team_rating' => $this->nullableFloat($metric->getAttribute('injury_adjusted_team_rating')),
            'injury_total_adjustment' => $this->nullableFloat($metric->getAttribute('injury_total_adjustment')),
            'rest_travel_fatigue' => (float) ($metric->getAttribute('rest_travel_fatigue') ?? 0),
        ];
    }
}
