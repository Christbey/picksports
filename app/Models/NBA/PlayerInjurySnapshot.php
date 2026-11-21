<?php

namespace App\Models\NBA;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlayerInjurySnapshot extends ImmutableInjuryRecord
{
    protected $table = 'nba_player_injury_snapshots';

    protected function casts(): array
    {
        return ['raw_payload' => 'array', 'issues' => 'array', 'entry_count' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PlayerInjurySnapshotEntry::class, 'snapshot_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(PlayerInjuryObservation::class, 'snapshot_id');
    }
}
