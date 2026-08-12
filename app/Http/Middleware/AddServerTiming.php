<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $queryCount = 0;
        $databaseMs = 0.0;

        DB::listen(function ($query) use (&$queryCount, &$databaseMs): void {
            $queryCount++;
            $databaseMs += (float) $query->time;
        });

        $response = $next($request);
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
                'path' => $request->path(),
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
