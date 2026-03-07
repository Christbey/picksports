<?php

namespace App\Models\NBA;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayoffForecast extends Model
{
    protected $table = 'nba_playoff_forecasts';

    protected $fillable = [
        'team_id',
        'season',
        'conference',
        'conference_rank',
        'projected_seed',
        'selection_score',
        'playoff_make_probability',
        'direct_playoff_probability',
        'play_in_tournament_probability',
        'division_win_probability',
        'conference_finals_probability',
        'nba_finals_probability',
        'champion_probability',
        'simulation_runs',
    ];

    protected function casts(): array
    {
        return [
            'season' => 'integer',
            'conference_rank' => 'integer',
            'projected_seed' => 'integer',
            'selection_score' => 'decimal:4',
            'playoff_make_probability' => 'decimal:5',
            'direct_playoff_probability' => 'decimal:5',
            'play_in_tournament_probability' => 'decimal:5',
            'division_win_probability' => 'decimal:5',
            'conference_finals_probability' => 'decimal:5',
            'nba_finals_probability' => 'decimal:5',
            'champion_probability' => 'decimal:5',
            'simulation_runs' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
