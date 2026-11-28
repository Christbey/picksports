<?php

namespace App\Providers;

use App\Services\Auth\Policy\ApiTokenPolicyAuthenticator;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

final class ApiTokenPolicyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $previous = Sanctum::$accessTokenAuthenticationCallback;
        // Repeated test/application boots must not accumulate recursive callback wrappers.
        if (! $previous instanceof ApiTokenPolicyAuthenticator) {
            Sanctum::authenticateAccessTokensUsing(new ApiTokenPolicyAuthenticator($previous));
        }
    }
}
