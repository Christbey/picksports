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
        'rest_travel_fatigue',
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
            'rest_travel_fatigue' => 'decimal:3',
            'calculation_date' => 'date',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
