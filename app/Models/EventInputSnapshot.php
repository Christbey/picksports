<?php

namespace App\Models;

use App\Models\Concerns\CachesJsonArrayCasts;
use App\Models\Concerns\HasPublicUlid;
use App\Services\Predictions\CfbSnapshotInputStorage;
use Database\Factories\EventInputSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class EventInputSnapshot extends Model
{
    /** @use HasFactory<EventInputSnapshotFactory> */
    use CachesJsonArrayCasts {
        decodeArrayAttribute as private decodeStoredArrayAttribute;
    }

    use HasFactory, HasPublicUlid;

    protected $fillable = [
        'public_id',
        'sport_event_id',
        'sport',
        'phase',
        'schema_version',
        'captured_at',
        'cutoff_at',
        'latest_source_available_at',
        'source_timestamps',
        'inputs',
        'object_uri',
        'content_hash',
        'pregame_safety_status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'immutable_datetime',
            'cutoff_at' => 'immutable_datetime',
            'latest_source_available_at' => 'immutable_datetime',
            'source_timestamps' => 'array',
            'inputs' => 'array',
            'metadata' => 'array',
        ];
    }

    protected function decodeArrayAttribute($key, $value)
    {
        $decoded = $this->decodeStoredArrayAttribute($key, $value);

        return $key === 'inputs'
            ? app(CfbSnapshotInputStorage::class)->resolve($decoded)
            : $decoded;
    }

    public function inlineInputs(): ?array
    {
        return $this->decodeStoredArrayAttribute('inputs', $this->attributes['inputs'] ?? null);
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Event input snapshots are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Event input snapshots are immutable.'));
    }

    public function sportEvent(): BelongsTo
    {
        return $this->belongsTo(SportEvent::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CalculationRun::class);
    }
}
