<?php

namespace App\Models\Sports;

use Illuminate\Database\Eloquent\Model;

abstract class AbstractPlayerProp extends Model
{
    protected $fillable = [
        'game_id',
        'player_id',
        'odds_api_event_id',
        'player_name',
        'market',
        'bookmaker',
        'line',
        'over_price',
        'under_price',
        'raw_data',
        'fetched_at',
        'actual_value',
        'hit_over',
        'error',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'line' => 'decimal:2',
            'over_price' => 'integer',
            'under_price' => 'integer',
            'raw_data' => 'array',
            'fetched_at' => 'datetime',
            'actual_value' => 'decimal:2',
            'hit_over' => 'boolean',
            'error' => 'decimal:2',
            'graded_at' => 'datetime',
        ];
    }
}
