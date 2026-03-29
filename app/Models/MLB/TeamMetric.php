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
        'season_type',
        'wins',
        'losses',
        'offensive_rating',
        'pitching_rating',
        'defensive_rating',
        'runs_per_game',
        'runs_allowed_per_game',
        'run_differential_per_game',
        'home_runs_per_game',
        'batting_average',
        'on_base_percentage',
        'slugging_percentage',
        'ops',
        'team_era',
        'strikeouts_pitched_per_game',
        'whip',
        'strength_of_schedule',
        'recent_form_rating',
        'injury_adjusted_team_rating',
        'injury_total_adjustment',
        'rest_travel_fatigue',
        'calculation_date',
    ];

    protected function casts(): array
    {
        return [
            'offensive_rating' => 'float',
            'pitching_rating' => 'float',
            'defensive_rating' => 'float',
            'season_type' => 'string',
            'wins' => 'integer',
            'losses' => 'integer',
            'runs_per_game' => 'float',
            'runs_allowed_per_game' => 'float',
            'run_differential_per_game' => 'float',
            'home_runs_per_game' => 'float',
            'batting_average' => 'float',
            'on_base_percentage' => 'float',
            'slugging_percentage' => 'float',
            'ops' => 'float',
            'team_era' => 'float',
            'strikeouts_pitched_per_game' => 'float',
            'whip' => 'float',
            'strength_of_schedule' => 'decimal:3',
            'recent_form_rating' => 'decimal:3',
            'injury_adjusted_team_rating' => 'decimal:3',
            'injury_total_adjustment' => 'decimal:3',
            'rest_travel_fatigue' => 'decimal:3',
            'calculation_date' => 'date',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
