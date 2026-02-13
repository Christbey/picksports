<?php

namespace App\Models\NBA;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EloRating extends Model
{
    /** @use HasFactory<\Database\Factories\CbbEloRatingFactory> */
    use HasFactory;

    protected $table = 'nba_elo_ratings';

    protected $fillable = [
        'team_id',
        'game_id',
        'season',
        'week',
        'date',
        'elo_rating',
        'elo_change',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'elo_rating' => 'decimal:1',
            'elo_change' => 'decimal:1',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
