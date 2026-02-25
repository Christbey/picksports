<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PlayerProp extends Model
{
    protected $fillable = [
        'gameable_type',
        'gameable_id',
        'player_id',
        'sport',
        'odds_api_event_id',
        'player_name',
        'market',
        'bookmaker',
        'line',
        'over_price',
        'under_price',
        'raw_data',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'line' => 'decimal:2',
            'over_price' => 'integer',
            'under_price' => 'integer',
            'raw_data' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    public function gameable(): MorphTo
    {
        return $this->morphTo();
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
