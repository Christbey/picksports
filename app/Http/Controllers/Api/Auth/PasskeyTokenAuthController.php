<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\PasskeyAuthenticationOptionsRequest;
use App\Http\Requests\Api\Auth\PasskeyAuthenticationVerifyRequest;
use App\Http\Resources\AuthUserResource;
use App\Services\Auth\PasskeyAuthenticationService;
use App\Services\Auth\TokenAuthService;
use Illuminate\Http\JsonResponse;

class PasskeyTokenAuthController extends Controller
{
    public function __construct(
        private readonly PasskeyAuthenticationService $passkeyAuthenticationService,
        private readonly TokenAuthService $tokenAuthService,
    ) {}

    public function createOptions(PasskeyAuthenticationOptionsRequest $request): JsonResponse
    {
        $this->ensurePasskeysEnabled();

        return response()->json(
            $this->passkeyAuthenticationService->buildAuthenticationOptions(
                $request,
                $request->input('email'),
                'passkeys.api.authenticate',
                false,
            )
        );
    }

    public function verify(PasskeyAuthenticationVerifyRequest $request): JsonResponse
    {
        $this->ensurePasskeysEnabled();

        $user = $this->passkeyAuthenticationService->verifyAuthentication(
            $request,
            $request->validated(),
            'passkeys.api.authenticate',
            false,
        );

        $grant = $this->tokenAuthService->issueGrant($user, $request->deviceName(), $request->input('token_profile', 'interactive'));

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $grant->plainTextToken,
            ...$grant->metadata(),
            'user' => new AuthUserResource($user),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function ensurePasskeysEnabled(): void
    {
        abort_unless((bool) config('passkeys.enabled', true), 404);
    }
}
