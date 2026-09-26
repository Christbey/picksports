<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

test('only v2 application API routes are registered and v1 support commands are retired', function () {
    $apiRoutes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/'));
    expect($apiRoutes)->not->toBeEmpty();
    foreach ($apiRoutes as $route) {
        expect($route->uri())->toStartWith('api/v2/');
        expect((string) $route->getName())->not->toStartWith('v1.');
    }
    expect(Artisan::all())->not->toHaveKeys(['api:v1-usage-report', 'api:v1-auth-usage-report']);
});

test('retired v1 routes return not found even for authenticated application users', function (string $method, string $path) {
    $this->json($method, $path)->assertNotFound();
    Sanctum::actingAs(User::factory()->create());
    $this->json($method, $path)->assertNotFound();
})->with([
    ['GET', '/api/v1/nba/teams'],
    ['GET', '/api/v1/nfl/games/1/research'],
    ['GET', '/api/v1/mlb/predictions'],
    ['GET', '/api/v1/user-bets'],
    ['POST', '/api/v1/groups'],
    ['PUT', '/api/v1/cbb-brackets/current'],
    ['GET', '/api/v1/alert-preferences'],
    ['POST', '/api/v1/auth/login'],
    ['GET', '/api/v1/auth/me'],
    ['POST', '/api/v1/auth/passkeys/options'],
    ['POST', '/api/v1/security/reports/csp'],
    ['POST', '/api/v1/security/reports/integrity'],
]);
