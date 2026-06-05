<?php

namespace App\Support\Api;

class V1ReplacementPathResolver
{
    /**
     * @var array<string, string>
     */
    private const APP_ROUTE_REPLACEMENTS = [
        'alert-preferences' => '/api/v2/alert-preferences',
        'auth' => '/api/v2/auth',
        'cbb-brackets' => '/api/v2/cbb-brackets',
        'groups' => '/api/v2/groups',
        'user-bets' => '/api/v2/user-bets',
    ];

    public function resolve(string $path): string
    {
        $segments = explode('/', trim($path, '/'));
        $prefix = $segments[2] ?? null;

        if ($prefix === null) {
            return '/api/v2';
        }

        $remainder = implode('/', array_slice($segments, 3));

        if (isset(self::APP_ROUTE_REPLACEMENTS[$prefix])) {
            return self::APP_ROUTE_REPLACEMENTS[$prefix].($remainder !== '' ? "/{$remainder}" : '');
        }

        if (array_key_exists($prefix, (array) config('sports.domains', []))) {
            return $this->sportReplacementPath($prefix, array_slice($segments, 3));
        }

        return '/api/v2';
    }

    /**
     * @param  array<int, string>  $segments
     */
    private function sportReplacementPath(string $sport, array $segments): string
    {
        $base = "/api/v2/sports/{$sport}";
        $first = $segments[0] ?? null;

        if ($first === null) {
            return $base;
        }

        if ($first === 'team-metrics') {
            return $this->replacePrefix($base, $segments, 'metrics/teams');
        }

        if ($first === 'team-stats') {
            return $this->replacePrefix($base, $segments, 'stats/team');
        }

        if ($first === 'player-stats') {
            if (($segments[1] ?? null) === 'leaderboard') {
                return "{$base}/leaderboards/players";
            }

            return $this->replacePrefix($base, $segments, 'stats/player');
        }

        if ($first === 'playoff-forecasts' || $first === 'tournament-forecasts') {
            return "{$base}/forecasts";
        }

        if ($first === 'player-props') {
            return $this->replacePrefix($base, $segments, 'markets/player-props');
        }

        if ($first === 'games' && isset($segments[1], $segments[2])) {
            if ($segments[2] === 'team-stats') {
                return "{$base}/stats/team?game_id={$segments[1]}";
            }

            if ($segments[2] === 'player-stats') {
                return "{$base}/stats/player?game_id={$segments[1]}";
            }
        }

        if ($first === 'players' && isset($segments[1], $segments[2]) && $segments[2] === 'stats') {
            return "{$base}/stats/player?player_id={$segments[1]}";
        }

        if ($first === 'teams' && isset($segments[1], $segments[2]) && $segments[2] === 'stats') {
            if (($segments[3] ?? null) === 'season-averages') {
                return "{$base}/teams/{$segments[1]}/stats/season-averages";
            }

            return "{$base}/stats/team?team_id={$segments[1]}";
        }

        return $base.'/'.implode('/', $segments);
    }

    /**
     * @param  array<int, string>  $segments
     */
    private function replacePrefix(string $base, array $segments, string $replacement): string
    {
        $remaining = array_slice($segments, 1);

        return "{$base}/{$replacement}".($remaining !== [] ? '/'.implode('/', $remaining) : '');
    }
}
