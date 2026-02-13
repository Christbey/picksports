<?php

namespace App\Actions\OddsApi\CBB;

use App\Models\CBB\Game;
use App\Models\OddsApiTeamMapping;
use App\Services\OddsApi\OddsApiService;

class SyncOddsForGames
{
    public function __construct(
        protected OddsApiService $oddsApiService
    ) {}

    public function execute(?int $daysAhead = 7): int
    {
        $oddsData = $this->oddsApiService->getOdds();

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
            $homeMapping = OddsApiTeamMapping::query()
                ->where('espn_team_name', $game->homeTeam->school)
                ->where('sport', 'basketball_ncaab')
                ->first();

            $awayMapping = OddsApiTeamMapping::query()
                ->where('espn_team_name', $game->awayTeam->school)
                ->where('sport', 'basketball_ncaab')
                ->first();

            if ($homeMapping && $awayMapping) {
                $oddsHomeTeam = $this->stripMascot($event['home_team']);
                $oddsAwayTeam = $this->stripMascot($event['away_team']);
                $mappedHomeTeam = $this->stripMascot($homeMapping->odds_api_team_name);
                $mappedAwayTeam = $this->stripMascot($awayMapping->odds_api_team_name);

                if ($this->teamsMatch($oddsHomeTeam, $mappedHomeTeam) &&
                    $this->teamsMatch($oddsAwayTeam, $mappedAwayTeam)) {
                    return $game;
                }
            }

            if ($this->fuzzyMatchTeams($game, $event)) {
                return $game;
            }
        }

        return null;
    }

    protected function stripMascot(string $name): string
    {
        $commonMascots = [
            'nittany lions', 'blue devils', 'crimson tide', 'demon deacons', 'fighting illini',
            'golden eagles', 'golden gophers', 'great danes', 'ragin cajuns', 'red raiders',
            'running rebels', 'scarlet knights', 'sun devils', 'tar heels',
            'aggies', 'aztecs', 'badgers', 'bears', 'bearcats', 'beavers', 'blazers',
            'bobcats', 'boilermakers', 'broncos', 'bruins', 'buckeyes', 'bulldogs', 'cardinals',
            'catamounts', 'cavaliers', 'chanticleers', 'colonels', 'commodores', 'cougars', 'cowboys',
            'crusaders', 'cyclones', 'dolphins', 'dons', 'ducks',
            'eagles', 'falcons', 'gamecocks', 'gators', 'grizzlies', 'hawkeyes', 'hokies', 'hoosiers',
            'hornets', 'huskies', 'hurricanes', 'jaguars', 'jayhawks', 'knights', 'lakers',
            'lions', 'lobos', 'longhorns', 'minutemen', 'mountaineers', 'mustangs',
            'orangemen', 'ospreys', 'owls', 'panthers', 'patriots', 'pioneers', 'pirates',
            'raiders', 'rams', 'ramblers', 'razorbacks', 'rebels', 'retrievers', 'rockets',
            'seahawks', 'seminoles', 'sooners', 'spartans', 'stags', 'terrapins', 'terriers',
            'tigers', 'titans', 'tribe', 'trojans', 'utes', 'vandals', 'volunteers', 'wildcats',
            'wolfpack', 'wolverines', 'wolves', 'nittany',
        ];

        $name = strtolower(trim($name));

        usort($commonMascots, fn ($a, $b) => strlen($b) - strlen($a));

        foreach ($commonMascots as $mascot) {
            $name = preg_replace('/\s+'.preg_quote($mascot, '/').'$/i', '', $name);
        }

        return trim($name);
    }

    protected function teamsMatch(string $team1, string $team2): bool
    {
        $team1 = strtolower(trim($team1));
        $team2 = strtolower(trim($team2));

        return $team1 === $team2;
    }

    protected function fuzzyMatchTeams(Game $game, array $event): bool
    {
        $oddsHome = $this->stripMascot($event['home_team']);
        $oddsAway = $this->stripMascot($event['away_team']);

        $espnHomeSchool = $game->homeTeam->school ?? '';
        $espnAwaySchool = $game->awayTeam->school ?? '';
        $espnHomeMascot = $game->homeTeam->mascot ?? '';
        $espnAwayMascot = $game->awayTeam->mascot ?? '';
        $espnHomeAbbr = $game->homeTeam->abbreviation ?? '';
        $espnAwayAbbr = $game->awayTeam->abbreviation ?? '';

        $homeNormalized = $this->normalizeTeamName($espnHomeSchool);
        $awayNormalized = $this->normalizeTeamName($espnAwaySchool);
        $oddsHomeNormalized = $this->normalizeTeamName($oddsHome);
        $oddsAwayNormalized = $this->normalizeTeamName($oddsAway);

        if ($this->teamsMatch($homeNormalized, $oddsHomeNormalized) &&
            $this->teamsMatch($awayNormalized, $oddsAwayNormalized)) {
            return true;
        }

        if ($this->teamsMatch(strtolower($espnHomeAbbr), $oddsHomeNormalized) &&
            $this->teamsMatch(strtolower($espnAwayAbbr), $oddsAwayNormalized)) {
            return true;
        }

        similar_text($homeNormalized, $oddsHomeNormalized, $homePercent);
        similar_text($awayNormalized, $oddsAwayNormalized, $awayPercent);

        if ($homePercent >= 85 && $awayPercent >= 85) {
            if ($this->hasConflictingDirectionalWords($homeNormalized, $oddsHomeNormalized) ||
                $this->hasConflictingDirectionalWords($awayNormalized, $oddsAwayNormalized)) {
                return false;
            }

            return true;
        }

        return false;
    }

    protected function hasConflictingDirectionalWords(string $name1, string $name2): bool
    {
        $directionalPairs = [
            ['north', 'south'],
            ['east', 'west'],
            ['eastern', 'western'],
            ['northern', 'southern'],
        ];

        foreach ($directionalPairs as [$dir1, $dir2]) {
            if ((str_contains($name1, $dir1) && str_contains($name2, $dir2)) ||
                (str_contains($name1, $dir2) && str_contains($name2, $dir1))) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeTeamName(string $name): string
    {
        $name = strtolower($name);
        $name = str_replace(['university', 'college', '&'], '', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        return trim($name);
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
