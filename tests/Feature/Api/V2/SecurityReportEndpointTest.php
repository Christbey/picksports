<?php

use App\Http\Middleware\AddSecurityHeaders;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

class SecurityReportTestCsrfMiddleware extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

test('browser security headers advertise only v2 reporting endpoints', function () {
    $response = app(AddSecurityHeaders::class)->handle(Request::create('/'), fn () => response('ok'));
    expect($response->headers->get('Reporting-Endpoints'))->toBe('csp="/api/v2/security/reports/csp", integrity="/api/v2/security/reports/integrity"');
});

test('browser security policy reports form and frame destinations outside the Meta Pixel endpoint', function () {
    $response = app(AddSecurityHeaders::class)->handle(Request::create('/'), fn () => response('ok'));
    $directives = collect(explode('; ', $response->headers->get('Content-Security-Policy-Report-Only')))
        ->mapWithKeys(function (string $directive): array {
            [$name, $sources] = explode(' ', $directive, 2);

            return [$name => explode(' ', $sources)];
        });
    $pixelEndpoint = 'https://www.facebook.com/tr/';

    expect($directives['form-action'])->toBe(["'self'", $pixelEndpoint])
        ->and(collect($directives['frame-src'])->filter(fn (string $source) => str_contains($source, 'facebook.com'))->values()->all())->toBe([$pixelEndpoint])
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

test('browser reports remain public throttled and CSRF exempt after migration', function (string $report, string $message) {
    config(['sanctum.stateful' => ['localhost'], 'sanctum.middleware.validate_csrf_token' => SecurityReportTestCsrfMiddleware::class]);
    Log::spy();
    $this->withHeaders(['Origin' => 'http://localhost'])->postJson("/api/v2/security/reports/{$report}", [
        ['type' => $report, 'body' => ['blockedURL' => 'https://example.com/script.js']],
    ])->assertOk()->assertJsonPath('ok', true)->assertHeader('X-Request-ID');
    Log::shouldHaveReceived('warning')->once()->with($message, Mockery::on(fn (array $context) => $context['payload'][0]['type'] === $report));
    $route = Route::getRoutes()->getByName("v2.security.reports.{$report}");
    expect(collect($route->gatherMiddleware())->contains(fn ($middleware) => str_starts_with($middleware, 'throttle:')))->toBeTrue();
})->with([['csp', 'CSP report received.'], ['integrity', 'Integrity policy report received.']]);
