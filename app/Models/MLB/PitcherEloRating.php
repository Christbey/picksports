<?php

namespace App\Models\MLB;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PitcherEloRating extends Model
{
    protected $table = 'mlb_pitcher_elo_ratings';

    protected $fillable = [
        'player_id',
        'game_id',
        'season',
        'date',
        'elo_rating',
        'elo_change',
        'games_started',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'elo_rating' => 'decimal:1',
            'elo_change' => 'decimal:1',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
