<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;

class AiProviderRateLimitCircuitBreaker
{
    public function retryAfterSeconds(string $provider): int
    {
        $blockedUntil = (int) Cache::get($this->cacheKey($provider), 0);
        $remaining = $blockedUntil - now()->timestamp;

        if ($remaining <= 0 && $blockedUntil > 0) {
            Cache::forget($this->cacheKey($provider));
        }

        return max(0, $remaining);
    }

    public function trip(string $provider, bool $quotaExhausted = false): int
    {
        $seconds = max(1, (int) config(
            $quotaExhausted
                ? 'ai.rate_limits.quota_cooldown_seconds'
                : 'ai.rate_limits.cooldown_seconds',
            $quotaExhausted ? 3600 : 900,
        ));
        $blockedUntil = now()->addSeconds($seconds)->timestamp;

        Cache::put($this->cacheKey($provider), $blockedUntil, $seconds);

        return $seconds;
    }

    public function isRateLimitFailure(?string $message): bool
    {
        if (! is_string($message) || trim($message) === '') {
            return false;
        }

        $normalized = strtolower($message);

        return str_contains($normalized, 'rate limited')
            || str_contains($normalized, 'rate limit')
            || str_contains($normalized, 'too many requests')
            || str_contains($normalized, 'code 429')
            || str_contains($normalized, 'status 429')
            || $this->isQuotaExhausted($message);
    }

    public function isQuotaExhausted(?string $message): bool
    {
        if (! is_string($message) || trim($message) === '') {
            return false;
        }

        $normalized = strtolower($message);

        return str_contains($normalized, 'insufficient_quota')
            || str_contains($normalized, 'credit_balance_exhausted')
            || str_contains($normalized, 'no credits remaining')
            || str_contains($normalized, 'billing_hard_limit_reached');
    }

    private function cacheKey(string $provider): string
    {
        return 'ai:provider-rate-limit:'.strtolower(trim($provider));
    }
}
