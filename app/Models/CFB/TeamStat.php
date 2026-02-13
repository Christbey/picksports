<?php

namespace App\Models\CFB;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamStat extends Model
{
    /** @use HasFactory<\Database\Factories\CfbTeamStatFactory> */
    use HasFactory;

    protected $table = 'cfb_team_stats';

    protected $fillable = [
        'team_id',
        'game_id',
        'total_yards',
        'passing_yards',
        'rushing_yards',
        'first_downs',
        'third_down_conversions',
        'third_down_attempts',
        'fourth_down_conversions',
        'fourth_down_attempts',
        'turnovers',
        'penalties',
        'penalty_yards',
        'possession_time',
        'sacks',
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
