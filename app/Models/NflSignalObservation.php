<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class NflSignalObservation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'prediction_feature_snapshot_id',
        'model_run_id',
        'prediction_id',
        'game_id',
        'season',
        'week',
        'snapshot_run_id',
        'model_version',
        'feature_version',
        'blend_version',
        'config_hash',
        'feature_hash',
        'signal_type',
        'signal_key',
        'label',
        'market_type',
        'direction',
        'action',
        'is_actionable',
        'is_diagnostic',
        'requires_market',
        'pregame_safe',
        'availability_status',
        'signal_payload',
        'definition_hash',
        'observation_hash',
        'observed_at',
        'game_start_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('NFL signal observations are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('NFL signal observations are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'season' => 'integer',
            'week' => 'integer',
            'is_actionable' => 'boolean',
            'is_diagnostic' => 'boolean',
            'requires_market' => 'boolean',
            'pregame_safe' => 'boolean',
            'signal_payload' => 'array',
            'observed_at' => 'datetime',
            'game_start_at' => 'datetime',
        ];
    }

    public function featureSnapshot(): BelongsTo
    {
        return $this->belongsTo(PredictionFeatureSnapshot::class, 'prediction_feature_snapshot_id');
    }

    public function modelRun(): BelongsTo
    {
        return $this->belongsTo(ModelRun::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(NflSignalGrade::class);
    }

    public function scopePregameSafe(Builder $query): Builder
    {
        return $query->where('pregame_safe', true);
    }
}
