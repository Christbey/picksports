<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameOddsSnapshot extends Model
{
    protected $fillable = [
        'sport',
        'game_table',
        'game_id',
        'odds_api_event_id',
        'bookmaker_key',
        'bookmaker_title',
        'source',
        'commence_time',
        'captured_at',
        'payload_hash',
        'odds_data',
        'market_context',
    ];

    protected function casts(): array
    {
        return [
            'commence_time' => 'datetime',
            'captured_at' => 'datetime',
            'odds_data' => 'array',
            'market_context' => 'array',
        ];
    }

    public function marketQuotes(): HasMany
    {
        return $this->hasMany(MarketQuote::class);
    }
}
