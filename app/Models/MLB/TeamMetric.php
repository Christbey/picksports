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
        'offensive_rating',
        'pitching_rating',
        'defensive_rating',
        'runs_per_game',
        'runs_allowed_per_game',
        'batting_average',
        'team_era',
        'strength_of_schedule',
        'calculation_date',
    ];

    protected function casts(): array
    {
        return [
            'offensive_rating' => 'float',
            'pitching_rating' => 'float',
            'defensive_rating' => 'float',
            'runs_per_game' => 'float',
            'runs_allowed_per_game' => 'float',
            'batting_average' => 'float',
            'team_era' => 'float',
            'strength_of_schedule' => 'float',
            'calculation_date' => 'date',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
