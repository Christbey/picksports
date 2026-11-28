<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Auth\Policy\ApiTokenGrant;
use App\Services\Auth\Policy\ApiTokenPolicyAuthenticator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Features;
use Laravel\Passport\Token;
use RuntimeException;

class TokenAuthService
{
    public function authenticateWithPassword(string $email, string $password): User
    {
        /** @var User|null $user */
        $user = User::query()->where('email', strtolower($email))->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => [trans('auth.failed')],
            ]);
        }

        if (
            Features::enabled(Features::twoFactorAuthentication())
            && $user->two_factor_secret !== null
            && $user->two_factor_confirmed_at !== null
        ) {
            throw ValidationException::withMessages([
                'two_factor' => ['Two-factor authentication is enabled for this account and is not supported on this API endpoint.'],
            ]);
        }

        return $user;
    }

    public function issueAccessToken(User $user, string $tokenName, string $profile = 'interactive'): string
    {
        return $this->issueGrant($user, $tokenName, $profile)->plainTextToken;
    }

    public function issueGrant(User $user, string $tokenName, string $profile = 'interactive'): ApiTokenGrant
    {
        if (! isset(ApiTokenPolicyAuthenticator::PROFILES[$profile])) {
            throw ValidationException::withMessages(['token_profile' => ['Unknown API token profile.']]);
        }
        $enabled = config('api_tokens.finite_issuance_enabled', false);
        if (! is_bool($enabled)) {
            throw new RuntimeException('API token activation policy is invalid.');
        }
        if (! $enabled) {
            if ($profile !== 'interactive') {
                throw ValidationException::withMessages(['token_profile' => ['Finite API token profiles are not activated.']]);
            }
            $token = $user->createToken($tokenName, ['*']);

            return new ApiTokenGrant($token->plainTextToken, 'legacy_interactive', ['*'], null, null);
        }
        $minutes = config('api_tokens.ttl_minutes', 60);
        if ((! is_int($minutes) && ! is_string($minutes)) || ! preg_match('/\A[0-9]{1,4}\z/', (string) $minutes)
            || (int) $minutes < 5 || (int) $minutes > 1440) {
            throw new RuntimeException('API token lifetime policy is invalid.');
        }
        if ($user->tokens()->getRelated()->getConnection()->getName() !== $user->getConnection()->getName()) {
            throw new RuntimeException('Finite API tokens require one resolved database connection.');
        }
        $seconds = (int) $minutes * 60;
        $expires = CarbonImmutable::now('UTC')->getTimestamp() + $seconds;
        $abilities = ApiTokenPolicyAuthenticator::PROFILES[$profile];

        return $user->getConnection()->transaction(function () use ($user, $tokenName, $profile, $seconds, $expires, $abilities): ApiTokenGrant {
            $token = $user->createToken($tokenName, $abilities);
            // No naive SQL expiry mirror: Sanctum's policy callback enforces the exact epoch.
            $token->accessToken->forceFill(['api_policy_version' => ApiTokenPolicyAuthenticator::VERSION,
                'api_token_profile' => $profile, 'api_expires_epoch' => $expires])->save();

            return new ApiTokenGrant($token->plainTextToken, $profile, $abilities, $expires, $seconds);
        });
    }

    public function revokeCurrentToken(?User $user): void
    {
        $oauthToken = request()->attributes->get('oauth_access_token');
        if ($oauthToken instanceof Token) {
            $oauthToken->refreshToken?->revoke();
            $oauthToken->revoke();

            return;
        }

        $token = $user?->currentAccessToken();

        if ($token) {
            $token->delete();
        }
    }

    public function revokeAllTokens(?User $user): void
    {
        if ($user) {
            $user->tokens()->delete();
            Token::query()
                ->where('user_id', $user->getAuthIdentifier())
                ->where('revoked', false)
                ->each(function (Token $token): void {
                    $token->refreshToken?->revoke();
                    $token->revoke();
                });
        }
    }
}
