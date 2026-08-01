<?php

namespace App\Models\CFB;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PredictionCalibration extends Model
{
    protected $table = 'cfb_prediction_calibrations';

    protected $fillable = [
        'season',
        'training_from_week',
        'training_through_week',
        'games_count',
        'min_games',
        'learning_rate',
        'parameters',
        'metrics',
        'is_active',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'season' => 'integer',
            'training_from_week' => 'integer',
            'training_through_week' => 'integer',
            'games_count' => 'integer',
            'min_games' => 'integer',
            'learning_rate' => 'decimal:3',
            'parameters' => 'array',
            'metrics' => 'array',
            'is_active' => 'boolean',
            'generated_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
