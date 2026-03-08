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

    public function options(PasskeyAuthenticationOptionsRequest $request): JsonResponse
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

        $plainTextToken = $this->tokenAuthService->issueAccessToken($user, $request->deviceName());

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $plainTextToken,
            'user' => new AuthUserResource($user),
        ]);
    }

    private function ensurePasskeysEnabled(): void
    {
        abort_unless((bool) config('passkeys.enabled', true), 404);
    }
}
