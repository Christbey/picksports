<?php

namespace App\Models\MLB;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamStat extends Model
{
    /** @use HasFactory<\Database\Factories\MlbTeamStatFactory> */
    use HasFactory;

    protected $table = 'mlb_team_stats';

    protected static function newFactory(): \Database\Factories\MlbTeamStatFactory
    {
        return \Database\Factories\MlbTeamStatFactory::new();
    }

    protected $fillable = [
        'team_id',
        'game_id',
        'team_type',
        // Batting stats
        'runs',
        'hits',
        'errors',
        'at_bats',
        'doubles',
        'triples',
        'home_runs',
        'rbis',
        'walks',
        'strikeouts',
        'stolen_bases',
        'left_on_base',
        'batting_average',
        // Pitching stats
        'pitchers_used',
        'innings_pitched',
        'hits_allowed',
        'runs_allowed',
        'earned_runs',
        'walks_allowed',
        'strikeouts_pitched',
        'home_runs_allowed',
        'total_pitches',
        'era',
        // Fielding stats
        'putouts',
        'assists',
        'double_plays',
        'passed_balls',
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
