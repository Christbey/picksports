<?php

namespace App\Models\NFL;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerInjurySnapshotEntry extends Model
{
    protected $table = 'nfl_player_injury_snapshot_entries';

    protected $fillable = [
        'snapshot_id',
        'player_id',
        'espn_athlete_id',
        'injury_key',
        'espn_injury_id',
        'status',
        'detail',
        'type',
        'injury_date',
        'return_date',
        'observed_at',
        'source_updated_at',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'injury_date' => 'date',
            'return_date' => 'date',
            'observed_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(PlayerInjurySnapshot::class, 'snapshot_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }
}
