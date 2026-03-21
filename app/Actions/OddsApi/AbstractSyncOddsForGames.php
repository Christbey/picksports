<?php

namespace App\Actions\OddsApi;

use App\Services\OddsApi\OddsApiService;
use App\Support\SportsViewCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

abstract class AbstractSyncOddsForGames
{
    protected const SPORT_KEY = '';

    protected const GAME_MODEL_CLASS = Model::class;

    protected const MATCH_THRESHOLD = 80.0;

    protected const INCLUDE_ABBREVIATION_IN_TEAM_NAMES = false;

    protected const INCLUDE_LOCATION_AND_NAME_IN_TEAM_NAMES = false;

    protected const INCLUDE_DISPLAY_NAME_IN_TEAM_NAMES = true;

    public function __construct(
        protected OddsApiService $oddsApiService,
        protected SportsViewCache $sportsViewCache
    ) {}

    public function execute(?int $daysAhead = 7, ?string $oddsSportKey = null): int
    {
        $effectiveSportKey = $this->effectiveSportKey($oddsSportKey);
        $oddsData = $this->fetchOddsData($effectiveSportKey);
        $daysAhead = $daysAhead !== null ? max(1, $daysAhead) : null;

        if (! $oddsData) {
            return 0;
        }

        $updated = 0;

        foreach ($oddsData as $event) {
            if (! isset($event['id'], $event['home_team'], $event['away_team'], $event['commence_time'])) {
                continue;
            }

            if (! $this->eventFallsWithinWindow($event, $daysAhead)) {
                continue;
            }

            $game = $this->matchEvent($event, $effectiveSportKey, $daysAhead);

            if (! $game) {
                continue;
            }

            $game->update([
                'odds_api_event_id' => $event['id'],
                'odds_data' => $this->oddsApiService->extractOddsData($event),
                'odds_updated_at' => now(),
            ]);

            $updated++;
        }

        if ($updated > 0) {
            $this->sportsViewCache->bustSegments([
                SportsViewCache::SEGMENT_DASHBOARD,
                SportsViewCache::SEGMENT_TEAM_GAMES_BY_TEAM,
            ]);
        }

        return $updated;
    }

    /**
     * @return array{
     *   sport_key:string,
     *   days_ahead:?int,
     *   local_games:int,
     *   local_games_with_odds:int,
     *   api_events:int,
     *   in_window_events:int,
     *   matched_events:int,
     *   unmatched_events:list<array{event_id:string,commence_date:string,home_team:string,away_team:string}>
     * }
     */
    public function diagnostics(?int $daysAhead = 7, ?string $oddsSportKey = null): array
    {
        $effectiveSportKey = $this->effectiveSportKey($oddsSportKey);
        $daysAhead = $daysAhead !== null ? max(1, $daysAhead) : null;
        $oddsData = $this->fetchOddsData($effectiveSportKey) ?? [];
        $events = collect(is_array($oddsData) ? $oddsData : []);

        $inWindowEvents = $events
            ->filter(fn ($event) => is_array($event))
            ->filter(fn (array $event) => isset($event['id'], $event['home_team'], $event['away_team'], $event['commence_time']))
            ->filter(fn (array $event) => $this->eventFallsWithinWindow($event, $daysAhead))
            ->values();

        $matchedEvents = 0;
        $unmatchedEvents = [];

        foreach ($inWindowEvents as $event) {
            $game = $this->matchEvent($event, $effectiveSportKey, $daysAhead);

            if ($game) {
                $matchedEvents++;

                continue;
            }

            $unmatchedEvents[] = [
                'event_id' => (string) $event['id'],
                'commence_date' => date('Y-m-d H:i', strtotime((string) $event['commence_time'])),
                'home_team' => (string) $event['home_team'],
                'away_team' => (string) $event['away_team'],
            ];
        }

        $localGamesQuery = $this->localGamesQuery($effectiveSportKey, $daysAhead);

        return [
            'sport_key' => $effectiveSportKey,
            'days_ahead' => $daysAhead,
            'local_games' => (clone $localGamesQuery)->count(),
            'local_games_with_odds' => (clone $localGamesQuery)->whereNotNull('odds_updated_at')->count(),
            'api_events' => $events->count(),
            'in_window_events' => $inWindowEvents->count(),
            'matched_events' => $matchedEvents,
            'unmatched_events' => array_slice($unmatchedEvents, 0, 10),
        ];
    }

    protected function matchEvent(array $event, string $oddsSportKey, ?int $daysAhead = null): ?Model
    {
        $gameDate = date('Y-m-d', strtotime($event['commence_time']));
        $query = $this->localGamesQuery($oddsSportKey, $daysAhead)
            ->with(['homeTeam', 'awayTeam'])
            ->whereDate('game_date', $gameDate);

        $games = $query->get();

        foreach ($games as $game) {
            if ($this->oddsApiService->fuzzyMatchTeams(
                $event['home_team'],
                $event['away_team'],
                $this->homeTeamNames($game),
                $this->awayTeamNames($game),
                $this->matchThreshold(),
                $oddsSportKey
            )) {
                return $game;
            }
        }

        return null;
    }

