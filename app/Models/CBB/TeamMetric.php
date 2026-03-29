<?php

namespace App\Models\CBB;

use Database\Factories\CbbTeamMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMetric extends Model
{
    /** @use HasFactory<CbbTeamMetricFactory> */
    use HasFactory;

    protected $table = 'cbb_team_metrics';

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
        'injury_total_adjustment',
        'rest_travel_fatigue',
        'calculation_date',
        // Minimum games tracking
        'games_played',
        'meets_minimum',
        // Adjusted metrics
        'adj_offensive_efficiency',
        'adj_defensive_efficiency',
        'adj_net_rating',
        'adj_tempo',
        // Rolling window metrics
        'rolling_offensive_efficiency',
        'rolling_defensive_efficiency',
        'rolling_net_rating',
        'rolling_tempo',
        'rolling_games_count',
        // Home/Away splits
        'home_offensive_efficiency',
        'home_defensive_efficiency',
        'away_offensive_efficiency',
        'away_defensive_efficiency',
        'home_games',
        'away_games',
        // Possession coefficient tracking
        'possession_coefficient',
        'iteration_count',
        'offensive_true_epa_per_play',
        'defensive_true_epa_per_play',
        'net_true_epa_per_play',
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
            'injury_total_adjustment' => 'decimal:3',
            'rest_travel_fatigue' => 'decimal:3',
            'calculation_date' => 'date',
            // Minimum games tracking
            'games_played' => 'integer',
            'meets_minimum' => 'boolean',
            // Adjusted metrics
            'adj_offensive_efficiency' => 'decimal:1',
            'adj_defensive_efficiency' => 'decimal:1',
            'adj_net_rating' => 'decimal:1',
            'adj_tempo' => 'decimal:1',
            // Rolling window metrics
            'rolling_offensive_efficiency' => 'decimal:1',
            'rolling_defensive_efficiency' => 'decimal:1',
            'rolling_net_rating' => 'decimal:1',
            'rolling_tempo' => 'decimal:1',
            'rolling_games_count' => 'integer',
            // Home/Away splits
            'home_offensive_efficiency' => 'decimal:1',
            'home_defensive_efficiency' => 'decimal:1',
            'away_offensive_efficiency' => 'decimal:1',
            'away_defensive_efficiency' => 'decimal:1',
            'home_games' => 'integer',
            'away_games' => 'integer',
            // Possession coefficient tracking
            'possession_coefficient' => 'decimal:3',
            'iteration_count' => 'integer',
            'offensive_true_epa_per_play' => 'decimal:3',
            'defensive_true_epa_per_play' => 'decimal:3',
            'net_true_epa_per_play' => 'decimal:3',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
