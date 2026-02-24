<?php

namespace App\Models\MLB;

use Database\Factories\MlbPlayerStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerStat extends Model
{
    /** @use HasFactory<MlbPlayerStatFactory> */
    use HasFactory;

    protected $table = 'mlb_player_stats';

    protected static function newFactory(): MlbPlayerStatFactory
    {
        return MlbPlayerStatFactory::new();
    }

    protected $fillable = [
        'player_id',
        'game_id',
        'team_id',
        'stat_type',
        // Batting stats
        'at_bats',
        'runs',
        'hits',
        'doubles',
        'triples',
        'home_runs',
        'rbis',
        'walks',
        'strikeouts',
        'stolen_bases',
        'caught_stealing',
        'batting_average',
        'on_base_percentage',
        'slugging_percentage',
        // Pitching stats
        'innings_pitched',
        'hits_allowed',
        'runs_allowed',
        'earned_runs',
        'walks_allowed',
        'strikeouts_pitched',
        'home_runs_allowed',
        'era',
        'pitches_thrown',
        'pitch_count',
        // Fielding stats
        'putouts',
        'assists',
        'errors',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
