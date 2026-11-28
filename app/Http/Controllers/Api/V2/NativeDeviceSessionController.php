<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\NativeDeviceSessionRefreshRequest;
use App\Http\Requests\Api\V2\NativeDeviceSessionStorePushRegistrationRequest;
use App\Http\Requests\Api\V2\NativeDeviceSessionStoreRequest;
use App\Models\DeviceSession;
use App\Models\User;
use App\Services\Auth\Native\DevicePushRegistrationService;
use App\Services\Auth\Native\DeviceSessionTokenService;
use App\Services\Auth\Native\DeviceTokenPair;
use App\Services\Auth\Native\InvalidRefreshToken;
use App\Services\Auth\Native\NativeDeviceBootstrapAdmission;
use App\Support\Api\ApiV2ErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NativeDeviceSessionController extends Controller
{
    public function store(NativeDeviceSessionStoreRequest $request, DeviceSessionTokenService $tokens): JsonResponse
    {
        $validated = $request->validated();

        $pair = app(NativeDeviceBootstrapAdmission::class)->issue($request,
            fn (User $current) => $tokens->issue(
                $current,
                $validated['device_name'],
                $validated['platform'],
                $validated['device_identifier'] ?? null,
            ));

        return response()->json($this->tokenPayload($pair), 201)->header('Cache-Control', 'private, no-store');
    }

    public function refresh(
        NativeDeviceSessionRefreshRequest $request,
        DeviceSessionTokenService $tokens,
        ApiV2ErrorResponse $errors,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $pair = $tokens->rotate($validated['refresh_token']);
        } catch (InvalidRefreshToken) {
            return $errors->make(
                request: $request,
                status: 401,
                code: 'invalid_refresh_token',
                message: 'The refresh token is invalid or no longer active.',
            );
        }

        return response()->json($this->tokenPayload($pair))->header('Cache-Control', 'private, no-store');
    }

    public function destroy(
        Request $request,
        string $deviceSession,
        DeviceSessionTokenService $tokens,
    ): JsonResponse {
        abort_unless($tokens->revoke($request->user(), $deviceSession), 404);

        return response()->json(status: 204);
    }

    public function storePushRegistration(
        NativeDeviceSessionStorePushRegistrationRequest $request,
        string $deviceSession,
        DevicePushRegistrationService $registrations,
    ): JsonResponse {
        $validated = $request->validated();
        $session = $this->ownedActiveSession($request, $deviceSession);
        $registration = $registrations->register(
            $session,
            $validated['provider'],
            $validated['device_token'],
            $validated['environment'] ?? null,
        );

        return response()->json([
            'data' => [
                'device_session_id' => $session->public_id,
                'provider' => $registration->provider,
                'environment' => $registration->environment,
                'last_registered_at' => $registration->last_registered_at?->toIso8601String(),
            ],
        ], $registration->wasRecentlyCreated ? 201 : 200);
    }

    public function destroyPushRegistrations(
        Request $request,
        string $deviceSession,
        string $provider,
        DevicePushRegistrationService $registrations,
    ): JsonResponse {
        abort_unless(in_array($provider, ['apns', 'fcm'], true), 404);
        $registrations->revokeProvider($this->ownedActiveSession($request, $deviceSession), $provider);

        return response()->json(status: 204);
    }

    private function ownedActiveSession(Request $request, string $publicId): DeviceSession
    {
        return DeviceSession::query()
            ->where('user_id', $request->user()->getKey())
            ->where('public_id', $publicId)
            ->whereNull('revoked_at')
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function tokenPayload(DeviceTokenPair $pair): array
    {
        return [
            'token_type' => 'Bearer',
            'access_token' => $pair->accessToken,
            'refresh_token' => $pair->refreshToken,
            'access_token_expires_at' => $pair->accessTokenExpiresAt->toIso8601String(),
            'refresh_token_expires_at' => $pair->refreshTokenExpiresAt->toIso8601String(),
            'device_session' => [
                'id' => $pair->deviceSession->public_id,
                'device_name' => $pair->deviceSession->device_name,
                'platform' => $pair->deviceSession->platform,
            ],
        ];
    }
}
