<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PredictionEvaluation extends Model
{
    protected $fillable = [
        'sport',
        'prediction_table',
        'prediction_id',
        'game_id',
        'model_version',
        'feature_version',
        'blend_version',
        'actuals',
        'errors',
        'market_comparison',
        'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'actuals' => 'array',
            'errors' => 'array',
            'market_comparison' => 'array',
            'evaluated_at' => 'datetime',
        ];
    }
}
