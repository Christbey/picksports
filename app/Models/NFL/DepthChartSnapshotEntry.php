<?php

namespace App\Models\NFL;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepthChartSnapshotEntry extends Model
{
    protected $table = 'nfl_depth_chart_snapshot_entries';

    protected $fillable = [
        'snapshot_id',
        'player_id',
        'espn_depth_chart_id',
        'depth_chart_name',
        'position_slot_key',
        'position_espn_id',
        'position_code',
        'position_name',
        'position_display_name',
        'espn_athlete_id',
        'slot_order',
        'depth_rank',
        'is_starter',
        'observed_at',
        'source_updated_at',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'slot_order' => 'integer',
            'depth_rank' => 'integer',
            'is_starter' => 'boolean',
            'observed_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(DepthChartSnapshot::class, 'snapshot_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }
}
