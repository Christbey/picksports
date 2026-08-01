<?php

namespace App\Models\CFB;

use Database\Factories\CfbGameFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Game extends Model
{
    /** @use HasFactory<CfbGameFactory> */
    use HasFactory;

    protected $table = 'cfb_games';

    protected static function newFactory(): CfbGameFactory
    {
        return CfbGameFactory::new();
    }

    protected $fillable = [
        'espn_id',
        'espn_event_id',
        'espn_uid',
        'home_team_id',
        'away_team_id',
        'season',
        'season_type',
        'week',
        'postseason_round',
        'game_date',
        'game_time',
        'name',
        'short_name',
        'venue',
        'venue_name',
        'venue_city',
        'venue_state',
        'attendance',
        'status',
        'odds_api_event_id',
        'odds_data',
        'odds_updated_at',
        'period',
        'game_clock',
        'clock',
        'home_score',
        'away_score',
        'home_linescores',
        'away_linescores',
        'neutral_site',
        'conference_game',
        'broadcast_networks',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'game_date' => 'datetime',
            'completed_at' => 'datetime',
            'odds_updated_at' => 'datetime',
            'postseason_round' => 'integer',
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

    public function contextSignal(): HasOne
    {
        return $this->hasOne(GameContextSignal::class, 'game_id');
    }

    public function weather(): HasOne
    {
        return $this->hasOne(GameWeather::class, 'game_id');
    }
}
