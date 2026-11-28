<?php

namespace App\Services\Auth\Native;

use App\Models\DeviceSession;
use App\Models\User;
use App\Services\Auth\Policy\ApiTokenPolicyAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Token;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Native bootstrap is a deliberate protocol boundary, not a general bearer-token exchange. */
final class NativeDeviceBootstrapAdmission
{
    public function issue(Request $request, Closure $issue): DeviceTokenPair
    {
        $user = $request->user();
        if (! $user instanceof User || $request->attributes->get('oauth_access_token') !== null) {
            $this->deny();
        }
        $parent = $user->currentAccessToken();
        if ($parent instanceof Token) {
            $this->deny();
        }
        $plainBearer = $request->bearerToken();
        $bearer = filled($plainBearer);
        $enabled = config('native_auth.epoch_issuance_enabled', false);
        if (! is_bool($enabled)) {
            throw new \LogicException('Native epoch issuance activation must be boolean.');
        }
        if ($user->getConnection()->getName() !== DB::connection()->getName()
            || ($parent instanceof PersonalAccessToken && $parent->getConnection()->getName() !== DB::connection()->getName())) {
            $this->deny();
        }

        return DB::transaction(function () use ($user, $parent, $bearer, $plainBearer, $enabled, $issue): DeviceTokenPair {
            // Refuse an actual native parent before taking a User lock against a rotating family.
            if ($parent instanceof PersonalAccessToken && DeviceSession::query()->where('access_token_id', $parent->id)->lockForUpdate()->first() !== null) {
                $this->deny();
            }
            $current = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $bearer) {
                if ($parent instanceof PersonalAccessToken || ! Auth::guard('web')->check() || (int) Auth::guard('web')->id() !== (int) $current->id) {
                    $this->deny();
                }

                return $issue($current);
            }
            if (! $parent instanceof PersonalAccessToken) {
                $this->deny();
            }
            $token = $parent->newQuery()->whereKey($parent->id)->lockForUpdate()->first();
            if ($token === null || $token->tokenable_type !== $current->getMorphClass() || (int) $token->tokenable_id !== (int) $current->id
                || DeviceSession::query()->where('access_token_id', $token->id)->lockForUpdate()->first() !== null) {
                $this->deny();
            }
            $parts = explode('|', (string) $plainBearer, 2);
            $plain = count($parts) === 2 ? $parts[1] : $parts[0];
            if ((count($parts) === 2 && (string) $parts[0] !== (string) $token->id)
                || ! hash_equals((string) $token->token, hash('sha256', $plain))) {
                $this->deny();
            }
            $expiration = config('sanctum.expiration');
            $valid = (! $expiration || $token->created_at->gt(now()->subMinutes((int) $expiration)))
                && ($token->expires_at === null || ! $token->expires_at->isPast());
            $callback = Sanctum::$accessTokenAuthenticationCallback;
            if (! $valid || (is_callable($callback) && ! $callback($token, $valid)) || ! $token->can('mobile:write')) {
                $this->deny();
            }
            $interactive = $token->api_policy_version === ApiTokenPolicyAuthenticator::VERSION && $token->api_token_profile === 'interactive'
                && $token->abilities === ApiTokenPolicyAuthenticator::PROFILES['interactive'];
            $legacy = $token->api_policy_version === null && $token->api_token_profile === null && $token->api_expires_epoch === null;
            if (! $interactive && ($enabled || ! $legacy)) {
                $this->deny();
            }

            return $issue($current);
        }, attempts: 3);
    }

    private function deny(): never
    {
        throw new AccessDeniedHttpException('Native bootstrap requires an interactive login or browser session; existing native families must refresh.');
    }
}
