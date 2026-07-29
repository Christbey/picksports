<?php

namespace App\Models\NFL;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepthChartSnapshot extends Model
{
    protected $table = 'nfl_depth_chart_snapshots';

    protected $fillable = [
        'snapshot_uuid',
        'team_id',
        'espn_team_id',
        'season',
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
            'season' => 'integer',
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
        return $this->hasMany(DepthChartSnapshotEntry::class, 'snapshot_id');
    }
}
