<?php

namespace App\Models\NFL;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Game extends Model
{
    /** @use HasFactory<\Database\Factories\NflGameFactory> */
    use HasFactory;

    protected $table = 'nfl_games';

    protected $fillable = [
        'espn_event_id',
        'espn_uid',
        'home_team_id',
        'away_team_id',
        'season',
        'season_type',
        'week',
        'game_date',
        'game_time',
        'name',
        'short_name',
        'venue_name',
        'venue_city',
        'venue_state',
        'neutral_site',
        'status',
        'odds_api_event_id',
        'odds_data',
        'odds_updated_at',
        'period',
        'game_clock',
        'home_score',
        'away_score',
        'home_linescores',
        'away_linescores',
        'broadcast_networks',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'game_date' => 'datetime',
            'completed_at' => 'datetime',
            'odds_updated_at' => 'datetime',
            'home_linescores' => 'array',
            'away_linescores' => 'array',
            'broadcast_networks' => 'array',
            'odds_data' => 'array',
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

    protected static function newFactory(): \Database\Factories\NflGameFactory
    {
        return \Database\Factories\NflGameFactory::new();
    }
}
