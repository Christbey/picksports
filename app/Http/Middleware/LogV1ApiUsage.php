<?php

namespace App\Http\Middleware;

use App\Support\Api\V1ReplacementPathResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogV1ApiUsage
{
    public function __construct(private readonly V1ReplacementPathResolver $replacementPathResolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ((bool) config('api.v1_usage_logging.deprecation_headers', true)) {
            $response->headers->set('X-API-Deprecated', 'true');
            $response->headers->set(
                'X-API-Replacement',
                $this->replacementPathResolver->resolve($request->path())
            );
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
}
