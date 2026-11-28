<?php

namespace Tests\Integration\MySql;

use App\Models\ApiIdempotencyKey;
use App\Models\User;
use App\Models\UserBet;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Separate opt-in suite: committed fixtures, independent connections, no wrapping test transaction. */
class ApiIdempotencyConcurrencyTest extends TestCase
{
    private string $directory;

    /** @var list<Process> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->directory = sys_get_temp_dir().'/picksports-idempotency-'.bin2hex(random_bytes(8));
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

    public function test_same_key_waits_for_transaction_then_replays_one_business_write(): void
    {
        $user = User::factory()->create();
        $first = $this->worker($user, 'concurrent-operation', 'hold');
        $this->waitFor('business-written', $first);
        $second = $this->worker($user, 'concurrent-operation', 'normal');
        $this->waitFor('normal-started', $second);
        usleep(100000);
        $this->assertTrue($second->isRunning(), 'Second request should wait while the first holds its receipt lock.');
        file_put_contents($this->directory.'/release', 'commit');

        $firstResult = $this->workerResult($first);
        $secondResult = $this->workerResult($second);
        $this->assertSame(201, $firstResult['status']);
        $this->assertSame('false', $firstResult['replayed']);
        $this->assertSame('true', $secondResult['replayed']);
        $this->assertSame($firstResult['body'], $secondResult['body']);
        $this->assertSame(1, UserBet::query()->count());
        $this->assertSame(1, ApiIdempotencyKey::query()->whereNotNull('completed_at')->count());
    }

    public function test_process_death_rolls_back_business_write_and_retry_resumes_reserved_key(): void
    {
        $user = User::factory()->create();
        $first = $this->worker($user, 'crashed-operation', 'hold');
        $this->waitFor('business-written', $first);
        $first->stop(0, 9);
        $this->assertSame(0, UserBet::query()->count());
        $receipt = ApiIdempotencyKey::query()->sole();
        $this->assertNull($receipt->completed_at);
        $this->assertSame(ApiIdempotencyKey::ATOMIC_LOCAL, $receipt->execution_mode);

        $result = $this->workerResult($this->worker($user, 'crashed-operation', 'normal'));
        $replay = $this->workerResult($this->worker($user, 'crashed-operation', 'normal'));
        $this->assertSame(201, $result['status']);
        $this->assertSame('false', $result['replayed']);
        $this->assertSame('true', $replay['replayed']);
        $this->assertSame($result['body'], $replay['body']);
        $this->assertSame(1, UserBet::query()->count());
        $this->assertNotNull($receipt->fresh()->completed_at);
    }

    private function worker(User $user, string $key, string $mode): Process
    {
        $connection = config('database.connections.mysql');
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/api-idempotency-worker.php'), (string) $user->id, $key, $this->directory, $mode], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_SOCKET' => $connection['unix_socket'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'PICKSPORTS_TEST_MYSQL_DATABASE' => Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'),
            'PICKSPORTS_TEST_MYSQL_SOCKET' => Env::get('PICKSPORTS_TEST_MYSQL_SOCKET') ?? '',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
            'NIGHTWATCH_ENABLED' => 'false', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false',
        ]);
        $process->setTimeout(25);
        $process->start();
        $this->workers[] = $process;

        return $process;
    }

    private function waitFor(string $file, Process $worker): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($this->directory.'/'.$file)) {
            $worker->checkTimeout();
            if (! $worker->isRunning() || microtime(true) > $deadline) {
                $this->fail('Worker failed to reach barrier: '.$file.' '.$worker->getErrorOutput());
            }
            usleep(20000);
        }
    }

    /** @return array{status: int, body: string, replayed: string} */
    private function workerResult(Process $worker): array
    {
        $worker->wait();
        $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());

        return json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
