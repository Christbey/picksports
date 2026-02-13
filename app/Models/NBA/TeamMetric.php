<?php

namespace App\Models\NBA;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMetric extends Model
{
    /** @use HasFactory<\Database\Factories\CbbTeamMetricFactory> */
    use HasFactory;

    protected $table = 'nba_team_metrics';

    protected $fillable = [
        'team_id',
        'season',
        'offensive_efficiency',
        'defensive_efficiency',
        'net_rating',
        'tempo',
        'strength_of_schedule',
        'calculation_date',
    ];

    protected function casts(): array
    {
        return [
            'offensive_efficiency' => 'decimal:1',
            'defensive_efficiency' => 'decimal:1',
            'net_rating' => 'decimal:1',
            'tempo' => 'decimal:1',
            'strength_of_schedule' => 'decimal:3',
            'calculation_date' => 'date',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
