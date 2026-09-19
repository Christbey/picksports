<?php

namespace App\Models\CFB;

use Database\Factories\CfbEloRatingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EloRating extends Model
{
    /** @use HasFactory<CfbEloRatingFactory> */
    use HasFactory;

    protected $table = 'cfb_elo_ratings';

    protected $fillable = [
        'team_id',
        'game_id',
        'season',
        'week',
        'season_type',
        'date',
        'elo_rating',
        'elo_change',
        'elo_before',
        'model_version',
        'result_fingerprint',
        'season_initialization_id',
        'rebuilt_at',
        'active_slot',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('active', fn ($query) => $query->where('cfb_elo_ratings.active_slot', 1));
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'rebuilt_at' => 'datetime',
            'elo_before' => 'decimal:2',
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
