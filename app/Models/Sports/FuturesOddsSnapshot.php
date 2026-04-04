<?php

namespace App\Models\Sports;

use App\Models\NFL\Player;
use App\Models\NBA\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuturesOddsSnapshot extends Model
{
    protected $table = 'sports_futures_odds_snapshots';

    protected $fillable = [
        'snapshot_key',
        'row_key',
        'sport',
        'season',
        'odds_api_sport_key',
        'event_id',
        'event_name',
        'commence_time',
        'nba_team_id',
        'mlb_team_id',
        'nfl_team_id',
        'nfl_player_id',
        'cbb_team_id',
        'wcbb_team_id',
        'bookmaker',
        'market_key',
        'market_last_update',
        'outcome_name',
        'outcome_description',
        'outcome_point',
        'price',
        'implied_probability',
        'raw_data',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'season' => 'integer',
            'commence_time' => 'datetime',
            'nba_team_id' => 'integer',
            'mlb_team_id' => 'integer',
            'nfl_team_id' => 'integer',
            'nfl_player_id' => 'integer',
            'cbb_team_id' => 'integer',
            'wcbb_team_id' => 'integer',
            'market_last_update' => 'datetime',
            'outcome_point' => 'decimal:3',
            'price' => 'integer',
            'implied_probability' => 'decimal:6',
            'raw_data' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    public function nbaTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'nba_team_id');
    }

    public function mlbTeam(): BelongsTo
    {
        return $this->belongsTo(\App\Models\MLB\Team::class, 'mlb_team_id');
    }

    public function nflTeam(): BelongsTo
    {
        return $this->belongsTo(\App\Models\NFL\Team::class, 'nfl_team_id');
    }

    public function nflPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'nfl_player_id');
    }

    public function cbbTeam(): BelongsTo
    {
        return $this->belongsTo(\App\Models\CBB\Team::class, 'cbb_team_id');
    }

    public function wcbbTeam(): BelongsTo
    {
        return $this->belongsTo(\App\Models\WCBB\Team::class, 'wcbb_team_id');
    }
}
