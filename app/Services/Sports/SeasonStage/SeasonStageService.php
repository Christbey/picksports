<?php

namespace App\Services\Sports\SeasonStage;

use App\Services\Sports\DateWindow;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SeasonStageService
{
    private const ACTIVE_STATUSES = [
        'STATUS_SCHEDULED',
        'STATUS_PRE_GAME',
        'STATUS_DELAYED',
        'STATUS_IN_PROGRESS',
        'STATUS_HALFTIME',
        'STATUS_END_PERIOD',
        'scheduled',
        'in_progress',
    ];

    public function __construct(private readonly SportsDateWindowService $dates) {}

    public function context(string $sport, int|string|null $season = null, CarbonInterface|string|null $asOf = null, ?int $windowDays = null): SeasonStageContext
    {
        $sport = strtolower($sport);
        $profile = config("validation.sports.{$sport}", []);
        $gameModel = data_get($profile, 'models.game');
        $season = $season !== null && $season !== '' ? (int) $season : $this->defaultSeason($sport, $asOf);
        $windowDays ??= (int) data_get($profile, 'window_days', config('validation.window_days', 7));
        $asOfDate = $asOf instanceof CarbonInterface
            ? CarbonImmutable::instance($asOf)->setTimezone($this->dates->timezone())
            : CarbonImmutable::parse($asOf ?: 'now', $this->dates->timezone());
        $activeWindow = $this->dates->forwardWindow($asOfDate, $windowDays);

        if (! is_string($gameModel) || ! class_exists($gameModel)) {
            return new SeasonStageContext(
                sport: $sport,
                season: $season,
                asOf: $asOfDate,
                stage: 'unknown',
                stageGroup: 'unknown',
                activeWindow: $activeWindow,
                activeGameIds: [],
                visibleGameIds: [],
                dataExpectations: $this->dataExpectations($sport, 'unknown', 0),
            );
        }

        /** @var class-string<Model> $gameModel */
        $visibleGames = $this->visibleGameQuery($gameModel, $season, $activeWindow)
            ->get($this->gameColumns($gameModel));
        $activeGames = $visibleGames
            ->filter(fn (Model $game): bool => in_array((string) $game->getAttribute('status'), self::ACTIVE_STATUSES, true))
            ->values();
        $stage = $this->resolveStage($sport, $season, $asOfDate, $gameModel, $activeGames);
        $remainingTeamIds = $this->remainingTeamIds($activeGames);

        return new SeasonStageContext(
            sport: $sport,
            season: $season,
            asOf: $asOfDate,
            stage: $stage['stage'],
            stageGroup: $stage['group'],
            activeWindow: $activeWindow,
            activeGameIds: $activeGames->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            visibleGameIds: $visibleGames->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            remainingTeamIds: $remainingTeamIds,
            seriesContext: $stage['series_context'],
            dataExpectations: $this->dataExpectations($sport, $stage['group'], $activeGames->count()),
        );
    }

    public function applyActiveGameScope(Builder $query, string $sport, int|string|null $season = null, CarbonInterface|string|null $asOf = null, ?int $windowDays = null): Builder
    {
        $context = $this->context($sport, $season, $asOf, $windowDays);

        if ($context->activeGameIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($query->getModel()->getTable().'.id', $context->activeGameIds);
    }

    public function applyVisibleGameScope(Builder $query, string $sport, int|string|null $season = null, CarbonInterface|string|null $asOf = null, ?int $windowDays = null): Builder
    {
        $context = $this->context($sport, $season, $asOf, $windowDays);

        if ($context->visibleGameIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($query->getModel()->getTable().'.id', $context->visibleGameIds);
    }

    /**
     * @param  class-string<Model>  $gameModel
     */
    private function visibleGameQuery(string $gameModel, ?int $season, DateWindow $window): Builder
    {
        $query = $gameModel::query()->orderBy('game_date')->orderBy('id');

        if ($season !== null) {
            $query->where('season', $season);
        }

        $this->dates->applyGameDateWindow($query, $window);

        if (method_exists($gameModel, 'scopeWithoutCompletedPlayoffSeriesPlaceholders')) {
            $query->withoutCompletedPlayoffSeriesPlaceholders();
        }

        return $query;
    }

    /**
     * @param  class-string<Model>  $gameModel
     * @param  Collection<int, Model>  $activeGames
     * @return array{stage:string,group:string,series_context:array<string, mixed>}
     */
    private function resolveStage(string $sport, ?int $season, CarbonImmutable $asOf, string $gameModel, Collection $activeGames): array
    {
        $nearbyGames = $gameModel::query()
            ->when($season !== null, fn (Builder $query) => $query->where('season', $season))
            ->whereDate('game_date', '>=', $asOf->subDays(45)->toDateString())
            ->whereDate('game_date', '<=', $asOf->addDays(45)->toDateString())
            ->get($this->gameColumns($gameModel));

        if ($this->isChampionshipStage($sport, $nearbyGames, $activeGames)) {
            return [
                'stage' => $this->championshipLabel($sport),
                'group' => 'championship',
                'series_context' => $this->seriesContext($nearbyGames, $activeGames),
            ];
        }

        if ($this->hasPostseasonGames($sport, $nearbyGames)) {
            return [
                'stage' => $this->postseasonLabel($sport, $nearbyGames),
                'group' => 'postseason',
                'series_context' => $this->seriesContext($nearbyGames, $activeGames),
            ];
        }

        $activeMonths = (array) config("validation.sports.{$sport}.active_months", []);
        if ($activeMonths !== [] && ! in_array((int) $asOf->month, array_map('intval', $activeMonths), true)) {
            return ['stage' => 'offseason', 'group' => 'offseason', 'series_context' => []];
        }

        return [
            'stage' => $activeGames->isEmpty() ? 'unknown' : 'regular_season',
            'group' => $activeGames->isEmpty() ? 'unknown' : 'regular_season',
            'series_context' => [],
        ];
    }

    /**
     * @param  Collection<int, Model>  $nearbyGames
     * @param  Collection<int, Model>  $activeGames
     */
    private function isChampionshipStage(string $sport, Collection $nearbyGames, Collection $activeGames): bool
    {
        if ($activeGames->isEmpty() || ! in_array($sport, ['nba', 'wnba', 'mlb', 'nfl', 'cbb', 'wcbb', 'cfb'], true)) {
            return false;
        }

        $activeTeams = $this->remainingTeamIds($activeGames);
        if (count($activeTeams) === 2 && $this->hasPostseasonGames($sport, $nearbyGames)) {
            return true;
        }

        if ($sport === 'nfl' && $activeGames->count() === 1 && $this->hasPostseasonGames($sport, $activeGames)) {
            return true;
        }

        return false;
    }

    /**
     * @param  Collection<int, Model>  $games
     */
    private function hasPostseasonGames(string $sport, Collection $games): bool
    {
        if ($games->isEmpty()) {
            return false;
        }

        return $games->contains(function (Model $game) use ($sport): bool {
            if ($sport === 'cbb' && (bool) $game->getAttribute('is_ncaa_tournament')) {
                return true;
            }

            if ($sport === 'cfb' && $game->getAttribute('postseason_round') !== null) {
                return true;
            }

            $seasonType = $game->getAttribute('season_type');

            return in_array((string) $seasonType, array_map('strval', $this->postseasonTypeCandidates($sport)), true);
        });
    }

    /**
     * @param  Collection<int, Model>  $games
     */
    private function postseasonLabel(string $sport, Collection $games): string
    {
        if (in_array($sport, ['cbb', 'wcbb'], true)) {
            return 'tournament';
        }

        if ($sport === 'cfb') {
            return 'playoff_or_bowls';
        }

        return 'postseason';
    }

    private function championshipLabel(string $sport): string
    {
        return match ($sport) {
            'nba', 'wnba' => 'finals',
            'mlb' => 'world_series',
            'nfl' => 'super_bowl',
            'cbb', 'wcbb', 'cfb' => 'championship',
            default => 'championship',
        };
    }

    /**
     * @param  Collection<int, Model>  $games
     * @return array<int, int>
     */
    private function remainingTeamIds(Collection $games): array
    {
        return $games
            ->flatMap(fn (Model $game): array => [
                $game->getAttribute('home_team_id'),
                $game->getAttribute('away_team_id'),
            ])
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Model>  $nearbyGames
     * @param  Collection<int, Model>  $activeGames
     * @return array<string, mixed>
     */
    private function seriesContext(Collection $nearbyGames, Collection $activeGames): array
    {
        $remainingTeamIds = $this->remainingTeamIds($activeGames);

        return [
            'remaining_team_ids' => $remainingTeamIds,
            'active_games' => $activeGames->count(),
            'recent_final_games' => $nearbyGames
                ->where('status', 'STATUS_FINAL')
                ->count(),
        ];
    }

    /**
     * @return array<int, int|string>
     */
    private function postseasonTypeCandidates(string $sport): array
    {
        $configured = config("{$sport}.season.types.postseason", 3);

        return array_values(array_unique([$configured, (string) $configured, 3, '3'], SORT_REGULAR));
    }

    /**
     * @return array<int, string>
     */
    private function gameColumns(string $gameModel): array
    {
        $table = (new $gameModel)->getTable();
        $columns = [
            'id',
            'season',
            'season_type',
            'week',
            'game_date',
            'game_time',
            'status',
            'home_team_id',
            'away_team_id',
            'home_score',
            'away_score',
            'short_name',
            'name',
            'is_ncaa_tournament',
            'postseason_round',
        ];

        return array_values(array_filter(
            array_unique($columns),
            fn (string $column): bool => Schema::hasColumn($table, $column)
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function dataExpectations(string $sport, string $stageGroup, int $activeGameCount): array
    {
        return [
            'active_games' => $activeGameCount,
            'expect_full_league_slate' => $stageGroup === 'regular_season',
            'expect_futures_for_remaining_teams_only' => $stageGroup === 'championship',
            'expect_player_props' => in_array($sport, ['mlb', 'nba', 'nfl', 'cbb', 'wnba'], true) && $activeGameCount > 0,
            'expect_weather' => in_array($sport, ['mlb', 'nfl'], true) && $activeGameCount > 0,
            'validation_mode' => $stageGroup === 'unknown' ? 'conservative_warning' : 'stage_aware',
        ];
    }

    private function defaultSeason(string $sport, CarbonInterface|string|null $asOf): int
    {
        $date = $asOf instanceof CarbonInterface
            ? CarbonImmutable::instance($asOf)
            : CarbonImmutable::parse($asOf ?: 'now', $this->dates->timezone());

        return in_array($sport, ['mlb', 'wnba'], true)
            ? (int) $date->year
            : (int) ($date->month <= 2 ? $date->year - 1 : $date->year);
    }
}
