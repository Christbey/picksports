<?php

namespace App\Models\WNBA;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Play extends Model
{
    /** @use HasFactory<\Database\Factories\CbbPlayFactory> */
    use HasFactory;

    protected $table = 'wnba_plays';

    protected $fillable = [
        'game_id',
        'possession_team_id',
        'espn_id',
        'sequence_number',
        'period',
        'clock',
        'play_type',
        'play_text',
        'scoring_play',
        'score_value',
        'home_score',
        'away_score',
    ];

    protected function casts(): array
    {
        return [
            'scoring_play' => 'boolean',
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
}
