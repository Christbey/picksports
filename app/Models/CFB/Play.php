<?php

namespace App\Models\CFB;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Play extends Model
{
    /** @use HasFactory<\Database\Factories\CfbPlayFactory> */
    use HasFactory;

    protected $table = 'cfb_plays';

    protected $fillable = [
        'game_id',
        'possession_team_id',
        'espn_id',
        'sequence_number',
        'period',
        'clock',
        'down',
        'distance',
        'yard_line',
        'play_type',
        'play_text',
        'yards_gained',
        'scoring_play',
        'touchdown',
        'field_goal',
        'safety',
        'turnover',
    ];

    protected function casts(): array
    {
        return [
            'scoring_play' => 'boolean',
            'touchdown' => 'boolean',
            'field_goal' => 'boolean',
            'safety' => 'boolean',
            'turnover' => 'boolean',
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
