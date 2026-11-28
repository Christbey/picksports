<?php

namespace App\Http\Middleware;

use App\Support\Performance\RequestQueryTimingCollector;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AddServerTiming
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('performance.server_timing_enabled', true)) {
            return $next($request);
        }

        $startedAt = hrtime(true);
        $collector = app(RequestQueryTimingCollector::class);
        $measurement = $collector->begin();
        try {
            $response = $next($request);
        } finally {
            $collector->finish($measurement);
        }
        $queryCount = $measurement->count;
        $databaseMs = $measurement->milliseconds;
        $totalMs = (hrtime(true) - $startedAt) / 1_000_000;
        $response->headers->set('Server-Timing', sprintf(
            'app;dur=%.1f, db;dur=%.1f;desc="%d queries"',
            $totalMs,
            $databaseMs,
            $queryCount,
        ));

        if ((bool) config('performance.slow_request_logging_enabled', true)
            && $totalMs >= (float) config('performance.slow_request_ms', 750)) {
            Log::warning('Slow application request detected.', [
                'method' => $request->method(),
                'path' => $request->route()?->uri(),
                'route' => $request->route()?->getName(),
                'status' => $response->getStatusCode(),
                'duration_ms' => round($totalMs, 1),
                'database_ms' => round($databaseMs, 1),
                'query_count' => $queryCount,
                'user_id' => $request->user()?->getAuthIdentifier(),
            ]);
        }

        return $response;
    }
}
