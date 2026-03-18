<?php

namespace App\Models\CBB;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentForecast extends Model
{
    /** @use HasFactory<\Database\Factories\CbbTournamentForecastFactory> */
    use HasFactory;

    protected $table = 'cbb_tournament_forecasts';

    protected static function newFactory(): \Database\Factories\CbbTournamentForecastFactory
    {
        return \Database\Factories\CbbTournamentForecastFactory::new();
    }

    protected $fillable = [
        'team_id',
        'snapshot_id',
        'placeholder_key',
        'season',
        'as_of',
        'mode',
        'region',
        'seed',
        'team_display_name',
        'team_abbreviation',
        'is_first_four',
        'is_alive',
        'is_eliminated',
        'reached_round',
        'eliminated_round',
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
        'games_final_count',
        'round_of_32_probability',
        'sweet_16_probability',
        'elite_8_probability',
        'simulated_field_appearances',
        'simulated_titles',
        'simulation_runs',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_id' => 'integer',
            'team_id' => 'integer',
            'season' => 'integer',
            'as_of' => 'datetime',
            'selection_score' => 'decimal:4',
            'seed' => 'integer',
            'is_first_four' => 'boolean',
            'is_alive' => 'boolean',
            'is_eliminated' => 'boolean',
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
            'games_final_count' => 'integer',
            'round_of_32_probability' => 'decimal:5',
            'sweet_16_probability' => 'decimal:5',
            'elite_8_probability' => 'decimal:5',
            'simulated_field_appearances' => 'integer',
            'simulated_titles' => 'integer',
            'simulation_runs' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(TournamentStateSnapshot::class, 'snapshot_id');
    }
}
