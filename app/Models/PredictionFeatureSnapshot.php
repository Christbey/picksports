<?php

namespace App\Models;

use App\Services\Predictions\SnapshotMetadataStorage;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class PredictionFeatureSnapshot extends Model
{
    protected $with = ['metadataPayload'];

    protected $hidden = ['metadataPayload'];

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

    protected function modelMetadata(): Attribute
    {
        return Attribute::get(function (?string $value): ?array {
            if ($value !== null && str_starts_with($value, '"'.SnapshotMetadataStorage::REFERENCE_PREFIX)) {
                $this->loadMissing('metadataPayload');
            }

            return app(SnapshotMetadataStorage::class)->resolve(
                $value,
                $this->relationLoaded('metadataPayload') ? $this->getRelation('metadataPayload') : null,
            );
        });
    }

    protected function metadataPayloadHash(): Attribute
    {
        return Attribute::get(function (): ?string {
            $raw = $this->getRawOriginal('model_metadata');
            if (! is_string($raw) || ! str_starts_with($raw, '"')) {
                return null;
            }

            $reference = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return app(SnapshotMetadataStorage::class)->isReference($reference)
                ? substr($reference, strlen(SnapshotMetadataStorage::REFERENCE_PREFIX)) : null;
        });
    }

    public function metadataPayload(): BelongsTo
    {
        return $this->belongsTo(SnapshotMetadataPayload::class, 'metadata_payload_hash', 'sha256');
    }

    protected static function booted(): void
    {
        $immutable = function (self $snapshot): void {
            if ($snapshot->getOriginal('blend_version') === 'nfl-prop-shadow-v1') {
                throw new LogicException('NFL prop shadow snapshots are immutable.');
            }
        };
        static::updating($immutable);
        static::deleting($immutable);
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
