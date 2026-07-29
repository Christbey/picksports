<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PredictionFeatureSnapshot extends Model
{
    protected $fillable = [
        'sport',
        'prediction_table',
        'prediction_id',
        'game_id',
        'snapshot_run_id',
        'model_run_id',
        'model_version',
        'feature_version',
        'blend_version',
        'features',
        'outputs',
        'market_context',
        'model_metadata',
        'feature_hash',
        'generated_at',
        'game_start_at',
        'features_available_at',
        'pregame_safe',
        'availability_status',
        'source_timestamps',
        'lineage_metadata',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'outputs' => 'array',
            'market_context' => 'array',
            'model_metadata' => 'array',
            'generated_at' => 'datetime',
            'game_start_at' => 'datetime',
            'features_available_at' => 'datetime',
            'pregame_safe' => 'boolean',
            'source_timestamps' => 'array',
            'lineage_metadata' => 'array',
        ];
    }

    public function modelRun(): BelongsTo
    {
        return $this->belongsTo(ModelRun::class);
    }

    public function shadowOutputs(): HasMany
    {
        return $this->hasMany(ShadowModelOutput::class);
    }
}
