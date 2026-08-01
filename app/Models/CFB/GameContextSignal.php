<?php

namespace App\Models\CFB;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameContextSignal extends Model
{
    protected $table = 'cfb_game_context_signals';

    protected $fillable = [
        'game_id',
        'home_team_id',
        'away_team_id',
        'season',
        'week',
        'player_availability_spread_adjustment',
        'player_availability_total_adjustment',
        'home_player_availability_score',
        'away_player_availability_score',
        'home_qb_availability_score',
        'away_qb_availability_score',
        'player_availability_payload',
        'weather_spread_adjustment',
        'weather_total_adjustment',
        'temperature_f',
        'wind_speed_mph',
        'wind_gust_mph',
        'precipitation_inches',
        'weather_condition',
        'weather_payload',
        'rating_consensus_spread_adjustment',
        'home_rating_consensus',
        'away_rating_consensus',
        'rating_consensus_payload',
        'explosiveness_spread_adjustment',
        'explosiveness_total_adjustment',
        'home_explosiveness_score',
        'away_explosiveness_score',
        'explosiveness_payload',
        'line_qb_spread_adjustment',
        'home_line_qb_score',
        'away_line_qb_score',
        'line_qb_payload',
        'market_movement_spread_adjustment',
        'market_confidence_penalty',
        'opening_home_spread',
        'current_home_spread',
        'closing_home_spread',
        'consensus_home_spread',
        'market_movement_payload',
        'schedule_context_spread_adjustment',
        'schedule_context_total_adjustment',
        'schedule_confidence_penalty',
        'home_rest_days',
        'away_rest_days',
        'schedule_context_payload',
        'scheme_spread_adjustment',
        'scheme_total_adjustment',
        'scheme_confidence_penalty',
        'home_scheme_change_score',
        'away_scheme_change_score',
        'scheme_payload',
        'special_teams_spread_adjustment',
        'special_teams_total_adjustment',
        'home_special_teams_score',
        'away_special_teams_score',
        'special_teams_payload',
        'signal_payload',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'season' => 'integer',
            'week' => 'integer',
            'player_availability_spread_adjustment' => 'decimal:3',
            'player_availability_total_adjustment' => 'decimal:3',
            'home_player_availability_score' => 'decimal:3',
            'away_player_availability_score' => 'decimal:3',
            'home_qb_availability_score' => 'decimal:3',
            'away_qb_availability_score' => 'decimal:3',
            'player_availability_payload' => 'array',
            'weather_spread_adjustment' => 'decimal:3',
            'weather_total_adjustment' => 'decimal:3',
            'temperature_f' => 'decimal:2',
            'wind_speed_mph' => 'decimal:2',
            'wind_gust_mph' => 'decimal:2',
            'precipitation_inches' => 'decimal:3',
            'weather_payload' => 'array',
            'rating_consensus_spread_adjustment' => 'decimal:3',
            'home_rating_consensus' => 'decimal:3',
            'away_rating_consensus' => 'decimal:3',
            'rating_consensus_payload' => 'array',
            'explosiveness_spread_adjustment' => 'decimal:3',
            'explosiveness_total_adjustment' => 'decimal:3',
            'home_explosiveness_score' => 'decimal:3',
            'away_explosiveness_score' => 'decimal:3',
            'explosiveness_payload' => 'array',
            'line_qb_spread_adjustment' => 'decimal:3',
            'home_line_qb_score' => 'decimal:3',
            'away_line_qb_score' => 'decimal:3',
            'line_qb_payload' => 'array',
            'market_movement_spread_adjustment' => 'decimal:3',
            'market_confidence_penalty' => 'decimal:3',
            'opening_home_spread' => 'decimal:2',
            'current_home_spread' => 'decimal:2',
            'closing_home_spread' => 'decimal:2',
            'consensus_home_spread' => 'decimal:2',
            'market_movement_payload' => 'array',
            'schedule_context_spread_adjustment' => 'decimal:3',
            'schedule_context_total_adjustment' => 'decimal:3',
            'schedule_confidence_penalty' => 'decimal:3',
            'home_rest_days' => 'integer',
            'away_rest_days' => 'integer',
            'schedule_context_payload' => 'array',
            'scheme_spread_adjustment' => 'decimal:3',
            'scheme_total_adjustment' => 'decimal:3',
            'scheme_confidence_penalty' => 'decimal:3',
            'home_scheme_change_score' => 'decimal:3',
            'away_scheme_change_score' => 'decimal:3',
            'scheme_payload' => 'array',
            'special_teams_spread_adjustment' => 'decimal:3',
            'special_teams_total_adjustment' => 'decimal:3',
            'home_special_teams_score' => 'decimal:3',
            'away_special_teams_score' => 'decimal:3',
            'special_teams_payload' => 'array',
            'signal_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }
}
