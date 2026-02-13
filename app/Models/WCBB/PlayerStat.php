<?php

namespace App\Models\WCBB;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerStat extends Model
{
    /** @use HasFactory<\Database\Factories\CbbPlayerStatFactory> */
    use HasFactory;

    protected $table = 'wcbb_player_stats';

    protected $fillable = [
        'player_id',
        'game_id',
        'team_id',
        'minutes_played',
        'points',
        'field_goals_made',
        'field_goals_attempted',
        'three_point_made',
        'three_point_attempted',
        'free_throws_made',
        'free_throws_attempted',
        'rebounds_total',
        'rebounds_offensive',
        'rebounds_defensive',
        'assists',
        'steals',
        'blocks',
        'turnovers',
        'fouls',
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
