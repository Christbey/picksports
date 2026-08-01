<?php

namespace App\Models\CFB;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMetric extends Model
{
    protected $table = 'cfb_team_metrics';

    protected $fillable = [
        'team_id',
        'season',
        'wins',
        'losses',
        'fpi',
        'offensive_rating',
        'defensive_rating',
        'net_rating',
        'points_per_game',
        'points_allowed_per_game',
        'yards_per_game',
        'yards_allowed_per_game',
        'passing_yards_per_game',
        'rushing_yards_per_game',
        'turnover_differential',
        'strength_of_schedule',
        'recent_form_rating',
        'injury_adjusted_team_rating',
        'injury_total_adjustment',
        'rest_travel_fatigue',
        'cfbd_wepa_offense',
        'cfbd_wepa_defense',
        'cfbd_wepa_net',
        'cfbd_wepa_payload',
        'cfp_rating',
        'power_rating',
        'resume_rating',
        'rating_consensus',
        'rating_consensus_sources',
        'offensive_success_rate',
        'defensive_success_rate',
        'net_success_rate',
        'offensive_explosiveness',
        'defensive_explosiveness',
        'net_explosiveness',
        'offensive_havoc_rate',
        'defensive_havoc_rate',
        'net_havoc_rate',
        'offensive_line_yards',
        'offensive_stuff_rate',
        'offensive_sack_rate',
        'offensive_line_rating',
        'qb_environment_rating',
        'defensive_front_rating',
        'cfbd_advanced_payload',
        'offensive_true_epa_per_play',
        'defensive_true_epa_per_play',
        'net_true_epa_per_play',
        'calculation_date',
    ];

    protected function casts(): array
    {
        return [
            'offensive_rating' => 'decimal:1',
            'defensive_rating' => 'decimal:1',
            'net_rating' => 'decimal:1',
            'wins' => 'integer',
            'losses' => 'integer',
            'fpi' => 'decimal:2',
            'points_per_game' => 'decimal:1',
            'points_allowed_per_game' => 'decimal:1',
            'yards_per_game' => 'decimal:1',
            'yards_allowed_per_game' => 'decimal:1',
            'passing_yards_per_game' => 'decimal:1',
            'rushing_yards_per_game' => 'decimal:1',
            'turnover_differential' => 'decimal:1',
            'strength_of_schedule' => 'decimal:3',
            'recent_form_rating' => 'decimal:3',
            'injury_adjusted_team_rating' => 'decimal:3',
            'injury_total_adjustment' => 'decimal:3',
            'rest_travel_fatigue' => 'decimal:3',
            'cfbd_wepa_offense' => 'decimal:4',
            'cfbd_wepa_defense' => 'decimal:4',
            'cfbd_wepa_net' => 'decimal:4',
            'cfbd_wepa_payload' => 'array',
            'cfp_rating' => 'decimal:3',
            'power_rating' => 'decimal:3',
            'resume_rating' => 'decimal:3',
            'rating_consensus' => 'decimal:3',
            'rating_consensus_sources' => 'array',
            'offensive_success_rate' => 'decimal:4',
            'defensive_success_rate' => 'decimal:4',
            'net_success_rate' => 'decimal:4',
            'offensive_explosiveness' => 'decimal:4',
            'defensive_explosiveness' => 'decimal:4',
            'net_explosiveness' => 'decimal:4',
            'offensive_havoc_rate' => 'decimal:4',
            'defensive_havoc_rate' => 'decimal:4',
            'net_havoc_rate' => 'decimal:4',
            'offensive_line_yards' => 'decimal:4',
            'offensive_stuff_rate' => 'decimal:4',
            'offensive_sack_rate' => 'decimal:4',
            'offensive_line_rating' => 'decimal:4',
            'qb_environment_rating' => 'decimal:4',
            'defensive_front_rating' => 'decimal:4',
            'cfbd_advanced_payload' => 'array',
            'offensive_true_epa_per_play' => 'decimal:3',
            'defensive_true_epa_per_play' => 'decimal:3',
            'net_true_epa_per_play' => 'decimal:3',
            'calculation_date' => 'date',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
