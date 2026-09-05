<?php

namespace App\Models\MLB;

use App\Models\SportEvent;
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
        'sport_event_id',
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
        'actual_home_pitcher_espn_id',
        'actual_away_pitcher_espn_id',
        'projected_home_pitcher_espn_id',
        'projected_away_pitcher_espn_id',
        'projected_home_pitcher_confidence',
        'projected_away_pitcher_confidence',
        'pitcher_projection_metadata',
        'pitcher_projection_generated_at',
        'starting_pitcher_confirmation_metadata',
        'starting_pitchers_confirmed_at',
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
            'projected_home_pitcher_confidence' => 'float',
            'projected_away_pitcher_confidence' => 'float',
            'pitcher_projection_metadata' => 'array',
            'pitcher_projection_generated_at' => 'datetime',
            'starting_pitcher_confirmation_metadata' => 'array',
            'starting_pitchers_confirmed_at' => 'datetime',
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

    public function sportEvent(): BelongsTo
    {
        return $this->belongsTo(SportEvent::class);
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

    public function actualHomePitcher(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'actual_home_pitcher_espn_id', 'espn_id');
    }

    public function actualAwayPitcher(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'actual_away_pitcher_espn_id', 'espn_id');
    }

    public function projectedHomePitcher(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'projected_home_pitcher_espn_id', 'espn_id');
    }

    public function projectedAwayPitcher(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'projected_away_pitcher_espn_id', 'espn_id');
    }

    public function resolvedStartingPitcherEspnId(string $side): ?string
    {
        $actual = trim((string) ($side === 'home'
            ? $this->actual_home_pitcher_espn_id
            : $this->actual_away_pitcher_espn_id));

        if ($actual !== '') {
            return $actual;
        }

        $probable = trim((string) ($side === 'home'
            ? $this->probable_home_pitcher_espn_id
            : $this->probable_away_pitcher_espn_id));

        if ($probable !== '') {
            return $probable;
        }

        $projected = trim((string) ($side === 'home'
            ? $this->projected_home_pitcher_espn_id
            : $this->projected_away_pitcher_espn_id));

        return $projected !== '' ? $projected : null;
    }

    public function startingPitcherSource(string $side): ?string
    {
        $actual = $side === 'home'
            ? $this->actual_home_pitcher_espn_id
            : $this->actual_away_pitcher_espn_id;

        if (filled($actual)) {
            return 'espn_boxscore_confirmed';
        }

        $probable = $side === 'home'
            ? $this->probable_home_pitcher_espn_id
            : $this->probable_away_pitcher_espn_id;

        if (filled($probable)) {
            return 'espn_probable';
        }

        return filled($this->resolvedStartingPitcherEspnId($side))
            ? 'rotation_projection'
            : null;
    }

    public function startingPitcherConfidence(string $side): ?float
    {
        $source = $this->startingPitcherSource($side);

        if ($source === 'espn_boxscore_confirmed') {
            return 1.0;
        }

        if ($source === 'espn_probable') {
            return (float) config('mlb.starter_projection.probable_confidence', 0.90);
        }

        $confidence = $side === 'home'
            ? $this->projected_home_pitcher_confidence
            : $this->projected_away_pitcher_confidence;

        return $confidence !== null ? (float) $confidence : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function startingPitcherCandidates(string $side): array
    {
        $candidates = data_get($this->pitcher_projection_metadata, "{$side}.candidates", []);

        return is_array($candidates) ? array_values($candidates) : [];
    }

    public function expectedStartingPitcherRating(string $side): ?float
    {
        $rating = data_get($this->pitcher_projection_metadata, "{$side}.expected_pitcher_rating");

        return is_numeric($rating) ? (float) $rating : null;
    }

    public function startingPitcherUncertainty(string $side): ?float
    {
        $uncertainty = data_get($this->pitcher_projection_metadata, "{$side}.uncertainty");

        return is_numeric($uncertainty) ? (float) $uncertainty : null;
    }

    public function hasResolvedStartingPitchers(): bool
    {
        return $this->resolvedStartingPitcherEspnId('home') !== null
            && $this->resolvedStartingPitcherEspnId('away') !== null;
    }

    public function startingPitcherForecasts(): HasMany
    {
        return $this->hasMany(StartingPitcherForecast::class);
    }

    public function homeStartingPitcherForecast(): HasOne
    {
        return $this->hasOne(StartingPitcherForecast::class)
            ->ofMany(
                ['forecasted_at' => 'max', 'id' => 'max'],
                fn ($query) => $query->where('side', 'home'),
            );
    }

    public function awayStartingPitcherForecast(): HasOne
    {
        return $this->hasOne(StartingPitcherForecast::class)
            ->ofMany(
                ['forecasted_at' => 'max', 'id' => 'max'],
                fn ($query) => $query->where('side', 'away'),
            );
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
