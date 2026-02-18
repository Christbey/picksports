<?php

namespace App\Models\WCBB;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EloRating extends Model
{
    /** @use HasFactory<\Database\Factories\WcbbEloRatingFactory> */
    use HasFactory;

    protected $table = 'wcbb_elo_ratings';

    protected $fillable = [
        'team_id',
        'game_id',
        'season',
        'game_date',
        'elo_rating',
        'elo_change',
    ];

    protected function casts(): array
    {
        return [
            'game_date' => 'date',
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
