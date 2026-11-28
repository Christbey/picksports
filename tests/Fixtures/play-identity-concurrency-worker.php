<?php

use App\Actions\ESPN\NBA\SyncPlays;
use App\Services\ESPN\NBA\EspnService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestDatabaseSafety;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->beforeBootstrapping(LoadConfiguration::class, function (Application $app): void {
    if ($app->configurationIsCached()) {
        throw new RuntimeException('Worker refuses cached configuration.');
    }
});
$app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
    TestDatabaseSafety::assertSafe($app['config']->all(), (bool) $app['config_loaded_from_cache'], Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    if ($app['config']->get('database.default') !== 'mysql') {
        throw new RuntimeException('Disposable MySQL required.');
    }
});
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
Queue::fake();
[$event, $team, $directory] = array_slice($argv, 1);
if (! is_dir($directory) || is_link($directory) || fileowner($directory) !== posix_geteuid() || (fileperms($directory) & 0777) !== 0700) {
    throw new RuntimeException('Private barrier required.');
}
DB::statement('SET SESSION innodb_lock_wait_timeout=8');
$service = Mockery::mock(EspnService::class);
$service->shouldReceive('getGame')->once()->andReturnUsing(function () use ($directory, $team): array {
    touch($directory.'/captured');
    $deadline = microtime(true) + 12;
    while (! is_file($directory.'/release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier deadline.');
        }
        usleep(10000);
    }
    touch($directory.'/locking');

    return ['plays' => [['id' => 'incoming', 'team' => ['id' => $team], 'homeScore' => 0, 'awayScore' => 0, 'period' => ['number' => 1], 'clock' => ['displayValue' => '10:00'], 'text' => 'Synthetic play']]];
});
try {
    $count = (new SyncPlays($service))->execute($event);
    $status = $count === 1 ? 'applied' : 'declined';
} catch (RuntimeException $exception) {
    $status = match ($exception->getMessage()) {
        'Play game identity changed during provider fetch; preserving stored plays.' => 'game_changed',
        'Play provider team bindings changed during fetch; preserving stored plays.' => 'team_changed',
        default => throw $exception,
    };
}
Http::assertNothingSent();
echo json_encode(['status' => $status, 'base' => base_path(), 'storage' => storage_path(), 'source' => (new ReflectionClass(SyncPlays::class))->getFileName()], JSON_THROW_ON_ERROR);
