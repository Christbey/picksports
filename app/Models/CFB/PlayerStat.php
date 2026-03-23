<?php

namespace App\Models\CFB;

use Database\Factories\CfbPlayerStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerStat extends Model
{
    /** @use HasFactory<CfbPlayerStatFactory> */
    use HasFactory;

    protected $table = 'cfb_player_stats';

    protected $fillable = [
        'player_id',
        'game_id',
        'team_id',
        'passing_completions',
        'passing_attempts',
        'passing_yards',
        'passing_touchdowns',
        'interceptions_thrown',
        'sacks_taken',
        'rushing_attempts',
        'rushing_yards',
        'rushing_touchdowns',
        'rushing_long',
        'receptions',
        'receiving_yards',
        'receiving_touchdowns',
        'receiving_targets',
        'receiving_long',
        'tackles_total',
        'tackles_solo',
        'tackles_assists',
        'sacks',
        'interceptions',
        'passes_defended',
        'fumbles_forced',
        'fumbles_recovered',
        'field_goals_made',
        'field_goals_attempted',
        'extra_points_made',
        'extra_points_attempted',
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
