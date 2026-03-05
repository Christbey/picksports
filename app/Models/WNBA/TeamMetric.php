<?php

namespace App\Models\WNBA;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMetric extends Model
{
    /** @use HasFactory<\Database\Factories\WnbaTeamMetricFactory> */
    use HasFactory;

    protected $table = 'wnba_team_metrics';

    protected $fillable = [
        'team_id',
        'season',
        'wins',
        'losses',
        'offensive_efficiency',
        'defensive_efficiency',
        'net_rating',
        'tempo',
        'strength_of_schedule',
        'recent_form_rating',
        'injury_adjusted_team_rating',
        'rest_travel_fatigue',
        'calculation_date',
    ];

    protected function casts(): array
    {
        return [
            'offensive_efficiency' => 'decimal:1',
            'defensive_efficiency' => 'decimal:1',
            'net_rating' => 'decimal:1',
            'wins' => 'integer',
            'losses' => 'integer',
            'tempo' => 'decimal:1',
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
