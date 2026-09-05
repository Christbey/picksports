<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class PredictionEvaluation extends Model
{
    protected $fillable = [
        'canonical_prediction_id',
        'sport_event_id',
        'sport_event_result_id',
        'evaluation_revision',
        'supersedes_prediction_evaluation_id',
        'sport',
        'prediction_phase',
        'scoring_version',
        'evaluation_hash',
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
            'evaluation_revision' => 'integer',
            'evaluated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $evaluation): void {
            if ($evaluation->getOriginal('canonical_prediction_id') !== null) {
                throw new LogicException('Canonical prediction evaluations are immutable.');
            }
        });

        static::deleting(function (self $evaluation): void {
            if ($evaluation->canonical_prediction_id !== null) {
                throw new LogicException('Canonical prediction evaluations are immutable.');
            }
        });
    }

    public function canonicalPrediction(): BelongsTo
    {
        return $this->belongsTo(CanonicalPrediction::class);
    }

    public function sportEvent(): BelongsTo
    {
        return $this->belongsTo(SportEvent::class);
    }

    public function eventResult(): BelongsTo
    {
        return $this->belongsTo(SportEventResult::class, 'sport_event_result_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_prediction_evaluation_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_prediction_evaluation_id');
    }
}
