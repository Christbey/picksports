<?php

namespace App\Services\Auth\Policy;

use App\Services\Auth\Native\NativeCredentialAuthenticator;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\PersonalAccessToken;

/** Exact epoch policy for explicitly marked new credentials; untouched legacy policy is unchanged; native epochs have a distinct validator. */
final class ApiTokenPolicyAuthenticator
{
    public const VERSION = 'interactive-api-v1';

    public const PROFILES = ['interactive' => ['mobile:read', 'mobile:write'], 'read_only' => ['mobile:read']];

    public function __construct(private readonly mixed $previous = null) {}

    public function __invoke(PersonalAccessToken $token, bool $valid): bool
    {
        if (! $valid || (is_callable($this->previous) && ! ($this->previous)($token, $valid))) {
            return false;
        }
        $native = app(NativeCredentialAuthenticator::class)->decision($token);
        if ($native !== null) {
            return $native;
        }
        $version = $token->getAttribute('api_policy_version');
        $profile = $token->getAttribute('api_token_profile');
        $epoch = $token->getAttribute('api_expires_epoch');
        if ($version === null && $profile === null && $epoch === null) {
            return true;
        }
        if ($version !== self::VERSION || ! is_string($profile) || ! isset(self::PROFILES[$profile])
            || $token->abilities !== self::PROFILES[$profile] || $token->getRawOriginal('expires_at') !== null
            || (! is_int($epoch) && ! is_string($epoch)) || ! preg_match('/\A[1-9][0-9]{0,11}\z/', (string) $epoch)
            || (int) $epoch > 253402300799) {
            return false;
        }

        return CarbonImmutable::now('UTC')->getTimestamp() < (int) $epoch;
    }
}
