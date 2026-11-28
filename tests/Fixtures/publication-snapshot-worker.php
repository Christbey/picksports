<?php

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CanonicalPrediction;
use App\Models\SportEvent;
use App\Services\Predictions\PredictionPublisher;
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
        throw new RuntimeException('Refusing cached publication test worker configuration.');
    }
});
$app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
    TestDatabaseSafety::assertSafe($app['config']->all(), false, Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    if ($app['config']->get('database.default') !== 'mysql') {
        throw new RuntimeException('Publication snapshot workers require isolated MySQL.');
    }
});
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
Queue::fake();
config()->set('game_analysis.publisher_enabled', false);
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
[$script, $predictionId, $directory, $mode] = $argv;
$prediction = CanonicalPrediction::query()->findOrFail($predictionId);

if ($mode === 'older') {
    DB::beginTransaction();
    try {
        // Prime the inherited consistent snapshot before publication's admission locks.
        CanonicalPrediction::query()->whereKey($prediction->getKey())->value('sport_event_id');
        file_put_contents($directory.'/snapshot-ready', json_encode(['transaction_level' => DB::connection()->transactionLevel()], JSON_THROW_ON_ERROR));
        app(PredictionPublisher::class)->publish($prediction);
        $status = 'published';
    } catch (PredictionLifecycleException $exception) {
        $status = 'refused';
    } finally {
        while (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }
    }
    echo json_encode(['status' => $status], JSON_THROW_ON_ERROR);
    exit(0);
}

DB::transaction(function () use ($prediction, $directory, $mode): void {
    $event = SportEvent::query()->lockForUpdate()->findOrFail($prediction->sport_event_id);
    if ($mode === 'newer') {
        app(PredictionPublisher::class)->publish($prediction);
    } else {
        $event->update(['starts_at' => now()->subMinute()]);
    }
    file_put_contents($directory.'/writer-ready', 'ready');
    $deadline = microtime(true) + 15;
    while (! is_file($directory.'/commit-writer')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Publication snapshot barrier timed out.');
        }
        usleep(20000);
    }
});
echo json_encode(['status' => 'committed'], JSON_THROW_ON_ERROR);
