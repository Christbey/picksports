<?php

namespace App\Models\NBA;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Play extends Model
{
    /** @use HasFactory<\Database\Factories\NbaPlayFactory> */
    use HasFactory;

    protected $table = 'nba_plays';

    protected $fillable = [
        'game_id',
        'possession_team_id',
        'espn_play_id',
        'sequence_number',
        'period',
        'clock',
        'play_type',
        'play_text',
        'score_value',
        'shooting_play',
        'made_shot',
        'assist',
        'is_turnover',
        'is_foul',
        'home_score',
        'away_score',
    ];

    protected function casts(): array
    {
        return [
            'shooting_play' => 'boolean',
            'made_shot' => 'boolean',
            'assist' => 'boolean',
            'is_turnover' => 'boolean',
            'is_foul' => 'boolean',
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

    protected static function newFactory(): \Database\Factories\NbaPlayFactory
    {
        return \Database\Factories\NbaPlayFactory::new();
    }
}
