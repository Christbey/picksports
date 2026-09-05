<?php

namespace App\Models\NFL;

use App\Models\SportEvent;
use Database\Factories\NflGameFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Game extends Model
{
    /** @use HasFactory<NflGameFactory> */
    use HasFactory;

    protected $table = 'nfl_games';

    protected $fillable = [
        'sport_event_id',
        'espn_event_id',
        'espn_uid',
        'nflverse_game_id',
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
        'home_qb_id',
        'home_qb_name',
        'away_qb_id',
        'away_qb_name',
        'home_coach',
        'away_coach',
        'home_linescores',
        'away_linescores',
        'broadcast_networks',
        'completed_at',
        'stadium_id',
        'roof',
        'surface',
        'home_rest',
        'away_rest',
        'division_game',
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
            'division_game' => 'boolean',
        ];
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function sportEvent(): BelongsTo
    {
        return $this->belongsTo(SportEvent::class);
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

    public function weather(): HasOne
    {
        return $this->hasOne(GameWeather::class, 'game_id');
    }

    public function playerProps(): HasMany
    {
        return $this->hasMany(PlayerProp::class, 'game_id');
    }

    protected static function newFactory(): NflGameFactory
    {
        return NflGameFactory::new();
    }
}
