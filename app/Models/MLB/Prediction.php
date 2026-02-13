<?php

namespace App\Models\MLB;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prediction extends Model
{
    protected $table = 'mlb_predictions';

    protected $fillable = [
        'game_id',
        'home_team_elo',
        'away_team_elo',
        'home_pitcher_elo',
        'away_pitcher_elo',
        'home_combined_elo',
        'away_combined_elo',
        'predicted_spread',
        'predicted_total',
        'win_probability',
        'confidence_score',
        'actual_spread',
        'actual_total',
        'spread_error',
        'total_error',
        'winner_correct',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'home_team_elo' => 'decimal:1',
            'away_team_elo' => 'decimal:1',
            'home_pitcher_elo' => 'decimal:1',
            'away_pitcher_elo' => 'decimal:1',
            'home_combined_elo' => 'decimal:1',
            'away_combined_elo' => 'decimal:1',
            'predicted_spread' => 'decimal:1',
            'predicted_total' => 'decimal:1',
            'win_probability' => 'decimal:3',
            'confidence_score' => 'decimal:3',
            'actual_spread' => 'decimal:1',
            'actual_total' => 'decimal:1',
            'spread_error' => 'decimal:1',
            'total_error' => 'decimal:1',
            'winner_correct' => 'boolean',
            'graded_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
