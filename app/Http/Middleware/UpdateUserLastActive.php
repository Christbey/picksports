<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class UpdateUserLastActive
{
    private const CACHE_TTL_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->resolveUser($request);

        if (! $user) {
            return $next($request);
        }

        $cacheKey = sprintf('users:%d:last-active-written', $user->getKey());

        if (! Cache::has($cacheKey)) {
            $user->forceFill([
                'last_active_at' => now(),
            ])->saveQuietly();

            Cache::put($cacheKey, true, now()->addSeconds(self::CACHE_TTL_SECONDS));
        }

        return $next($request);
    }

    private function resolveUser(Request $request): ?User
    {
        $user = $request->user() ?? $request->user('sanctum');

        return $user instanceof User ? $user : null;
    }
}
