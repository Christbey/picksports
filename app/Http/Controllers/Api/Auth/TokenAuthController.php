<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Resources\AuthUserResource;
use App\Services\Auth\Native\DeviceSessionTokenService;
use App\Services\Auth\TokenAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TokenAuthController extends Controller
{
    public function __construct(
        private readonly TokenAuthService $tokenAuthService,
        private readonly DeviceSessionTokenService $deviceSessionTokenService,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->tokenAuthService->authenticateWithPassword(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );
        $grant = $this->tokenAuthService->issueGrant($user, $request->deviceName(), $request->input('token_profile', 'interactive'));

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $grant->plainTextToken,
            ...$grant->metadata(),
            'user' => new AuthUserResource($user),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function me(Request $request): AuthUserResource
    {
        $user = $request->user();

        return new AuthUserResource($user);
    }

    public function logout(Request $request): JsonResponse
    {
        if (! $this->deviceSessionTokenService->revokeCurrent($request->user())) {
            $this->tokenAuthService->revokeCurrentToken($request->user());
        }

        return response()->json(null, 204);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->deviceSessionTokenService->revokeAll($request->user());
        $this->tokenAuthService->revokeAllTokens($request->user());

        return response()->json(null, 204);
    }
}
