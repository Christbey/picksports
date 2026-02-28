<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebPushSubscriptionController extends Controller
{
    public function __construct(private readonly WebPushService $webPushService) {}

    public function config(Request $request): JsonResponse
    {
        return response()->json([
            'configured' => $this->webPushService->isConfigured(),
            'publicKey' => $this->webPushService->publicKey(),
            'hasSubscription' => $request->user()
                ->webPushSubscriptions()
                ->active()
                ->exists(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000'],
            'contentEncoding' => ['nullable', 'string', 'max:32'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:1024'],
            'keys.auth' => ['required', 'string', 'max:256'],
        ]);

        $subscription = $this->webPushService->registerSubscription(
            $request->user(),
            $validated,
            $request->userAgent()
        );

        return response()->json([
            'ok' => true,
            'id' => $subscription->id,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['nullable', 'url', 'max:2000'],
        ]);

        $deleted = $this->webPushService->removeSubscription(
            $request->user(),
            $validated['endpoint'] ?? null
        );

        return response()->json([
            'ok' => true,
            'deleted' => $deleted,
        ]);
    }

    public function sendTest(Request $request): JsonResponse
    {
        if (! $this->webPushService->isConfigured()) {
            return response()->json([
                'ok' => false,
                'message' => 'Web push is not configured on the server.',
            ], 422);
        }

        $result = $this->webPushService->sendToUser($request->user(), [
            'title' => 'PickSports Test Notification',
            'body' => 'Push notifications are active for your account.',
            'icon' => '/apple-touch-icon.png',
            'badge' => '/icon-192.png',
            'tag' => 'pick-sports-test',
            'url' => route('alert-preferences.edit'),
            'data' => [
                'url' => route('alert-preferences.edit'),
                'type' => 'test',
            ],
        ]);

        $ok = $result['sent'] > 0;
        $status = $ok ? 200 : 422;
        $message = $ok
            ? 'Test push sent.'
            : 'No active subscriptions were reachable. Re-enable push and try again.';

        return response()->json([
            'ok' => $ok,
            'message' => $message,
            'result' => $result,
        ], $status);
    }
}
