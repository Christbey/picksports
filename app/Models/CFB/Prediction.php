<?php

namespace App\Models\CFB;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prediction extends Model
{
    /** @use HasFactory<\Database\Factories\CfbPredictionFactory> */
    use HasFactory;

    protected $table = 'cfb_predictions';

    protected $fillable = [
        'game_id',
        'home_elo',
        'away_elo',
        'home_fpi',
        'away_fpi',
        'predicted_spread',
        'predicted_total',
        'win_probability',
        'confidence_score',
        'model_version',
        'feature_version',
        'blend_version',
        'actual_spread',
        'actual_total',
        'spread_error',
        'total_error',
        'winner_correct',
        'graded_at',
        'live_predicted_spread',
        'live_win_probability',
        'live_predicted_total',
        'live_seconds_remaining',
        'live_updated_at',
        'narrative_json',
        'narrative_provider',
        'narrative_model',
        'narrative_input_hash',
        'narrative_latency_ms',
        'narrative_generated_at',
    ];

    protected function casts(): array
    {
        return [
            'home_elo' => 'decimal:1',
            'away_elo' => 'decimal:1',
            'home_fpi' => 'decimal:1',
            'away_fpi' => 'decimal:1',
            'predicted_spread' => 'decimal:1',
            'predicted_total' => 'decimal:1',
            'win_probability' => 'decimal:3',
            'confidence_score' => 'decimal:2',
            'model_version' => 'string',
            'feature_version' => 'string',
            'blend_version' => 'string',
            'actual_spread' => 'decimal:1',
            'actual_total' => 'decimal:1',
            'spread_error' => 'decimal:1',
            'total_error' => 'decimal:1',
            'winner_correct' => 'boolean',
            'graded_at' => 'datetime',
            'live_predicted_spread' => 'decimal:1',
            'live_win_probability' => 'decimal:3',
            'live_predicted_total' => 'decimal:1',
            'live_seconds_remaining' => 'integer',
            'live_updated_at' => 'datetime',
            'narrative_json' => 'array',
            'narrative_latency_ms' => 'integer',
            'narrative_generated_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
