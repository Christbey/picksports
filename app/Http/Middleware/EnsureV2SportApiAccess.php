<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Api\V2\SportApiAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureV2SportApiAccess
{
    public function __construct(private readonly SportApiAccess $sportApiAccess) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('v2.sports.*')) {
            return $next($request);
        }

        $sport = strtolower((string) $request->route('sport'));

        if (! array_key_exists($sport, (array) config('sports.domains', []))) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($this->sportApiAccess->shouldBypass($user)) {
            return $next($request);
        }

        if (! $this->sportApiAccess->canAccessApi($user)) {
            return response()->json([
                'message' => 'Your subscription does not include V2 API access.',
            ], 403);
        }

        if (! $this->sportApiAccess->canAccessSport($user, $sport)) {
            return response()->json([
                'message' => "Your subscription does not include {$sport} API access.",
            ], 403);
        }

        return $next($request);
    }
}
