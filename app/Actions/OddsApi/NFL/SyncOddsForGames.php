<?php

namespace App\Actions\OddsApi\NFL;

use App\Models\NFL\Game;
use App\Models\OddsApiTeamMapping;
use App\Services\OddsApi\OddsApiService;

class SyncOddsForGames
{
    protected string $sport = 'americanfootball_nfl';

    public function __construct(
        protected OddsApiService $oddsApiService
    ) {}

    public function execute(?int $daysAhead = 7): int
    {
        $oddsData = $this->oddsApiService->getNflOdds();

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
            // Try explicit mapping first
            $homeMapping = OddsApiTeamMapping::query()
                ->where('espn_team_name', $game->homeTeam->display_name)
                ->where('sport', $this->sport)
                ->first();

            $awayMapping = OddsApiTeamMapping::query()
                ->where('espn_team_name', $game->awayTeam->display_name)
                ->where('sport', $this->sport)
                ->first();

            if ($homeMapping && $awayMapping) {
                $oddsHomeTeam = $this->normalizeTeamName($event['home_team']);
                $oddsAwayTeam = $this->normalizeTeamName($event['away_team']);
                $mappedHomeTeam = $this->normalizeTeamName($homeMapping->odds_api_team_name);
                $mappedAwayTeam = $this->normalizeTeamName($awayMapping->odds_api_team_name);

                if ($oddsHomeTeam === $mappedHomeTeam && $oddsAwayTeam === $mappedAwayTeam) {
                    return $game;
                }
            }

            // Fall back to fuzzy matching
            if ($this->fuzzyMatchTeams($game, $event)) {
                return $game;
            }
        }

        return null;
    }

    protected function fuzzyMatchTeams(Game $game, array $event): bool
    {
        $oddsHome = $this->normalizeTeamName($event['home_team']);
        $oddsAway = $this->normalizeTeamName($event['away_team']);

        // NFL teams use location + name (e.g., "Kansas City Chiefs")
        $espnHomeLocation = $this->normalizeTeamName($game->homeTeam->location ?? '');
        $espnAwayLocation = $this->normalizeTeamName($game->awayTeam->location ?? '');
        $espnHomeName = $this->normalizeTeamName($game->homeTeam->name ?? '');
        $espnAwayName = $this->normalizeTeamName($game->awayTeam->name ?? '');
        $espnHomeDisplay = $this->normalizeTeamName($game->homeTeam->display_name ?? '');
        $espnAwayDisplay = $this->normalizeTeamName($game->awayTeam->display_name ?? '');

        // Try exact display name match first
        if ($espnHomeDisplay === $oddsHome && $espnAwayDisplay === $oddsAway) {
            return true;
        }

        // Try matching with location/city names
        if ($this->containsMatch($oddsHome, [$espnHomeName, $espnHomeLocation, $espnHomeDisplay]) &&
            $this->containsMatch($oddsAway, [$espnAwayName, $espnAwayLocation, $espnAwayDisplay])) {
            return true;
        }

        // Fuzzy match on display names
        similar_text($espnHomeDisplay, $oddsHome, $homePercent);
        similar_text($espnAwayDisplay, $oddsAway, $awayPercent);

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
