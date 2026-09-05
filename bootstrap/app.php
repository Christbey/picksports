<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AddServerTiming;
use App\Http\Middleware\AddV1ApiDeprecationHeaders;
use App\Http\Middleware\AuthenticateApiV2Client;
use App\Http\Middleware\EnforceDeveloperApiEntitlement;
use App\Http\Middleware\EnsureIdempotentApiRequest;
use App\Http\Middleware\EnsureUserCompletedOnboarding;
use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsSubscribed;
use App\Http\Middleware\EnsureV2SportApiAccess;
use App\Http\Middleware\HandleApiV2Transport;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogV1ApiUsage;
use App\Http\Middleware\LogV1AuthApiUsage;
use App\Http\Middleware\UpdateUserLastActive;
use App\Support\Api\ApiV2ErrorResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(HandleApiV2Transport::class);

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

        // Authenticate and reserve/replay idempotency records before route model
        // binding so a retried DELETE can replay after its model is gone.
        $middleware->prependToPriorityList(ThrottleRequests::class, AuthenticateApiV2Client::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureIdempotentApiRequest::class);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'onboarded' => EnsureUserCompletedOnboarding::class,
            'subscribed' => EnsureUserIsSubscribed::class,
            'permission' => EnsureUserHasPermission::class,
            'v2.sport-api-access' => EnsureV2SportApiAccess::class,
            'v2.auth' => AuthenticateApiV2Client::class,
            'v2.idempotent' => EnsureIdempotentApiRequest::class,
            'developer.entitlement' => EnforceDeveloperApiEntitlement::class,
            'v1.api-deprecation' => AddV1ApiDeprecationHeaders::class,
            'v1.auth-api-usage' => LogV1AuthApiUsage::class,
            'v1.api-usage' => LogV1ApiUsage::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception): bool => app(ApiV2ErrorResponse::class)->appliesTo($request)
                || $request->expectsJson()
        );

        $exceptions->respond(
            fn (Response $response): Response => app(ApiV2ErrorResponse::class)->normalize(request(), $response)
        );
    })->create();
