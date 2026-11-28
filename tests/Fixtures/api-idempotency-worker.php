<?php

use App\Http\Middleware\EnsureIdempotentApiRequest;
use App\Models\User;
use App\Models\UserBet;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestDatabaseSafety;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->beforeBootstrapping(LoadConfiguration::class, function (Application $app): void {
    if ($app->configurationIsCached()) {
        throw new RuntimeException('Refusing cached test worker configuration.');
    }
});
$app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
    TestDatabaseSafety::assertSafe($app['config']->all(), false, Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    if ($app['config']->get('database.default') !== 'mysql') {
        throw new RuntimeException('The concurrency worker requires isolated MySQL.');
    }
});
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();

[$script, $userId, $key, $directory, $mode] = $argv;
$user = User::query()->findOrFail($userId);
$request = Request::create('/api/v2/user-bets', 'POST', ['bet_amount' => 10]);
$request->headers->set('Idempotency-Key', $key);
$request->setUserResolver(fn () => $user);
$route = new Route('POST', 'api/v2/user-bets', fn () => null);
$route->name('v2.user-bets.store')->bind($request);
$request->setRouteResolver(fn () => $route);
file_put_contents($directory.'/'.$mode.'-started', 'ready');

$response = app(EnsureIdempotentApiRequest::class)->handle($request, function () use ($user, $directory, $mode) {
    $bet = UserBet::query()->create([
        'user_id' => $user->id, 'bet_amount' => 10, 'odds' => '-110',
        'bet_type' => 'moneyline', 'selection_side' => 'home', 'result' => 'pending', 'placed_at' => now(),
    ]);
    if ($mode === 'hold') {
        file_put_contents($directory.'/business-written', (string) $bet->id);
        $deadline = microtime(true) + 15;
        while (! is_file($directory.'/release')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Timed out waiting for the transaction barrier.');
            }
            usleep(20000);
        }
    }

    return response()->json(['id' => $bet->id], 201);
});
echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent(),
    'replayed' => $response->headers->get('Idempotency-Replayed')], JSON_THROW_ON_ERROR);
