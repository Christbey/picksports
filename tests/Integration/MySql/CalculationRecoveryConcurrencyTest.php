<?php

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Contracts\Predictions\EventInputSnapshotBuilder;
use App\Contracts\Predictions\SportCalculator;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CalculationRunAttempt;
use App\Models\CanonicalPrediction;
use App\Models\SportEvent;
use App\Services\Predictions\PredictionLifecycleOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(),
        Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    if (DB::connection()->getDriverName() !== 'mysql') {
        throw new RuntimeException('Concurrency tests require explicitly selected disposable MySQL.');
    }
    Artisan::call('migrate:fresh', ['--force' => true]);
    Http::preventStrayRequests();
    Queue::fake();
    $this->raceDirectory = sys_get_temp_dir().'/picksports-calculation-race-'.bin2hex(random_bytes(8));
    mkdir($this->raceDirectory, 0700);
    $this->raceWorkers = [];
    $this->raceCapturedAt = CarbonImmutable::now()->startOfSecond();
    $this->raceEvent = SportEvent::factory()->create(['sport' => 'nba', 'starts_at' => $this->raceCapturedAt->addHours(4)]);
    CalculationRelease::factory()->approved()->create(['sport' => 'nba', 'phase' => 'pregame']);
});

afterEach(function () {
    foreach ($this->raceWorkers ?? [] as $worker) {
        $worker->stop(0);
    }
    if (isset($this->raceDirectory)) {
        File::deleteDirectory($this->raceDirectory);
    }
});

function calculationRaceWait(Closure $condition): void
{
    $deadline = microtime(true) + 12;
    while (! $condition()) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Parent concurrency barrier timed out.');
        }
        usleep(10000);
    }
}

function calculationRaceWorker($test, string $mode, int $id, string $label, int $offset = 0): Process
{
    $connection = config('database.connections.mysql');
    $process = new Process([PHP_BINARY, base_path('tests/Fixtures/calculation-concurrency-worker.php'),
        $mode, (string) $id, $test->raceDirectory, $label, $test->raceCapturedAt->toIso8601String(), (string) $offset],
        base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_SOCKET' => $connection['unix_socket'], 'DB_DATABASE' => $connection['database'],
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'PICKSPORTS_TEST_MYSQL_DATABASE' => $connection['database'],
            'PICKSPORTS_TEST_MYSQL_SOCKET' => $connection['unix_socket'],
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array', 'NIGHTWATCH_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'PULSE_ENABLED' => 'false',
        ]);
    $process->setTimeout(20);
    $process->start();
    $test->raceWorkers[] = $process;

    return $process;
}

function calculationRaceResults($test, array $labels): array
{
    foreach ($test->raceWorkers as $worker) {
        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue('Concurrency worker did not exit cleanly.');
    }

    return array_map(fn ($label) => json_decode(file_get_contents($test->raceDirectory.'/result-'.$label), true, flags: JSON_THROW_ON_ERROR), $labels);
}

it('allows only one concurrent initial claim for identical native inputs', function () {
    calculationRaceWorker($this, 'generate', $this->raceEvent->id, 'a');
    calculationRaceWorker($this, 'generate', $this->raceEvent->id, 'b');
    calculationRaceWait(fn () => is_file($this->raceDirectory.'/ready-a') && is_file($this->raceDirectory.'/ready-b'));
    touch($this->raceDirectory.'/start-a');
    touch($this->raceDirectory.'/start-b');
    calculationRaceWait(fn () => is_file($this->raceDirectory.'/result-a') || is_file($this->raceDirectory.'/result-b'));
    touch($this->raceDirectory.'/finish-a');
    touch($this->raceDirectory.'/finish-b');
    $statuses = array_column(calculationRaceResults($this, ['a', 'b']), 'status');
    sort($statuses);
    expect($statuses)->toBe(['refused', 'succeeded'])
        ->and(CalculationRun::query()->count())->toBe(1)
        ->and(CalculationRunAttempt::query()->sole()->status)->toBe('succeeded')
        ->and(CanonicalPrediction::query()->count())->toBe(1);
});

it('fences a real paused process after a second process reclaims its expired attempt', function () {
    calculationRaceWorker($this, 'generate', $this->raceEvent->id, 'old');
    calculationRaceWait(fn () => is_file($this->raceDirectory.'/ready-old'));
    touch($this->raceDirectory.'/start-old');
    calculationRaceWait(fn () => is_file($this->raceDirectory.'/claimed-old'));
    calculationRaceWorker($this, 'generate', $this->raceEvent->id, 'new', 31);
    calculationRaceWait(fn () => is_file($this->raceDirectory.'/ready-new'));
    touch($this->raceDirectory.'/start-new');
    calculationRaceWait(fn () => is_file($this->raceDirectory.'/claimed-new'));
    touch($this->raceDirectory.'/finish-new');
    calculationRaceWait(fn () => is_file($this->raceDirectory.'/result-new'));
    touch($this->raceDirectory.'/finish-old');
    $results = calculationRaceResults($this, ['old', 'new']);
    expect(array_column($results, 'status'))->toBe(['refused', 'succeeded'])
        ->and(CalculationRunAttempt::query()->orderBy('attempt_number')->pluck('status')->all())->toBe(['abandoned', 'succeeded'])
        ->and(CalculationRun::query()->sole()->status)->toBe('succeeded')
        ->and(CanonicalPrediction::query()->count())->toBe(1);
});

it('serializes concurrent older and newer draft publications on the shared event', function () {
    $capturedAt = $this->raceCapturedAt;
    $builder = new class($capturedAt) implements EventInputSnapshotBuilder
    {
        public int $version = 1;

        public function __construct(private readonly CarbonImmutable $capturedAt) {}

        public function build(SportEvent $event, CalculationReleaseData $release): EventInputSnapshotData
        {
            return new EventInputSnapshotData(schemaVersion: $release->inputSchemaVersion,
                inputs: ['version' => $this->version], capturedAt: $this->capturedAt,
                cutoffAt: $this->capturedAt, latestSourceAvailableAt: $this->capturedAt, pregameSafetyStatus: 'verified');
        }
    };
    $calculator = new class implements SportCalculator
    {
        public function calculate(EventInputSnapshotData $snapshot, CalculationReleaseData $release): PredictionOutput
        {
            return new PredictionOutput([new PredictionMarketOutput('moneyline', 'home', probability: 0.61)]);
        }
    };
    $orchestrator = app(PredictionLifecycleOrchestrator::class);
    $older = $orchestrator->generate($this->raceEvent, $builder, $calculator);
    $builder->version = 2;
    $newer = $orchestrator->generate($this->raceEvent, $builder, $calculator);
    calculationRaceWorker($this, 'publish', $older->id, 'older');
    calculationRaceWorker($this, 'publish', $newer->id, 'newer');
    calculationRaceWait(fn () => is_file($this->raceDirectory.'/ready-older') && is_file($this->raceDirectory.'/ready-newer'));
    touch($this->raceDirectory.'/start-older');
    touch($this->raceDirectory.'/start-newer');
    $results = calculationRaceResults($this, ['older', 'newer']);
    expect($results[1]['status'])->toBe('succeeded')
        ->and($results[0]['status'])->toBeIn(['succeeded', 'refused'])
        ->and(CanonicalPrediction::query()->where('publication_state', 'published')->sole()->is($newer))->toBeTrue();
});
