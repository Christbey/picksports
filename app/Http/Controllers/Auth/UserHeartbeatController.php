<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class UserHeartbeatController extends Controller
{
    private const CACHE_TTL_SECONDS = 60;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user !== null, Response::HTTP_UNAUTHORIZED);

        $cacheKey = sprintf('users:%d:heartbeat-written', $user->getKey());

        if (! Cache::has($cacheKey)) {
            $user->forceFill([
                'last_active_at' => now(),
            ])->saveQuietly();

            Cache::put($cacheKey, true, now()->addSeconds(self::CACHE_TTL_SECONDS));
        }

        return response()->noContent();
    }
}
