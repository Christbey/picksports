<?php

namespace App\Models\NFL;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Play extends Model
{
    /** @use HasFactory<\Database\Factories\NflPlayFactory> */
    use HasFactory;

    protected $table = 'nfl_plays';

    protected $fillable = [
        'game_id',
        'possession_team_id',
        'espn_play_id',
        'sequence_number',
        'period',
        'clock',
        'down',
        'distance',
        'yards_to_endzone',
        'play_type',
        'play_text',
        'yards_gained',
        'is_scoring_play',
        'is_turnover',
        'is_penalty',
        'home_score',
        'away_score',
    ];

    protected function casts(): array
    {
        return [
            'is_scoring_play' => 'boolean',
            'is_turnover' => 'boolean',
            'is_penalty' => 'boolean',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    public function possessionTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'possession_team_id');
    }

    protected static function newFactory(): \Database\Factories\NflPlayFactory
    {
        return \Database\Factories\NflPlayFactory::new();
    }
}
