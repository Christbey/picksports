<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShadowModelOutput extends Model
{
    protected $fillable = [
        'inference_run_id',
        'model_artifact_id',
        'prediction_feature_snapshot_id',
        'sport',
        'game_table',
        'game_id',
        'prediction_table',
        'prediction_id',
        'market_type',
        'baseline_output',
        'challenger_output',
        'output_delta',
        'status',
        'explanation',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'baseline_output' => 'float',
            'challenger_output' => 'float',
            'output_delta' => 'float',
            'explanation' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function inferenceRun(): BelongsTo
    {
        return $this->belongsTo(ModelRun::class, 'inference_run_id');
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(ModelArtifact::class, 'model_artifact_id');
    }

    public function featureSnapshot(): BelongsTo
    {
        return $this->belongsTo(PredictionFeatureSnapshot::class, 'prediction_feature_snapshot_id');
    }

    public function betDecisions(): HasMany
    {
        return $this->hasMany(BetDecision::class);
    }
}
