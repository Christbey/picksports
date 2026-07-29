<?php

namespace App\Models\NFL;

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
    ];

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'entry_count' => 'integer',
            'raw_payload' => 'array',
        ];
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
