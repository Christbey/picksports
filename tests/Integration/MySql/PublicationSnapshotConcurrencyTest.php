<?php

namespace Tests\Integration\MySql;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Contracts\Predictions\EventInputSnapshotBuilder;
use App\Contracts\Predictions\SportCalculator;
use App\Models\CalculationRelease;
use App\Models\CanonicalPrediction;
use App\Models\SportEvent;
use App\Services\Predictions\PredictionLifecycleOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PublicationSnapshotConcurrencyTest extends TestCase
{
    private string $directory;

    /** @var list<Process> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->directory = sys_get_temp_dir().'/picksports-publication-snapshot-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(0, 9);
            }
        }
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    public function test_a_prior_consistent_snapshot_cannot_hide_a_newer_publication(): void
    {
        [$older, $newer] = $this->drafts();
        $this->runSnapshotRace($older, $newer, 'newer');
        $this->assertTrue(CanonicalPrediction::query()->where('publication_state', 'published')->sole()->is($newer));
        $this->assertSame('draft', $older->fresh()->publication_state);
    }

    public function test_a_prior_consistent_snapshot_cannot_hide_a_rescheduled_kickoff(): void
    {
        [$older, $newer] = $this->drafts();
        $this->runSnapshotRace($older, $newer, 'reschedule');
        $this->assertSame(0, CanonicalPrediction::query()->whereNotNull('published_at')->count());
        $this->assertTrue($older->sportEvent->fresh()->starts_at->isPast());
    }

    /** @return array{CanonicalPrediction, CanonicalPrediction} */
    private function drafts(): array
    {
        $captured = CarbonImmutable::now()->startOfSecond();
        $event = SportEvent::factory()->create(['sport' => 'nba', 'starts_at' => $captured->addHours(4)]);
        CalculationRelease::factory()->approved()->create(['sport' => 'nba', 'phase' => 'pregame']);
        $builder = new class($captured) implements EventInputSnapshotBuilder
        {
            public int $revision = 1;

            public function __construct(private readonly CarbonImmutable $captured) {}

            public function build(SportEvent $event, CalculationReleaseData $release): EventInputSnapshotData
            {
                return new EventInputSnapshotData(schemaVersion: $release->inputSchemaVersion,
                    inputs: ['revision' => $this->revision], capturedAt: $this->captured,
                    cutoffAt: $this->captured, latestSourceAvailableAt: $this->captured, pregameSafetyStatus: 'verified');
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
        $older = $orchestrator->generate($event, $builder, $calculator);
        $builder->revision = 2;

        return [$older, $orchestrator->generate($event, $builder, $calculator)];
    }

    private function runSnapshotRace(CanonicalPrediction $older, CanonicalPrediction $newer, string $mode): void
    {
        $writer = $this->snapshotWorker($newer, $mode);
        $this->waitSnapshotBarrier('writer-ready', $writer);
        $reader = $this->snapshotWorker($older, 'older');
        $this->waitSnapshotBarrier('snapshot-ready', $reader);
        $snapshot = json_decode(file_get_contents($this->directory.'/snapshot-ready'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertGreaterThan(0, $snapshot['transaction_level']);
        file_put_contents($this->directory.'/commit-writer', 'commit');
        $writer->wait();
        $reader->wait();
        $this->assertSame(0, $writer->getExitCode(), $writer->getErrorOutput());
        $this->assertSame(0, $reader->getExitCode(), $reader->getErrorOutput());
        $this->assertSame('refused', json_decode($reader->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status']);
    }

    private function snapshotWorker(CanonicalPrediction $prediction, string $mode): Process
    {
        $connection = config('database.connections.mysql');
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/publication-snapshot-worker.php'), (string) $prediction->id, $this->directory, $mode], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_SOCKET' => $connection['unix_socket'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'PICKSPORTS_TEST_MYSQL_DATABASE' => Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'),
            'PICKSPORTS_TEST_MYSQL_SOCKET' => Env::get('PICKSPORTS_TEST_MYSQL_SOCKET') ?? '',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
            'NIGHTWATCH_ENABLED' => 'false', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false',
        ]);
        $worker->setTimeout(25);
        $worker->start();
        $this->workers[] = $worker;

        return $worker;
    }

    private function waitSnapshotBarrier(string $file, Process $worker): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($this->directory.'/'.$file)) {
            $worker->checkTimeout();
            if (! $worker->isRunning() || microtime(true) > $deadline) {
                $this->fail('Publication worker failed at barrier '.$file.' '.$worker->getErrorOutput());
            }
            usleep(20000);
        }
    }
}
