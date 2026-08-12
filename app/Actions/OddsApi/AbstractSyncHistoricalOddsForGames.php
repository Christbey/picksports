<?php

namespace App\Actions\OddsApi;

use App\Services\OddsApi\GameOddsSnapshotRecorder;
use App\Support\SportsViewCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

abstract class AbstractSyncHistoricalOddsForGames extends AbstractSyncOddsForGames
{
    /**
     * @return array{
     *   processed_games:int,
     *   matched_games:int,
     *   created_snapshots:int,
     *   hydrated_current_games:int,
     *   unmatched_games:list<array{game_id:int,game_date:string,game_time:?string,home_team:string,away_team:string,target_timestamp:string}>
     * }
     */
    public function executeHistorical(
        int $hoursBefore = 24,
        ?int $season = null,
        ?string $fromDate = null,
        ?string $toDate = null,
        int $limit = 0,
        ?string $oddsSportKey = null,
        bool $hydrateCurrentWhenEmpty = false
    ): array {
        $effectiveSportKey = $this->effectiveSportKey($oddsSportKey);
        $games = $this->historicalGamesQuery($season, $fromDate, $toDate, $limit)
            ->with(['homeTeam', 'awayTeam'])
            ->get();

        $processedGames = 0;
        $matchedGames = 0;
        $createdSnapshots = 0;
        $hydratedCurrentGames = 0;
        $unmatchedGames = [];
        $historicalResponseCache = [];

        $this->gameOddsSnapshotRecorder ??= app(GameOddsSnapshotRecorder::class);

        foreach ($games as $game) {
            $targetTimestamp = $this->historicalSnapshotTimestamp($game, $hoursBefore);

            if ($targetTimestamp === null || $targetTimestamp->gt(now())) {
                continue;
            }

            $processedGames++;
            $matchedEvent = null;
            $resolvedSnapshotTimestamp = null;

            foreach ($this->candidateSnapshotTimestamps($game, $hoursBefore) as $candidateTimestamp) {
                $cacheKey = $effectiveSportKey.'|'.$candidateTimestamp->toIso8601String();
                $historicalResponse = $historicalResponseCache[$cacheKey]
                    ??= $this->historicalEventsAt($effectiveSportKey, $candidateTimestamp);

                $matchedEvent = $this->matchHistoricalEventForGame(
                    $game,
                    $historicalResponse['events'],
                    $effectiveSportKey
                );

                if ($matchedEvent !== null) {
                    $resolvedSnapshotTimestamp = $historicalResponse['snapshot_timestamp'] ?? $candidateTimestamp;
                    break;
                }
            }

            if ($matchedEvent === null) {
                $unmatchedGames[] = [
                    'game_id' => (int) $game->getKey(),
                    'game_date' => $this->gameDateString($game),
                    'game_time' => $game->game_time ? (string) $game->game_time : null,
                    'home_team' => $this->teamLabel($game->homeTeam),
                    'away_team' => $this->teamLabel($game->awayTeam),
                    'target_timestamp' => $targetTimestamp->toIso8601String(),
                ];

                continue;
            }

            $matchedGames++;
            $extractedOddsData = $this->oddsApiService->extractOddsData($matchedEvent);

            if ($this->gameOddsSnapshotRecorder->record(
                $this->snapshotSportKey(),
                $game,
                $matchedEvent,
                $extractedOddsData,
                $resolvedSnapshotTimestamp ?? $targetTimestamp
            ) !== null) {
                $createdSnapshots++;
            }

            $updatePayload = [];

            if (($game->odds_api_event_id ?? null) !== ($matchedEvent['id'] ?? null)) {
                $updatePayload['odds_api_event_id'] = $matchedEvent['id'] ?? null;
            }

            if ($hydrateCurrentWhenEmpty && $this->shouldHydrateCurrentOdds($game)) {
                $updatePayload['odds_data'] = $extractedOddsData;
                $updatePayload['odds_updated_at'] = $resolvedSnapshotTimestamp ?? $targetTimestamp;
                $hydratedCurrentGames++;
            }

            if ($updatePayload !== []) {
                $game->update($updatePayload);
            }
        }

        if ($hydratedCurrentGames > 0) {
            $this->sportsViewCache->bustSegments([
                SportsViewCache::SEGMENT_DASHBOARD,
                SportsViewCache::SEGMENT_TEAM_GAMES_BY_TEAM,
            ]);
        }

        return [
            'processed_games' => $processedGames,
            'matched_games' => $matchedGames,
            'created_snapshots' => $createdSnapshots,
            'hydrated_current_games' => $hydratedCurrentGames,
            'unmatched_games' => array_slice($unmatchedGames, 0, 20),
        ];
    }

