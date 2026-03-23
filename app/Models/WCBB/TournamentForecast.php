<?php

namespace App\Models\WCBB;

use Database\Factories\WcbbTournamentForecastFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentForecast extends Model
{
    /** @use HasFactory<WcbbTournamentForecastFactory> */
    use HasFactory;

    protected $table = 'wcbb_tournament_forecasts';

    protected static function newFactory(): WcbbTournamentForecastFactory
    {
        return WcbbTournamentForecastFactory::new();
    }

    protected $fillable = [
        'team_id',
        'season',
        'selection_score',
        'projected_seed',
        'auto_bid',
        'auto_bid_probability',
        'at_large_probability',
        'first_four_probability',
        'first_four_auto_probability',
        'first_four_at_large_probability',
        'bid_thief_probability',
        'tournament_make_probability',
        'champion_probability',
        'final_four_probability',
        'title_game_probability',
        'simulated_field_appearances',
        'simulated_titles',
        'simulation_runs',
    ];

    protected function casts(): array
    {
        return [
            'season' => 'integer',
            'selection_score' => 'decimal:4',
            'projected_seed' => 'integer',
            'auto_bid' => 'boolean',
            'auto_bid_probability' => 'decimal:5',
            'at_large_probability' => 'decimal:5',
            'first_four_probability' => 'decimal:5',
            'first_four_auto_probability' => 'decimal:5',
            'first_four_at_large_probability' => 'decimal:5',
            'bid_thief_probability' => 'decimal:5',
            'tournament_make_probability' => 'decimal:5',
            'champion_probability' => 'decimal:5',
            'final_four_probability' => 'decimal:5',
            'title_game_probability' => 'decimal:5',
            'simulated_field_appearances' => 'integer',
            'simulated_titles' => 'integer',
            'simulation_runs' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
