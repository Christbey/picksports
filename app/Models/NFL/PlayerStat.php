<?php

namespace App\Models\NFL;

use Database\Factories\NflPlayerStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerStat extends Model
{
    /** @use HasFactory<NflPlayerStatFactory> */
    use HasFactory;

    protected $table = 'nfl_player_stats';

    protected $fillable = [
        'player_id',
        'game_id',
        'team_id',
        // Passing
        'passing_completions',
        'passing_attempts',
        'passing_yards',
        'passing_touchdowns',
        'interceptions_thrown',
        'sacks_taken',
        'passing_long',
        'sack_yards_lost',
        'passing_two_point_conversions',
        // Rushing
        'rushing_attempts',
        'rushing_yards',
        'rushing_touchdowns',
        'rushing_long',
        'rushing_two_point_conversions',
        // Receiving
        'receptions',
        'receiving_yards',
        'receiving_touchdowns',
        'receiving_targets',
        'receiving_long',
        'receiving_two_point_conversions',
        // Returning
        'kickoff_returns',
        'kickoff_return_yards',
        'kickoff_return_touchdowns',
        'kickoff_return_long',
        'kickoff_return_fair_catches',
        'punt_returns',
        'punt_return_yards',
        'punt_return_touchdowns',
        'punt_return_long',
        'punt_return_fair_catches',
        // Defense
        'tackles_total',
        'tackles_solo',
        'tackles_assists',
        'sacks',
        'interceptions',
        'passes_defended',
        'fumbles_forced',
        'fumbles_recovered',
        // Kicking
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
