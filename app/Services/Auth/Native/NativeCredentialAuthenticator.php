<?php

namespace App\Services\Auth\Native;

use App\Models\DeviceSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\PersonalAccessToken;

final class NativeCredentialAuthenticator
{
    /** Null delegates untouched legacy/interactive policy; false never falls through. */
    public function decision(PersonalAccessToken $token): ?bool
    {
        if ($token->getConnection()->getName() !== (new DeviceSession)->getConnection()->getName()) {
            return false;
        }
        $sessions = DeviceSession::query()->where('access_token_id', $token->id)->orderBy('id')->limit(2)->lockForUpdate()->get();
        $native = $token->getAttribute('api_policy_version') === NativeCredentialPolicy::VERSION;
        $recorded = $sessions->contains(fn ($session) => $session->native_policy_version !== null
            || ($session->getAttributes()['access_expires_epoch'] ?? null) !== null);
        if (! $native && ! $recorded) {
            return null;
        }
        if (! $native || $sessions->count() !== 1) {
            return false;
        }
        $current = $token->newQuery()->whereKey($token->id)->lockForUpdate()->first();
        if ($current === null || ! hash_equals((string) $current->token, (string) $token->token)
            || $current->getAttribute('api_policy_version') !== NativeCredentialPolicy::VERSION) {
            return false;
        }
        $token = $current;
        $session = $sessions->first();
        $profile = $token->getAttribute('api_token_profile');
        $epoch = $token->getAttribute('api_expires_epoch');
        if (! is_string($profile) || ! isset(NativeCredentialPolicy::PROFILES[$profile])
            || $token->abilities !== NativeCredentialPolicy::PROFILES[$profile]
            || $token->getRawOriginal('expires_at') !== null || ! NativeCredentialPolicy::validEpoch($epoch)
            || $session->revoked_at !== null || $token->tokenable_type !== (new User)->getMorphClass()
            || (int) $session->user_id !== (int) $token->tokenable_id || $session->abilities !== $token->abilities
            || $session->access_token_expires_at === null || (int) $session->access_expires_epoch !== (int) $epoch) {
            return false;
        }

        return CarbonImmutable::now('UTC')->getTimestamp() < (int) $epoch;
    }
}
