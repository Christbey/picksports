<?php

use App\Http\Controllers\ML\TrainingWorkerController;
use App\Http\Middleware\AuthenticateTrainingWorker;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

test('only v2 application API routes are registered and v1 support commands are retired', function () {
    $apiRoutes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/'));
    expect($apiRoutes)->not->toBeEmpty();
    $workerPrefix = 'api/ml-worker/v1/';
    $workerRoutes = $apiRoutes->filter(fn ($route) => str_starts_with($route->uri(), $workerPrefix));
    $workerContract = [
        'ml-worker.heartbeat' => ['POST', 'heartbeat', 'heartbeat', 'report'],
        'ml-worker.progress' => ['POST', 'cycles/{cycle}/attempts/{attempt}/progress', 'progress', 'report'],
        'ml-worker.index' => ['GET|HEAD', 'cycles', 'index', 'read'],
        'ml-worker.claim' => ['POST', 'cycles/{cycle}/claim', 'claim', 'claim'],
        'ml-worker.input' => ['GET|HEAD', 'cycles/{cycle}/attempts/{attempt}/inputs/{kind}', 'input', 'read'],
        'ml-worker.upload' => ['PUT', 'cycles/{cycle}/attempts/{attempt}/files', 'upload', 'upload'],
        'ml-worker.complete' => ['POST', 'cycles/{cycle}/attempts/{attempt}/complete', 'complete', 'complete'],
        'ml-worker.fail' => ['POST', 'cycles/{cycle}/attempts/{attempt}/fail', 'fail', 'complete'],
    ];
    expect($workerRoutes->map(fn ($route) => $route->getName())->values()->all())
        ->toEqualCanonicalizing(array_keys($workerContract));
    foreach ($apiRoutes as $route) {
        if (str_starts_with($route->uri(), $workerPrefix)) {
            [$method, $path, $action, $ability] = $workerContract[$route->getName()];
            expect(implode('|', $route->methods()))->toBe($method)
                ->and($route->uri())->toBe($workerPrefix.$path)
                ->and($route->getActionName())->toBe(TrainingWorkerController::class.'@'.$action)
                ->and($route->gatherMiddleware())->toContain(AuthenticateTrainingWorker::class.':'.$ability, 'throttle:60,1');

            continue;
        }
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
