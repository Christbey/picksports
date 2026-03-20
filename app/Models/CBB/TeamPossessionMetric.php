<?php

namespace App\Models\CBB;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamPossessionMetric extends Model
{
    use HasFactory;

    protected $table = 'cbb_team_possession_metrics';

    protected $fillable = [
        'team_id',
        'season',
        'as_of_date',
        'games_sampled',
        'offensive_possessions',
        'defensive_possessions',
        'rolling_games_sampled',
        'rolling_offensive_possessions',
        'rolling_defensive_possessions',
        'late_game_offensive_possessions',
        'late_game_defensive_possessions',
        'offensive_points_per_possession',
        'defensive_points_per_possession_allowed',
        'net_points_per_possession',
        'rolling_offensive_points_per_possession',
        'rolling_defensive_points_per_possession_allowed',
        'rolling_net_points_per_possession',
        'late_game_offensive_points_per_possession',
        'late_game_defensive_points_per_possession_allowed',
        'turnover_rate',
        'forced_turnover_rate',
        'free_throw_trip_rate',
        'free_throw_rate_allowed',
        'possessions_per_game',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
            'games_sampled' => 'integer',
            'offensive_possessions' => 'integer',
            'defensive_possessions' => 'integer',
            'rolling_games_sampled' => 'integer',
            'rolling_offensive_possessions' => 'integer',
            'rolling_defensive_possessions' => 'integer',
            'late_game_offensive_possessions' => 'integer',
            'late_game_defensive_possessions' => 'integer',
            'offensive_points_per_possession' => 'decimal:3',
            'defensive_points_per_possession_allowed' => 'decimal:3',
            'net_points_per_possession' => 'decimal:3',
            'rolling_offensive_points_per_possession' => 'decimal:3',
            'rolling_defensive_points_per_possession_allowed' => 'decimal:3',
            'rolling_net_points_per_possession' => 'decimal:3',
            'late_game_offensive_points_per_possession' => 'decimal:3',
            'late_game_defensive_points_per_possession_allowed' => 'decimal:3',
            'turnover_rate' => 'decimal:3',
            'forced_turnover_rate' => 'decimal:3',
            'free_throw_trip_rate' => 'decimal:3',
            'free_throw_rate_allowed' => 'decimal:3',
            'possessions_per_game' => 'decimal:3',
            'metadata' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
