<?php

namespace App\Actions\OddsApi\MLB;

use App\Models\MLB\Game;
use App\Services\OddsApi\OddsApiService;

class SyncOddsForGames
{
    public function __construct(
        protected OddsApiService $oddsApiService
    ) {}

    public function execute(?int $daysAhead = 7): int
    {
        $oddsData = $this->oddsApiService->getMlbOdds();

        if (! $oddsData) {
            return 0;
        }

        $updated = 0;

        foreach ($oddsData as $event) {
            if (! isset($event['id'], $event['home_team'], $event['away_team'], $event['commence_time'])) {
                continue;
            }

            $game = $this->matchEvent($event);

            if (! $game) {
                continue;
            }

            $oddsDataForGame = $this->extractOddsData($event);

            $game->update([
                'odds_api_event_id' => $event['id'],
                'odds_data' => $oddsDataForGame,
                'odds_updated_at' => now(),
            ]);

            $updated++;
        }

        return $updated;
    }

    protected function matchEvent(array $event): ?Game
    {
        $commenceTime = $event['commence_time'];
        $gameDate = date('Y-m-d', strtotime($commenceTime));

        $games = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->whereDate('game_date', $gameDate)
            ->get();

        foreach ($games as $game) {
            if ($this->teamsMatch($game, $event)) {
                return $game;
            }
        }

        return null;
    }

    protected function teamsMatch(Game $game, array $event): bool
    {
        $oddsHome = $this->normalizeTeamName($event['home_team']);
        $oddsAway = $this->normalizeTeamName($event['away_team']);

        // MLB teams have location and name fields (e.g., "New York" "Yankees")
        $espnHomeLocation = $this->normalizeTeamName($game->homeTeam->location ?? '');
        $espnHomeName = $this->normalizeTeamName($game->homeTeam->name ?? '');
        $espnAwayLocation = $this->normalizeTeamName($game->awayTeam->location ?? '');
        $espnAwayName = $this->normalizeTeamName($game->awayTeam->name ?? '');

        // Combine location + name for full team name match
        $espnHomeFull = trim($espnHomeLocation.' '.$espnHomeName);
        $espnAwayFull = trim($espnAwayLocation.' '.$espnAwayName);

        // Try exact match first
        if ($espnHomeFull === $oddsHome && $espnAwayFull === $oddsAway) {
            return true;
        }

        // Try matching with just location (e.g., "New York" matches "New York Yankees")
        if ($this->containsMatch($oddsHome, [$espnHomeLocation, $espnHomeName, $espnHomeFull]) &&
            $this->containsMatch($oddsAway, [$espnAwayLocation, $espnAwayName, $espnAwayFull])) {
            return true;
        }

        // Fuzzy match on full name
        similar_text($espnHomeFull, $oddsHome, $homePercent);
        similar_text($espnAwayFull, $oddsAway, $awayPercent);

        return $homePercent >= 80 && $awayPercent >= 80;
    }

    protected function containsMatch(string $oddsName, array $espnNames): bool
    {
        foreach ($espnNames as $name) {
            if (empty($name)) {
                continue;
            }
            if (str_contains($oddsName, $name) || str_contains($name, $oddsName)) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeTeamName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/\s+/', ' ', $name);

        return $name;
    }

    protected function extractOddsData(array $event): array
    {
        $data = [
            'event_id' => $event['id'],
            'commence_time' => $event['commence_time'],
            'home_team' => $event['home_team'],
            'away_team' => $event['away_team'],
            'bookmakers' => [],
        ];

        if (isset($event['bookmakers'])) {
            foreach ($event['bookmakers'] as $bookmaker) {
                if ($bookmaker['key'] !== 'draftkings') {
                    continue;
                }

                $bookmakerData = [
                    'key' => $bookmaker['key'],
                    'title' => $bookmaker['title'],
                    'markets' => [],
                ];

                foreach ($bookmaker['markets'] as $market) {
                    if (! in_array($market['key'], ['h2h', 'spreads', 'totals'])) {
                        continue;
                    }

                    $marketData = [
                        'key' => $market['key'],
                        'outcomes' => [],
                    ];

                    foreach ($market['outcomes'] as $outcome) {
                        $outcomeData = [
                            'name' => $outcome['name'],
                            'price' => $outcome['price'],
                        ];

                        if (isset($outcome['point'])) {
                            $outcomeData['point'] = $outcome['point'];
                        }

                        $marketData['outcomes'][] = $outcomeData;
                    }

                    $bookmakerData['markets'][] = $marketData;
                }

                $data['bookmakers'][] = $bookmakerData;
            }
        }

        return $data;
    }
}
