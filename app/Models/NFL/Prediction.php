<?php

namespace App\Models\NFL;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prediction extends Model
{
    /** @use HasFactory<\Database\Factories\NflPredictionFactory> */
    use HasFactory;

    protected $table = 'nfl_predictions';

    protected $fillable = [
        'game_id',
        'home_elo',
        'away_elo',
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
        // Live prediction columns
        'live_predicted_spread',
        'live_win_probability',
        'live_predicted_total',
        'live_seconds_remaining',
        'live_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'home_elo' => 'decimal:1',
            'away_elo' => 'decimal:1',
            'predicted_spread' => 'decimal:1',
            'predicted_total' => 'decimal:1',
            'win_probability' => 'decimal:3',
            'confidence_score' => 'decimal:2',
            'actual_spread' => 'decimal:1',
            'actual_total' => 'decimal:1',
            'spread_error' => 'decimal:1',
            'total_error' => 'decimal:1',
            'winner_correct' => 'boolean',
            'graded_at' => 'datetime',
            // Live prediction casts
            'live_predicted_spread' => 'decimal:1',
            'live_win_probability' => 'decimal:3',
            'live_predicted_total' => 'decimal:1',
            'live_seconds_remaining' => 'integer',
            'live_updated_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    protected static function newFactory(): \Database\Factories\NflPredictionFactory
    {
        return \Database\Factories\NflPredictionFactory::new();
    }
}
