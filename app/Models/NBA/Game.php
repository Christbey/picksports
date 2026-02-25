<?php

namespace App\Models\NBA;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Game extends Model
{
    /** @use HasFactory<\Database\Factories\NbaGameFactory> */
    use HasFactory;

    protected $table = 'nba_games';

    protected $fillable = [
        'espn_event_id',
        'espn_uid',
        'season',
        'week',
        'season_type',
        'game_date',
        'game_time',
        'name',
        'short_name',
        'home_team_id',
        'away_team_id',
        'home_score',
        'away_score',
        'home_linescores',
        'away_linescores',
        'status',
        'period',
        'game_clock',
        'venue_name',
        'venue_city',
        'venue_state',
        'broadcast_networks',
        'odds_api_event_id',
        'odds_data',
        'odds_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'game_date' => 'datetime',
            'completed_at' => 'datetime',
            'home_linescores' => 'array',
            'away_linescores' => 'array',
            'broadcast_networks' => 'array',
            'odds_data' => 'array',
            'odds_updated_at' => 'datetime',
        ];
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function plays(): HasMany
    {
        return $this->hasMany(Play::class, 'game_id');
    }

    public function playerStats(): HasMany
    {
        return $this->hasMany(PlayerStat::class, 'game_id');
    }

    public function teamStats(): HasMany
    {
        return $this->hasMany(TeamStat::class, 'game_id');
    }

    public function prediction(): HasOne
    {
        return $this->hasOne(Prediction::class, 'game_id');
    }

    public function playerProps(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(\App\Models\PlayerProp::class, 'gameable');
    }

    protected static function newFactory(): \Database\Factories\NbaGameFactory
    {
        return \Database\Factories\NbaGameFactory::new();
    }
}
