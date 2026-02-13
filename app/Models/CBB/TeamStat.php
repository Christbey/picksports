<?php

namespace App\Models\CBB;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamStat extends Model
{
    /** @use HasFactory<\Database\Factories\CbbTeamStatFactory> */
    use HasFactory;

    protected $table = 'cbb_team_stats';

    protected $fillable = [
        'team_id',
        'game_id',
        'team_type',
        'field_goals_made',
        'field_goals_attempted',
        'three_point_made',
        'three_point_attempted',
        'free_throws_made',
        'free_throws_attempted',
        'rebounds',
        'offensive_rebounds',
        'defensive_rebounds',
        'assists',
        'turnovers',
        'steals',
        'blocks',
        'fouls',
        'points',
        'possessions',
        'fast_break_points',
        'points_in_paint',
        'second_chance_points',
        'bench_points',
        'biggest_lead',
        'times_tied',
        'lead_changes',
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
