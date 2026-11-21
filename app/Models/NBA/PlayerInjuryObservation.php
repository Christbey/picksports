<?php

namespace App\Models\NBA;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerInjuryObservation extends ImmutableInjuryRecord
{
    protected $table = 'nba_player_injury_observations';

    protected function casts(): array
    {
        return ['acquisition_sequence' => 'integer', 'projection_applied' => 'boolean', 'fetch_metadata' => 'array', 'issues' => 'array', 'cache_hit' => 'boolean',
            'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'observed_at' => 'immutable_datetime'];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(PlayerInjurySnapshot::class, 'snapshot_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
