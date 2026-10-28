<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'sport',
        'run_type',
        'model_version',
        'feature_version',
        'blend_version',
        'config_hash',
        'code_version',
        'artifact_path',
        'artifact_hash',
        'parameters',
        'status',
        'started_at',
        'completed_at',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $run): void {
            if ($run->getOriginal('run_type') === 'evidence_trial' && $run->isDirty(['parameters', 'config_hash', 'code_version', 'model_version', 'feature_version', 'blend_version'])) {
                throw new \LogicException('Evidence trial protocols are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(ModelArtifact::class, 'training_run_id');
    }

    public function shadowOutputs(): HasMany
    {
        return $this->hasMany(ShadowModelOutput::class, 'inference_run_id');
    }

    public function canonicalPredictions(): HasMany
    {
        return $this->hasMany(CanonicalPrediction::class);
    }
}
