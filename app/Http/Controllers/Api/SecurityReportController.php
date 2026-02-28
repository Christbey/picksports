<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

        Log::warning('Integrity policy report received.', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'payload' => $payload,
        ]);

        return response()->json(['ok' => true]);
    }
}
