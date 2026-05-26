<?php

namespace App\Models\MLB;

use Database\Factories\MlbGameFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Game extends Model
{
    /** @use HasFactory<MlbGameFactory> */
    use HasFactory;

    protected $table = 'mlb_games';

    public function playerProps(): HasMany
    {
        return $this->hasMany(PlayerProp::class, 'game_id');
    }

    protected static function newFactory(): MlbGameFactory
    {
        return MlbGameFactory::new();
    }

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
        'inning',
        'inning_half',
        'balls',
        'strikes',
        'outs',
        'probable_home_pitcher_espn_id',
        'probable_away_pitcher_espn_id',
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
            'home_linescores' => 'array',
            'away_linescores' => 'array',
            'broadcast_networks' => 'array',
            'odds_data' => 'array',
            'odds_updated_at' => 'datetime',
        ];
    }

    protected function inningState(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->inning_half,
            set: fn (?string $value): array => ['inning_half' => $value]
        );
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function probableHomePitcher(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'probable_home_pitcher_espn_id', 'espn_id');
    }

    public function probableAwayPitcher(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'probable_away_pitcher_espn_id', 'espn_id');
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
}
