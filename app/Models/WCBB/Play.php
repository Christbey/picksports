<?php

namespace App\Models\WCBB;

use Database\Factories\CbbPlayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Play extends Model
{
    /** @use HasFactory<CbbPlayFactory> */
    use HasFactory;

    protected $table = 'wcbb_plays';

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
        'is_epa_eligible',
        'expected_points_before',
        'expected_points_after',
        'true_epa',
    ];

    protected function casts(): array
    {
        return [
            'scoring_play' => 'boolean',
            'is_epa_eligible' => 'boolean',
            'expected_points_before' => 'decimal:3',
            'expected_points_after' => 'decimal:3',
            'true_epa' => 'decimal:3',
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
