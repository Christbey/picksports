<?php

namespace App\Models\NBA;

use App\Models\SportEvent;
use Database\Factories\NbaGameFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Game extends Model
{
    /** @use HasFactory<NbaGameFactory> */
    use HasFactory;

    protected $table = 'nba_games';

    private const SCHEDULED_STATUSES = ['STATUS_SCHEDULED', 'scheduled'];

    private const PLAYOFF_SERIES_WINS_TO_ADVANCE = 4;

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

    public function playerProps(): HasMany
    {
        return $this->hasMany(PlayerProp::class, 'game_id');
    }

    protected static function newFactory(): NbaGameFactory
    {
        return NbaGameFactory::new();
    }

    public function scopeWithoutCompletedPlayoffSeriesPlaceholders(Builder $query, string $table = 'nba_games'): Builder
    {
        return $query->where(function (Builder $query) use ($table): void {
            $query
                ->whereNotIn("{$table}.status", self::SCHEDULED_STATUSES)
                ->orWhereNull("{$table}.status")
                ->orWhereNotIn("{$table}.season_type", self::postseasonTypeCandidates())
                ->orWhereNull("{$table}.season_type")
                ->orWhereNotExists(function ($subQuery) use ($table): void {
                    $subQuery
                        ->selectRaw('1')
                        ->from('nba_games as completed_series_games')
                        ->whereColumn('completed_series_games.season', "{$table}.season")
                        ->whereIn('completed_series_games.season_type', self::postseasonTypeCandidates())
                        ->where('completed_series_games.status', 'STATUS_FINAL')
                        ->whereColumn('completed_series_games.game_date', '<', "{$table}.game_date")
                        ->whereNotNull('completed_series_games.home_score')
                        ->whereNotNull('completed_series_games.away_score')
                        ->where(function ($sameSeriesQuery) use ($table): void {
                            $sameSeriesQuery
                                ->where(function ($sameHomeAwayQuery) use ($table): void {
                                    $sameHomeAwayQuery
                                        ->whereColumn('completed_series_games.home_team_id', "{$table}.home_team_id")
                                        ->whereColumn('completed_series_games.away_team_id', "{$table}.away_team_id");
                                })
                                ->orWhere(function ($swappedHomeAwayQuery) use ($table): void {
                                    $swappedHomeAwayQuery
                                        ->whereColumn('completed_series_games.home_team_id', "{$table}.away_team_id")
                                        ->whereColumn('completed_series_games.away_team_id', "{$table}.home_team_id");
                                });
                        })
                        ->groupBy('completed_series_games.season')
                        ->havingRaw($this->completedSeriesWinnerHavingSql($table), [
                            self::PLAYOFF_SERIES_WINS_TO_ADVANCE,
                            self::PLAYOFF_SERIES_WINS_TO_ADVANCE,
                        ]);
                });
        });
    }

    private function completedSeriesWinnerHavingSql(string $table): string
    {
        return <<<SQL
SUM(CASE
    WHEN completed_series_games.home_team_id = {$table}.home_team_id
        AND completed_series_games.home_score > completed_series_games.away_score THEN 1
    WHEN completed_series_games.away_team_id = {$table}.home_team_id
        AND completed_series_games.away_score > completed_series_games.home_score THEN 1
    ELSE 0
END) >= ?
OR SUM(CASE
    WHEN completed_series_games.home_team_id = {$table}.away_team_id
        AND completed_series_games.home_score > completed_series_games.away_score THEN 1
    WHEN completed_series_games.away_team_id = {$table}.away_team_id
        AND completed_series_games.away_score > completed_series_games.home_score THEN 1
    ELSE 0
END) >= ?
SQL;
    }

    /**
     * @return array<int, int|string>
     */
    private static function postseasonTypeCandidates(): array
    {
        $postseasonType = config('nba.season.types.postseason', 3);

        return array_values(array_unique([
            $postseasonType,
            (string) $postseasonType,
        ], SORT_REGULAR));
    }
}
