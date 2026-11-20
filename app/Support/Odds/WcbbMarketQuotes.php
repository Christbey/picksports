<?php

namespace App\Support\Odds;

use Illuminate\Support\Str;

class WcbbMarketQuotes
{
    /** @return array{home: array, away: array, bookmaker: ?string}|null */
    public function firstTeamMarket(object $game, string $key): ?array
    {
        foreach ($game->odds_data['bookmakers'] ?? [] as $bookmaker) {
            foreach ($bookmaker['markets'] ?? [] as $market) {
                if (($market['key'] ?? null) === $key && ($quotes = $this->teams($game, $market, $key)) !== null) {
                    return $quotes + ['bookmaker' => $bookmaker['key'] ?? $bookmaker['title'] ?? null];
                }
            }
        }

        return null;
    }

    /** @return array{home: array, away: array}|null */
    public function teams(object $game, array $market, string $key): ?array
    {
        $home = $this->names($game->homeTeam, data_get($game->odds_data, 'home_team'));
        $away = $this->names($game->awayTeam, data_get($game->odds_data, 'away_team'));
        $quotes = [];

        foreach ($market['outcomes'] ?? [] as $outcome) {
            $name = $this->normalize((string) ($outcome['name'] ?? ''));
            $isHome = in_array($name, $home, true);
            $isAway = in_array($name, $away, true);
            if ($isHome === $isAway) {
                return null;
            }

            $side = $isHome ? 'home' : 'away';
            if (isset($quotes[$side]) || ! is_numeric($outcome['price'] ?? null)
                || abs((float) $outcome['price']) < 100
                || ($key === 'spreads' && ! is_numeric($outcome['point'] ?? null))) {
                return null;
            }
            $quotes[$side] = $outcome;
        }

        if (! isset($quotes['home'], $quotes['away'])
            || ($key === 'spreads' && abs((float) $quotes['home']['point'] + (float) $quotes['away']['point']) > 0.001)) {
            return null;
        }

        return $quotes;
    }

    private function names(object $team, mixed $providerName): array
    {
        if (is_string($providerName) && trim($providerName) !== '') {
            return [$this->normalize($providerName)];
        }

        $school = (string) ($team->school ?? '');

        return array_values(array_filter(array_map($this->normalize(...), [
            $school,
            trim($school.' '.($team->mascot ?? '')),
            (string) ($team->display_name ?? ''),
            (string) ($team->abbreviation ?? ''),
        ])));
    }

    private function normalize(string $name): string
    {
        return Str::slug($name);
    }
}
