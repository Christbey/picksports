<?php

namespace App\Services\Billing;

/** Configured application origin, never an incoming Host or caller-supplied redirect. */
final class BillingReturnUrl
{
    public static function route(string $name): string
    {
        if (! in_array($name, ['subscription.manage', 'subscription.success', 'subscription.plans'], true)) {
            throw new BillingProviderFailure('provider_return_url_invalid');
        }
        $base = config('app.url');
        $parts = is_string($base) ? parse_url($base) : false;
        if (! is_string($base) || ! is_array($parts) || strlen($base) > 2048 || filter_var($base, FILTER_VALIDATE_URL) === false
            || ($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null)
            || filter_var($parts['host'], FILTER_VALIDATE_IP) !== false || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f]/', $base)) {
            throw new BillingProviderFailure('provider_return_url_invalid');
        }

        return rtrim($base, '/').route($name, [], false);
    }
}
