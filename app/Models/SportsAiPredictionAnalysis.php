<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SportsAiPredictionAnalysis extends Model
{
    protected $fillable = [
        'sport',
        'game_id',
        'prediction_id',
        'game_date',
        'as_of_date',
        'market',
        'provider',
        'model',
        'input_hash',
        'raw_payload',
        'recommendation',
        'ai_confidence',
        'analysis_confidence',
        'bet_classification',
        'summary',
        'key_factors',
        'risk_flags',
        'reason_codes',
        'market_notes',
        'calculated_edge',
        'metadata',
        'latency_ms',
    ];

    protected function casts(): array
    {
        return [
            'game_id' => 'integer',
            'prediction_id' => 'integer',
            'game_date' => 'date',
            'as_of_date' => 'date',
            'raw_payload' => 'array',
            'ai_confidence' => 'integer',
            'analysis_confidence' => 'integer',
            'key_factors' => 'array',
            'risk_flags' => 'array',
            'reason_codes' => 'array',
            'market_notes' => 'array',
            'calculated_edge' => 'array',
            'metadata' => 'array',
            'latency_ms' => 'integer',
        ];
    }
}
