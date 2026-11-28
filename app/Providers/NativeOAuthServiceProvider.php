<?php

namespace App\Providers;

use App\Services\Auth\NativeAuthCodeGrant;
use App\Services\Auth\NativeOAuthPkcePolicy;
use DateInterval;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\PassportServiceProvider;
use League\OAuth2\Server\Grant\AuthCodeGrant;

/** Replace only Passport's server factory; the package still owns its routes, guards and other bindings. */
class NativeOAuthServiceProvider extends PassportServiceProvider
{
    public function register(): void
    {
        $this->registerAuthorizationServer();
    }

    public function boot(): void
    {
        // Passport's discovered provider boots its routes, commands and publishing exactly once.
    }

    protected function buildAuthCodeGrant(): AuthCodeGrant
    {
        return new NativeAuthCodeGrant(
            $this->app->make(AuthCodeRepository::class),
            $this->app->make(RefreshTokenRepository::class),
            new DateInterval('PT10M'),
            $this->app->make(NativeOAuthPkcePolicy::class),
        );
    }
}
