<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PredictionFeatureSnapshot extends Model
{
    protected $fillable = [
        'sport',
        'prediction_table',
        'prediction_id',
        'game_id',
        'model_version',
        'feature_version',
        'blend_version',
        'features',
        'outputs',
        'market_context',
        'model_metadata',
        'feature_hash',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'outputs' => 'array',
            'market_context' => 'array',
            'model_metadata' => 'array',
            'generated_at' => 'datetime',
        ];
    }
}
