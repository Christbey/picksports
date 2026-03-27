<?php

namespace App\Models\NBA;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepthChartEntry extends Model
{
    protected $table = 'nba_depth_chart_entries';

    protected $fillable = [
        'team_id',
        'player_id',
        'season',
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
        'source_updated_at',
        'raw_payload',
    ];

    protected $casts = [
        'is_starter' => 'boolean',
        'source_updated_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }
}
