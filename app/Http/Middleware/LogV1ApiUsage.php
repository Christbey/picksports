<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogV1ApiUsage
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

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ((bool) config('api.v1_usage_logging.deprecation_headers', true)) {
            $response->headers->set('X-API-Deprecated', 'true');
            $response->headers->set('X-API-Replacement', $this->replacementPath($request));
        }

        if ((bool) config('api.v1_usage_logging.enabled', false)) {
            Log::info('api.v1.usage', [
                'method' => $request->method(),
                'path' => $request->path(),
                'route_name' => $request->route()?->getName(),
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return $response;
    }

    private function replacementPath(Request $request): string
    {
        $segments = explode('/', trim($request->path(), '/'));
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
