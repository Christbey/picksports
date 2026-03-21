<?php

namespace App\Models\MLB;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayoffForecast extends Model
{
    protected $table = 'mlb_playoff_forecasts';

    protected $fillable = [
        'team_id',
        'season',
        'league',
        'league_rank',
        'projected_seed',
        'selection_score',
        'playoff_make_probability',
        'league_championship_probability',
        'world_series_probability',
        'champion_probability',
        'simulation_runs',
    ];

    protected function casts(): array
    {
        return [
            'season' => 'integer',
            'league_rank' => 'integer',
            'projected_seed' => 'integer',
            'selection_score' => 'decimal:4',
            'playoff_make_probability' => 'decimal:5',
            'league_championship_probability' => 'decimal:5',
            'world_series_probability' => 'decimal:5',
            'champion_probability' => 'decimal:5',
            'simulation_runs' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