    protected function eventFallsWithinWindow(array $event, ?int $daysAhead): bool
    {
        if ($daysAhead === null) {
            return true;
        }

        $commenceTime = strtotime((string) ($event['commence_time'] ?? ''));
        if ($commenceTime === false) {
            return false;
        }

        $windowStart = now()->startOfDay();
        $windowEnd = now()->copy()->startOfDay()->addDays($daysAhead)->endOfDay();
        $eventTime = now()->setTimestamp($commenceTime);

        return $eventTime->betweenIncluded($windowStart, $windowEnd);
    }

    protected function localGamesQuery(string $oddsSportKey, ?int $daysAhead = null)
    {
        $gameModel = $this->gameModelClass();

        $query = $gameModel::query();

        if ($daysAhead !== null) {
            $query->whereDate('game_date', '>=', now()->startOfDay()->toDateString())
                ->whereDate('game_date', '<=', now()->startOfDay()->addDays($daysAhead)->toDateString())
                ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_IN_PROGRESS', 'STATUS_HALFTIME']);
        }

        $seasonType = $this->seasonTypeForOddsSportKey($oddsSportKey);
        if ($seasonType !== null) {
            $query->where('season_type', $seasonType);
        }

        return $query;
    }

    protected function matchThreshold(): float
    {
        return static::MATCH_THRESHOLD;
    }

    /**
     * @return array<string,mixed>|array<int,array<string,mixed>>|null
     */
    protected function fetchOddsData(?string $oddsSportKey = null): ?array
    {
        return $this->oddsApiService->getOdds(sport: $this->effectiveSportKey($oddsSportKey));
    }

    protected function effectiveSportKey(?string $oddsSportKey): string
    {
        return ($oddsSportKey !== null && $oddsSportKey !== '')
            ? $oddsSportKey
            : $this->sportKey();
    }

    protected function seasonTypeForOddsSportKey(string $oddsSportKey): ?int
    {
        return null;
    }

    protected function sportKey(): string
    {
        if (static::SPORT_KEY === '') {
            throw new \RuntimeException('SPORT_KEY must be defined on odds sync action.');
        }

        return static::SPORT_KEY;
    }

    /**
     * @return class-string<Model>
     */
    protected function gameModelClass(): string
    {
        if (static::GAME_MODEL_CLASS === Model::class) {
            throw new \RuntimeException('GAME_MODEL_CLASS must be defined on odds sync action.');
        }

        return static::GAME_MODEL_CLASS;
    }

    /**
     * @return array<int,string>
     */
    protected function homeTeamNames(Model $game): array
    {
        return $this->locationNameDisplayTeamNames(
            $game->homeTeam,
            includeAbbreviation: $this->includeAbbreviationInTeamNames(),
            includeLocationAndName: $this->includeLocationAndNameInTeamNames(),
            includeDisplayName: $this->includeDisplayNameInTeamNames()
        );
    }

    /**
     * @return array<int,string>
     */
    protected function awayTeamNames(Model $game): array
    {
        return $this->locationNameDisplayTeamNames(
            $game->awayTeam,
            includeAbbreviation: $this->includeAbbreviationInTeamNames(),
            includeLocationAndName: $this->includeLocationAndNameInTeamNames(),
            includeDisplayName: $this->includeDisplayNameInTeamNames()
        );
    }

    protected function includeAbbreviationInTeamNames(): bool
    {
        return static::INCLUDE_ABBREVIATION_IN_TEAM_NAMES;
    }

    protected function includeLocationAndNameInTeamNames(): bool
    {
        return static::INCLUDE_LOCATION_AND_NAME_IN_TEAM_NAMES;
    }

    protected function includeDisplayNameInTeamNames(): bool
    {
        return static::INCLUDE_DISPLAY_NAME_IN_TEAM_NAMES;
    }

    /**
     * @return array<int,string>
     */
    protected function locationNameDisplayTeamNames(
        mixed $team,
        bool $includeAbbreviation = false,
        bool $includeLocationAndName = false,
        bool $includeDisplayName = true
    ): array {
        $names = [
            $team->location ?? '',
            $team->name ?? '',
        ];

        if ($includeDisplayName) {
            $names[] = $team->display_name ?? '';
        }

        if ($includeAbbreviation) {
            $names[] = $team->abbreviation ?? '';
        }

        if ($includeLocationAndName) {
            $names[] = trim(($team->location ?? '').' '.($team->name ?? ''));
        }

        return array_filter($names);
    }

    /**
     * @return array<int,string>
     */
    protected function schoolMascotAbbreviationTeamNames(mixed $team): array
    {
        return array_filter([
            $team->school ?? '',
            $team->mascot ?? '',
            $team->abbreviation ?? '',
        ]);
    }
}
