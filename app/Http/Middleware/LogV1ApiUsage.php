<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogV1ApiUsage extends AddV1ApiDeprecationHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = parent::handle($request, $next);

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
}
