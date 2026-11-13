<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SecurityReportController extends Controller
{
    public function csp(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        Log::warning('CSP report received.', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'payload' => $payload,
        ]);

        return response()->json(['ok' => true]);
    }

    public function integrity(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        $reports = array_is_list($payload) ? $payload : [$payload];
        $unique = [];

        foreach ($reports as $report) {
            if (! is_array($report) || ! $this->isRepeatedIntegrityReport($report, $request)) {
                $unique[] = $report;
            }
        }

        if ($unique !== [] || $reports === []) {
            Log::warning('Integrity policy report received.', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'payload' => array_is_list($payload) ? $unique : $payload,
            ]);
        }

        return response()->json(['ok' => true]);
    }

    private function isRepeatedIntegrityReport(array $report, Request $request): bool
    {
        $body = $report['body'] ?? null;
        if (($report['type'] ?? null) !== 'integrity-violation' || ! is_array($body)
            || ! is_string($body['blockedURL'] ?? null) || ! is_string($body['documentURL'] ?? null)) {
            return false;
        }

        ksort($body);
        $fingerprint = hash('sha256', serialize([$body, $request->ip(), $request->userAgent()]));

        try {
            return ! Cache::add('security:integrity-report:'.$fingerprint, true, 60);
        } catch (Throwable) {
            // Keep reporting available when the deduplication cache is unavailable.
            return false;
        }
    }
}
