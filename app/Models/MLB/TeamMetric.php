<?php

namespace App\Models\MLB;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMetric extends Model
{
    protected $table = 'mlb_team_metrics';

    protected $fillable = [
        'team_id',
        'season',
        'wins',
        'losses',
        'offensive_rating',
        'pitching_rating',
        'defensive_rating',
        'runs_per_game',
        'runs_allowed_per_game',
        'batting_average',
        'team_era',
        'strength_of_schedule',
        'recent_form_rating',
        'injury_adjusted_team_rating',
        'rest_travel_fatigue',
        'calculation_date',
    ];

    protected function casts(): array
    {
        return [
            'offensive_rating' => 'float',
            'pitching_rating' => 'float',
            'defensive_rating' => 'float',
            'wins' => 'integer',
            'losses' => 'integer',
            'runs_per_game' => 'float',
            'runs_allowed_per_game' => 'float',
            'batting_average' => 'float',
            'team_era' => 'float',
            'strength_of_schedule' => 'decimal:3',
            'recent_form_rating' => 'decimal:3',
            'injury_adjusted_team_rating' => 'decimal:3',
            'rest_travel_fatigue' => 'decimal:3',
            'calculation_date' => 'date',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
