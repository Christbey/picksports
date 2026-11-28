<?php

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Contracts\Predictions\EventInputSnapshotBuilder;
use App\Contracts\Predictions\SportCalculator;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CanonicalPrediction;
use App\Models\SportEvent;
use App\Services\Predictions\PredictionLifecycleOrchestrator;
use App\Services\Predictions\PredictionPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestDatabaseSafety;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->beforeBootstrapping(LoadConfiguration::class, function (Application $app): void {
    if ($app->configurationIsCached()) {
        throw new RuntimeException('Concurrency worker refuses cached configuration.');
    }
});
$app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
    TestDatabaseSafety::assertSafe($app['config']->all(), (bool) $app['config_loaded_from_cache'],
        Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    if ($app['config']->get('database.default') !== 'mysql') {
        throw new RuntimeException('Concurrency worker requires disposable MySQL.');
    }
});
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
Queue::fake();
config(['game_analysis.publisher_enabled' => false,
    'prediction_lifecycle.calculation_recovery.lease_seconds' => 30,
    'prediction_lifecycle.calculation_recovery.retry_seconds' => 60]);
[$mode, $recordId, $directory, $label, $capturedAt, $offset] = array_slice($argv, 1);
CarbonImmutable::setTestNow(CarbonImmutable::parse($capturedAt)->addSeconds((int) $offset));
Carbon::setTestNow(CarbonImmutable::now());
$await = static function (string $path): void {
    $deadline = microtime(true) + 15;
    while (! is_file($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Concurrency barrier timed out.');
        }
        usleep(10000);
    }
};
try {
    touch($directory.'/ready-'.$label);
    $await($directory.'/start-'.$label);
    if ($mode === 'publish') {
        $prediction = app(PredictionPublisher::class)->publish(CanonicalPrediction::query()->findOrFail($recordId));
    } else {
        $builder = new class(CarbonImmutable::parse($capturedAt)) implements EventInputSnapshotBuilder
        {
            public function __construct(private readonly CarbonImmutable $capturedAt) {}

            public function build(SportEvent $event, CalculationReleaseData $release): EventInputSnapshotData
            {
                return new EventInputSnapshotData(schemaVersion: $release->inputSchemaVersion,
                    inputs: ['concurrent_fixture' => true], capturedAt: $this->capturedAt,
                    cutoffAt: $this->capturedAt, latestSourceAvailableAt: $this->capturedAt,
                    pregameSafetyStatus: 'verified');
            }
        };
        $calculator = new class($directory, $label, $await) implements SportCalculator
        {
            public function __construct(private readonly string $directory, private readonly string $label, private readonly Closure $await) {}

            public function calculate(EventInputSnapshotData $snapshot, CalculationReleaseData $release): PredictionOutput
            {
                touch($this->directory.'/claimed-'.$this->label);
                ($this->await)($this->directory.'/finish-'.$this->label);

                return new PredictionOutput([new PredictionMarketOutput('moneyline', 'home', probability: 0.61)]);
            }
        };
        $prediction = app(PredictionLifecycleOrchestrator::class)->generate(SportEvent::query()->findOrFail($recordId), $builder, $calculator);
    }
    file_put_contents($directory.'/result-'.$label, json_encode(['status' => 'succeeded', 'prediction_id' => $prediction->id], JSON_THROW_ON_ERROR));
} catch (PredictionLifecycleException $exception) {
    file_put_contents($directory.'/result-'.$label, json_encode(['status' => 'refused', 'reason' => $exception->getMessage()], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($directory.'/result-'.$label, json_encode(['status' => 'error', 'exception' => $exception::class], JSON_THROW_ON_ERROR));
    exit(1);
}