    protected function historicalGamesQuery(
        ?int $season = null,
        ?string $fromDate = null,
        ?string $toDate = null,
        int $limit = 0
    ) {
        $gameModel = $this->gameModelClass();
        $query = $gameModel::query()
            ->whereNotNull('home_team_id')
            ->whereNotNull('away_team_id')
            ->whereNotNull('game_date')
            ->whereNotNull('game_time')
            ->orderBy('game_date')
            ->orderBy('game_time')
            ->orderBy('id');

        if ($season !== null) {
            $query->where('season', $season);
        }

        if ($fromDate !== null) {
            $query->whereDate('game_date', '>=', $fromDate);
        }

        if ($toDate !== null) {
            $query->whereDate('game_date', '<=', $toDate);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query;
    }

    protected function historicalSnapshotTimestamp(Model $game, int $hoursBefore): ?Carbon
    {
        $scheduledTime = $this->gameScheduledTime($game);

        if ($scheduledTime === null) {
            return null;
        }

        return $scheduledTime->copy()->subHours(max(0, $hoursBefore));
    }

    /**
     * @return array{events: array<int, array<string, mixed>>, snapshot_timestamp: ?Carbon}
     */
    protected function historicalEventsAt(string $oddsSportKey, Carbon $targetTimestamp): array
    {
        $response = $this->oddsApiService->getHistoricalOdds(
            sport: $oddsSportKey,
            date: $targetTimestamp->copy()->utc()->format('Y-m-d\TH:i:s\Z')
        );

        if (! is_array($response)) {
            return [
                'events' => [],
                'snapshot_timestamp' => null,
            ];
        }

        $events = $response['data'] ?? $response;
        $snapshotTimestamp = isset($response['timestamp']) && is_string($response['timestamp'])
            ? Carbon::parse($response['timestamp'])
            : null;

        return [
            'events' => is_array($events) ? array_values(array_filter($events, 'is_array')) : [],
            'snapshot_timestamp' => $snapshotTimestamp,
        ];
    }

    /**
     * @return array<int, Carbon>
     */
    protected function candidateSnapshotTimestamps(Model $game, int $hoursBefore): array
    {
        $targetTimestamp = $this->historicalSnapshotTimestamp($game, $hoursBefore);

        if ($targetTimestamp === null) {
            return [];
        }

        return [$targetTimestamp];
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<string, mixed>|null
     */
    protected function matchHistoricalEventForGame(Model $game, array $events, string $oddsSportKey): ?array
    {
        $gameEventId = trim((string) ($game->odds_api_event_id ?? ''));

        if ($gameEventId !== '') {
            foreach ($events as $event) {
                if (($event['id'] ?? null) === $gameEventId) {
                    return $event;
                }
            }
        }

        foreach ($events as $event) {
            if (! isset($event['id'], $event['home_team'], $event['away_team'], $event['commence_time'])) {
                continue;
            }

            $eventTime = $this->eventCommenceTime($event);
            if ($eventTime === null || ! $this->gameFallsWithinEventTimeTolerance($game, $eventTime)) {
                continue;
            }

            if ($this->oddsApiService->fuzzyMatchTeams(
                (string) $event['home_team'],
                (string) $event['away_team'],
                $this->homeTeamNames($game),
                $this->awayTeamNames($game),
                $this->matchThreshold(),
                $oddsSportKey
            )) {
                return $event;
            }
        }

        return null;
    }

    protected function shouldHydrateCurrentOdds(Model $game): bool
    {
        return empty($game->odds_data) || empty($game->odds_updated_at);
    }

    protected function gameDateString(Model $game): string
    {
        $value = $game->game_date;

        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        return Carbon::parse((string) $value)->toDateString();
    }

    protected function teamLabel(?Model $team): string
    {
        if ($team === null) {
            return 'Unknown';
        }

        return trim(implode(' ', array_filter([
            $team->location ?? null,
            $team->name ?? null,
            $team->school ?? null,
            $team->mascot ?? null,
        ]))) ?: (string) ($team->abbreviation ?? 'Unknown');
    }
}
