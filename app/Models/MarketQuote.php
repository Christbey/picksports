<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketQuote extends Model
{
    protected $fillable = [
        'game_odds_snapshot_id',
        'sport',
        'game_table',
        'game_id',
        'source',
        'bookmaker_key',
        'bookmaker_title',
        'market_key',
        'side',
        'participant',
        'line',
        'price',
        'bookmaker_home_line',
        'home_margin_equivalent',
        'implied_probability',
        'no_vig_probability',
        'commence_time',
        'captured_at',
        'is_pregame',
        'quote_hash',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'line' => 'decimal:3',
            'bookmaker_home_line' => 'decimal:3',
            'home_margin_equivalent' => 'decimal:3',
            'implied_probability' => 'decimal:6',
            'no_vig_probability' => 'decimal:6',
            'commence_time' => 'datetime',
            'captured_at' => 'datetime',
            'is_pregame' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function gameOddsSnapshot(): BelongsTo
    {
        return $this->belongsTo(GameOddsSnapshot::class);
    }
}
