<?php

namespace App\Models\CFB;

use Database\Factories\CfbTeamStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamStat extends Model
{
    /** @use HasFactory<CfbTeamStatFactory> */
    use HasFactory;

    protected $table = 'cfb_team_stats';

    protected $fillable = [
        'team_id',
        'game_id',
        'team_type',
        'total_yards',
        'passing_yards',
        'passing_completions',
        'passing_attempts',
        'passing_touchdowns',
        'interceptions',
        'rushing_yards',
        'rushing_attempts',
        'rushing_touchdowns',
        'fumbles',
        'fumbles_lost',
        'sacks_allowed',
        'first_downs',
        'third_down_conversions',
        'third_down_attempts',
        'fourth_down_conversions',
        'fourth_down_attempts',
        'red_zone_attempts',
        'red_zone_scores',
        'penalties',
        'penalty_yards',
        'time_of_possession',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
