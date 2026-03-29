<?php

namespace App\Models\NFL;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMetric extends Model
{
    protected $table = 'nfl_team_metrics';

    protected $fillable = [
        'team_id',
        'season',
        'wins',
        'losses',
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
        'predictive_rating',
        'home_rating',
        'away_rating',
        'home_advantage_rating',
        'future_strength_of_schedule',
        'season_strength_of_schedule',
        'strength_of_schedule_basic',
        'in_division_strength_of_schedule',
        'non_division_strength_of_schedule',
        'last_5_rating',
        'last_10_rating',
        'in_division_rating',
        'non_division_rating',
        'luck_rating',
        'consistency_rating',
        'vs_1_to_5_rating',
        'vs_6_to_10_rating',
        'vs_11_to_16_rating',
        'vs_17_to_22_rating',
        'vs_23_to_32_rating',
        'first_half_rating',
        'second_half_rating',
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
            'predictive_rating' => 'decimal:3',
            'home_rating' => 'decimal:3',
            'away_rating' => 'decimal:3',
            'home_advantage_rating' => 'decimal:3',
            'future_strength_of_schedule' => 'decimal:3',
            'season_strength_of_schedule' => 'decimal:3',
            'strength_of_schedule_basic' => 'decimal:3',
            'in_division_strength_of_schedule' => 'decimal:3',
            'non_division_strength_of_schedule' => 'decimal:3',
            'last_5_rating' => 'decimal:3',
            'last_10_rating' => 'decimal:3',
            'in_division_rating' => 'decimal:3',
            'non_division_rating' => 'decimal:3',
            'luck_rating' => 'decimal:3',
            'consistency_rating' => 'decimal:3',
            'vs_1_to_5_rating' => 'decimal:3',
            'vs_6_to_10_rating' => 'decimal:3',
            'vs_11_to_16_rating' => 'decimal:3',
            'vs_17_to_22_rating' => 'decimal:3',
            'vs_23_to_32_rating' => 'decimal:3',
            'first_half_rating' => 'decimal:3',
            'second_half_rating' => 'decimal:3',
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
