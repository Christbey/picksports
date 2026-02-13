<?php

namespace App\Models\WNBA;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamStat extends Model
{
    /** @use HasFactory<\Database\Factories\CbbTeamStatFactory> */
    use HasFactory;

    protected $table = 'wnba_team_stats';

    protected $fillable = [
        'team_id',
        'game_id',
        'points',
        'field_goals_made',
        'field_goals_attempted',
        'field_goal_percentage',
        'three_pointers_made',
        'three_pointers_attempted',
        'three_point_percentage',
        'free_throws_made',
        'free_throws_attempted',
        'free_throw_percentage',
        'rebounds',
        'offensive_rebounds',
        'defensive_rebounds',
        'assists',
        'steals',
        'blocks',
        'turnovers',
        'fouls',
    ];

    protected function casts(): array
    {
        return [
            'field_goal_percentage' => 'decimal:1',
            'three_point_percentage' => 'decimal:1',
            'free_throw_percentage' => 'decimal:1',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
