<?php

namespace App\Actions\OddsApi\NBA;

use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Services\OddsApi\OddsApiService;

class SyncOddsForGames
{
    public function __construct(
        protected OddsApiService $oddsApiService
    ) {}

    public function execute(?int $daysAhead = 7): int
    {
        $oddsData = $this->oddsApiService->getNbaOdds();

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

        $espnHome = $this->normalizeTeamName($game->homeTeam->school ?? '');
        $espnAway = $this->normalizeTeamName($game->awayTeam->school ?? '');

        // Try exact match first
        if ($espnHome === $oddsHome && $espnAway === $oddsAway) {
            return true;
        }

        // Try matching with city/location names (NBA teams use city names)
        $homeLocation = $this->normalizeTeamName($game->homeTeam->location ?? '');
        $awayLocation = $this->normalizeTeamName($game->awayTeam->location ?? '');

        if ($this->containsMatch($oddsHome, [$espnHome, $homeLocation]) &&
            $this->containsMatch($oddsAway, [$espnAway, $awayLocation])) {
            return true;
        }

        // Fuzzy match
        similar_text($espnHome, $oddsHome, $homePercent);
        similar_text($espnAway, $oddsAway, $awayPercent);

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

        // Normalize LA team variations
        $name = str_replace('los angeles', 'la', $name);

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
