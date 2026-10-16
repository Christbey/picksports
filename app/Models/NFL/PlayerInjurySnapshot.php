<?php

namespace App\Models\NFL;

use App\Services\ProviderData\CompressedJsonPayloadStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlayerInjurySnapshot extends Model
{
    protected $table = 'nfl_player_injury_snapshots';

    protected $fillable = [
        'snapshot_uuid',
        'team_id',
        'espn_team_id',
        'provider',
        'observed_at',
        'source_updated_at',
        'payload_hash',
        'entry_count',
        'raw_payload',
        'payload_archive',
    ];

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'entry_count' => 'integer',
            'raw_payload' => 'array',
            'payload_archive' => 'array',
        ];
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function reportPayload(): ?array
    {
        if ($this->raw_payload !== null) {
            return $this->raw_payload;
        }

        if ($this->payload_archive === null) {
            return null;
        }

        return app(CompressedJsonPayloadStorage::class)->read($this->payload_archive);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PlayerInjurySnapshotEntry::class, 'snapshot_id');
    }
}
