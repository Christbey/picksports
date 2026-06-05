<?php

namespace App\Support\Api;

class V1ReplacementPathResolver
{
    /**
     * @var array<string, string>
     */
    private const APP_ROUTE_REPLACEMENTS = [
        'alert-preferences' => '/api/v2/alert-preferences',
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
            return "/api/v2/sports/{$prefix}".($remainder !== '' ? "/{$remainder}" : '');
        }

        return '/api/v2';
    }
}
