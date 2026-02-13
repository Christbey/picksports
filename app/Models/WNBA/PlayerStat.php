<?php

namespace App\Models\WNBA;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerStat extends Model
{
    /** @use HasFactory<\Database\Factories\CbbPlayerStatFactory> */
    use HasFactory;

    protected $table = 'wnba_player_stats';

    protected $fillable = [
        'player_id',
        'game_id',
        'team_id',
        'stat_type',
        'minutes',
        'points',
        'field_goals_made',
        'field_goals_attempted',
        'three_pointers_made',
        'three_pointers_attempted',
        'free_throws_made',
        'free_throws_attempted',
        'rebounds',
        'offensive_rebounds',
        'defensive_rebounds',
        'assists',
        'steals',
        'blocks',
        'turnovers',
        'fouls',
        'plus_minus',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
