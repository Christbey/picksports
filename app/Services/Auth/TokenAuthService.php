<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Features;
use Laravel\Passport\Token;

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

    public function issueAccessToken(User $user, string $tokenName): string
    {
        return $user->createToken($tokenName, ['*'])->plainTextToken;
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
