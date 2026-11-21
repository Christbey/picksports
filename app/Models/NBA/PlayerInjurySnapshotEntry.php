<?php

namespace App\Models\NBA;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerInjurySnapshotEntry extends ImmutableInjuryRecord
{
    protected $table = 'nba_player_injury_snapshot_entries';

    protected function casts(): array
    {
        return ['raw_payload' => 'array', 'issues' => 'array', 'source_updated_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(PlayerInjurySnapshot::class, 'snapshot_id');
    }
}
