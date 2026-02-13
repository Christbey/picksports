<?php

namespace App\Models\MLB;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Play extends Model
{
    protected $table = 'mlb_plays';

    protected $fillable = [
        'game_id',
        'espn_play_id',
        'sequence_number',
        'inning',
        'inning_half',
        'play_type',
        'play_text',
        'score_value',
        'is_at_bat',
        'is_scoring_play',
        'is_out',
        'balls',
        'strikes',
        'outs',
        'home_score',
        'away_score',
        'batting_team_id',
        'pitching_team_id',
    ];

    protected function casts(): array
    {
        return [
            'is_at_bat' => 'boolean',
            'is_scoring_play' => 'boolean',
            'is_out' => 'boolean',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    public function battingTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'batting_team_id');
    }

    public function pitchingTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'pitching_team_id');
    }
}
