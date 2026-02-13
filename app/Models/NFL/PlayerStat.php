<?php

namespace App\Models\NFL;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerStat extends Model
{
    /** @use HasFactory<\Database\Factories\NflPlayerStatFactory> */
    use HasFactory;

    protected $table = 'nfl_player_stats';

    protected $fillable = [
        'player_id',
        'game_id',
        'team_id',
        'stat_type',
        'completions',
        'attempts',
        'passing_yards',
        'passing_touchdowns',
        'interceptions',
        'carries',
        'rushing_yards',
        'rushing_touchdowns',
        'receptions',
        'receiving_yards',
        'receiving_touchdowns',
        'targets',
        'fumbles',
        'fumbles_lost',
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
