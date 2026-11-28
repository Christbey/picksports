<?php

namespace App\Support\Privacy;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

final class SensitiveDocument
{
    private const NAMES = ['login', 'logout', 'register', 'password.*', 'verification.*', 'two-factor.*', 'passkeys.*', 'oauth.*', 'group-invitations.*', 'groups.join.*', 'subscription.*', 'cashier.payment', 'admin.users', 'admin.subscriptions*'];

    private static function routeIsSensitive(Route $route): bool
    {
        return Str::is(self::NAMES, (string) $route->getName())
            || Str::is(['settings', 'settings/*'], $route->uri());
    }

    public static function matches(Request $request): bool
    {
        return ($request->route() instanceof Route && self::routeIsSensitive($request->route()))
            || collect(array_keys($request->query()))->contains(fn ($key) => preg_match('/token|code|email|signature|invite|join|secret/i', (string) $key) === 1);
    }

    /** Route templates contain no request token or user value. */
    public static function paths(): array
    {
        $paths = [];
        foreach (app('router')->getRoutes() as $route) {
            if (self::routeIsSensitive($route)) {
                $paths[] = '/'.ltrim($route->uri(), '/');
            }
        }

        return array_values(array_unique($paths));
    }
}
