<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AddServerTiming;
use App\Http\Middleware\AddV1ApiDeprecationHeaders;
use App\Http\Middleware\EnsureUserCompletedOnboarding;
use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsSubscribed;
use App\Http\Middleware\EnsureV2SportApiAccess;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogV1ApiUsage;
use App\Http\Middleware\LogV1AuthApiUsage;
use App\Http\Middleware\UpdateUserLastActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->validateCsrfTokens(except: [
            'api/v1/security/reports/csp',
            'api/v1/security/reports/integrity',
        ]);

        $middleware->web(append: [
            AddServerTiming::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddSecurityHeaders::class,
            UpdateUserLastActive::class,
        ]);

        $middleware->api(append: [
            AddServerTiming::class,
            UpdateUserLastActive::class,
        ]);

        // Enable Sanctum stateful SPA authentication for /api routes.
        $middleware->statefulApi();

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'onboarded' => EnsureUserCompletedOnboarding::class,
            'subscribed' => EnsureUserIsSubscribed::class,
            'permission' => EnsureUserHasPermission::class,
            'v2.sport-api-access' => EnsureV2SportApiAccess::class,
            'v1.api-deprecation' => AddV1ApiDeprecationHeaders::class,
            'v1.auth-api-usage' => LogV1AuthApiUsage::class,
            'v1.api-usage' => LogV1ApiUsage::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
