<?php

namespace App\Models;

use App\Support\Models\GuardsCoreEvidenceMutations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PredictionPublicationEffect extends Model
{
    use GuardsCoreEvidenceMutations;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array', 'attempts' => 'integer',
            'lease_expires_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $effect): void {
            if ($effect->isDirty(['canonical_prediction_id', 'effect', 'payload'])) {
                throw new LogicException('Publication effect identity and admission payload are immutable.');
            }
            if (in_array($effect->getOriginal('state'), ['completed', 'cancelled', 'exhausted'], true)) {
                throw new LogicException('Terminal publication effect receipts are immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Publication delivery receipts cannot be deleted.'));
    }

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(CanonicalPrediction::class, 'canonical_prediction_id');
    }
}
