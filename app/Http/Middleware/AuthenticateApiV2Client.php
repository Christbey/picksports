<?php

namespace App\Http\Middleware;

use App\Models\OAuthUser;
use App\Models\User;
use App\Support\Api\ApiV2ErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Token;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateApiV2Client
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            $user = Auth::guard('sanctum')->user();
        }

        if (! $user instanceof User && filled($request->bearerToken())) {
            $user = $this->passportUser($request);
        }

        if (! $user instanceof User) {
            return app(ApiV2ErrorResponse::class)->make(
                request: $request,
                status: 401,
                code: 'unauthenticated',
                message: 'Unauthenticated.',
            );
        }

        $oauthToken = $request->attributes->get('oauth_access_token');
        if ($oauthToken instanceof Token) {
            $requiredScope = $request->isMethodSafe() ? 'mobile:read' : 'mobile:write';

            if ($oauthToken->cant($requiredScope)) {
                return app(ApiV2ErrorResponse::class)->make(
                    request: $request,
                    status: 403,
                    code: 'insufficient_oauth_scope',
                    message: "The {$requiredScope} OAuth scope is required for this operation.",
                );
            }
        }

        $request->setUserResolver(fn (?string $guard = null): User => $user);
        Auth::setUser($user);

        return $next($request);
    }

    private function passportUser(Request $request): ?User
    {
        try {
            $oauthUser = Auth::guard('api')->user();
        } catch (Throwable) {
            return null;
        }

        if (! $oauthUser instanceof OAuthUser) {
            return null;
        }

        $user = User::query()->find($oauthUser->getAuthIdentifier());
        if (! $user instanceof User) {
            return null;
        }

        $accessToken = $oauthUser->currentAccessToken();
        if ($accessToken !== null) {
            $user->withAccessToken($accessToken);
        }

        $request->attributes->set('oauth_access_token', $accessToken);

        return $user;
    }
}
