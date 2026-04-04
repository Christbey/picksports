<?php

namespace App\Actions\ScoresAndOdds\MLB;

use App\Models\MLB\Game;
use App\Services\OddsApi\GameOddsSnapshotRecorder;
use App\Services\OddsApi\OddsApiService;
use App\Services\ScoresAndOdds\MLB\HistoricalOddsScraper;
use App\Support\SportsViewCache;
use Illuminate\Support\Carbon;

class SyncHistoricalOddsForGames
{
    public function __construct(
        private readonly HistoricalOddsScraper $scraper,
        private readonly OddsApiService $oddsApiService,
        private readonly SportsViewCache $sportsViewCache,
        private readonly GameOddsSnapshotRecorder $snapshotRecorder,
    ) {}

    /**
     * @return array{
     *   processed_games:int,
     *   matched_games:int,
     *   created_snapshots:int,
     *   hydrated_current_games:int,
     *   unmatched_games:list<array{game_id:int,game_date:string,game_time:?string,home_team:string,away_team:string}>
     * }
     */
    public function execute(
        ?int $season = null,
        ?string $fromDate = null,
        ?string $toDate = null,
        int $limit = 0,
        bool $hydrateCurrentWhenEmpty = false
    ): array {
        $games = $this->gamesQuery($season, $fromDate, $toDate, $limit)
            ->with(['homeTeam', 'awayTeam'])
            ->get();

        $processedGames = 0;
        $matchedGames = 0;
        $createdSnapshots = 0;
        $hydratedCurrentGames = 0;
        $unmatchedGames = [];
        $eventsByDate = [];
        $detailsByEvent = [];

        foreach ($games as $game) {
            $dateKey = $game->game_date?->toDateString();

            if (! is_string($dateKey) || $dateKey === '') {
                continue;
            }

            $events = $eventsByDate[$dateKey] ??= $this->scraper->fetchDate($dateKey);
            $processedGames++;

            $matchedEvent = $this->matchGameToEvent($game, $events);
            if ($matchedEvent === null) {
                $unmatchedGames[] = [
                    'game_id' => (int) $game->getKey(),
                    'game_date' => $dateKey,
                    'game_time' => $game->game_time ? (string) $game->game_time : null,
                    'home_team' => trim(((string) $game->homeTeam?->location).' '.((string) $game->homeTeam?->name)),
                    'away_team' => trim(((string) $game->awayTeam?->location).' '.((string) $game->awayTeam?->name)),
                ];

                continue;
            }

            $detail = $detailsByEvent[$matchedEvent['id']] ??= $this->scraper->fetchEventDetails((string) $matchedEvent['id']);
            if (! is_array($detail) || ! is_array($detail['odds_data'] ?? null)) {
                $unmatchedGames[] = [
                    'game_id' => (int) $game->getKey(),
                    'game_date' => $dateKey,
                    'game_time' => $game->game_time ? (string) $game->game_time : null,
                    'home_team' => trim(((string) $game->homeTeam?->location).' '.((string) $game->homeTeam?->name)),
                    'away_team' => trim(((string) $game->awayTeam?->location).' '.((string) $game->awayTeam?->name)),
                ];

                continue;
            }

            $matchedGames++;

            if ($this->snapshotRecorder->record(
                'mlb',
                $game,
                [
                    'id' => 'scoresandodds:'.$matchedEvent['id'],
                    'commence_time' => $detail['commence_time'] ?? $matchedEvent['commence_time'] ?? null,
                ],
                $detail['odds_data'],
                $this->capturedAt($detail['commence_time'] ?? $matchedEvent['commence_time'] ?? null),
                'scores_and_odds'
            ) !== null) {
                $createdSnapshots++;
            }

            if ($hydrateCurrentWhenEmpty && empty($game->odds_data)) {
                $game->update([
                    'odds_data' => $detail['odds_data'],
                    'odds_updated_at' => $this->capturedAt($detail['commence_time'] ?? $matchedEvent['commence_time'] ?? null) ?? now(),
                ]);
                $hydratedCurrentGames++;
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

    private function gamesQuery(?int $season = null, ?string $fromDate = null, ?string $toDate = null, int $limit = 0)
    {
        $query = Game::query()
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

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<string, mixed>|null
     */
    private function matchGameToEvent(Game $game, array $events): ?array
    {
        $homeTeam = $this->normalizeTeamName((string) $game->homeTeam?->name);
        $awayTeam = $this->normalizeTeamName((string) $game->awayTeam?->name);
        $scheduledTime = $this->scheduledTime($game);

        foreach ($events as $event) {
            $eventHome = $this->normalizeTeamName((string) ($event['home_team'] ?? ''));
            $eventAway = $this->normalizeTeamName((string) ($event['away_team'] ?? ''));

            if ($eventHome !== $homeTeam || $eventAway !== $awayTeam) {
                continue;
            }

            $eventTime = isset($event['commence_time']) && is_string($event['commence_time'])
                ? Carbon::parse($event['commence_time'])->utc()
                : null;

            if ($scheduledTime !== null && $eventTime !== null && abs($scheduledTime->diffInMinutes($eventTime, false)) > 360) {
                continue;
            }

            return $event;
        }

        return null;
    }

    private function normalizeTeamName(string $name): string
    {
        return $this->oddsApiService->normalizeTeamName($name);
    }

    private function scheduledTime(Game $game): ?Carbon
    {
        if ($game->game_date === null || $game->game_time === null) {
            return null;
        }

        return Carbon::parse(
            sprintf('%s %s', $game->game_date->toDateString(), $game->game_time),
            (string) config('app.timezone', 'UTC')
        )->utc();
    }

    private function capturedAt(?string $commenceTime): ?Carbon
    {
        if (! is_string($commenceTime) || trim($commenceTime) === '') {
            return null;
        }

        return Carbon::parse($commenceTime)->utc();
    }
}
