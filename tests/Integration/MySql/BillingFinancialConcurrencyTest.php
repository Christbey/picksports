<?php

namespace Tests\Integration\MySql;

use App\Models\BillingMutationIntent;
use App\Models\BillingMutationReceipt;
use App\Models\BillingMutationState;
use App\Models\User;
use App\Services\Billing\BillingClock;
use App\Services\Billing\BillingFinancialAdmission;
use App\Services\Billing\BillingFinancialExecutor;
use App\Services\Billing\BillingFinancialGateway;
use App\Services\Billing\BillingFinancialScanner;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Isolated committed fixtures and independent DB-only workers; never invokes a provider/gateway. */
class BillingFinancialConcurrencyTest extends TestCase
{
    private string $directory;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        config(['billing.durable.enabled' => true, 'billing.entitlements.enabled' => true, 'app.url' => 'https://app.example.test', 'billing.account.platform_id' => 'acct_readiness_synthetic',
            'billing.account.livemode' => false, 'billing.account.api_origin' => 'https://api.stripe.com']);
        $this->directory = sys_get_temp_dir().'/picksports-billing-financial-'.bin2hex(random_bytes(8));
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

    public function test_two_independent_requests_reuse_one_durable_charge_intent(): void
    {
        $user = User::factory()->create();
        $first = $this->worker('first', 'admit', $user->id);
        $second = $this->worker('second', 'admit', $user->id);
        $this->releasePair($first, $second);
        $a = $this->workerResult($first);
        $b = $this->workerResult($second);
        $this->assertSame($a['intent_id'], $b['intent_id']);
        $this->assertSame(1, BillingMutationIntent::query()->count());
        $this->assertSame(1, BillingMutationState::query()->count());
        $this->assertSame(1, BillingMutationReceipt::query()->where('phase', 'requested')->count());
        $this->assertNull($user->fresh()->stripe_id);
    }

    public function test_duplicate_independent_queue_jobs_start_exactly_one_synthetic_provider_step(): void
    {
        [$user, $state, $intent, $token] = $this->enqueued();
        $first = $this->worker('first', 'start', $state->id, $token);
        $second = $this->worker('second', 'start', $state->id, $token);
        $this->releasePair($first, $second);
        $a = $this->workerResult($first);
        $b = $this->workerResult($second);
        $this->assertSame(1, (int) $a['ran'] + (int) $b['ran']);
        $this->assertCount(1, glob($this->directory.'/wire-*'));
        $this->assertSame(1, BillingMutationReceipt::query()->whereNotNull('provider_step_key')->count());
        $this->assertSame('cus_financial_process', $user->fresh()->stripe_id);
        $this->assertSame('pending_next', $state->fresh()->status);
        $this->assertSame($intent->id, $state->fresh()->active_intent_id);
    }

    public function test_killed_worker_after_committed_possible_send_remains_uncertain_without_any_repeated_send(): void
    {
        [$user, $state, , $token] = $this->enqueued();
        $worker = $this->worker('crash', 'send-hold', $state->id, $token);
        $this->waitFor('crash-ready', $worker);
        file_put_contents($this->directory.'/go', 'ready');
        $this->waitFor('possible-send', $worker);
        $this->assertSame('possible_send', $state->fresh()->status);
        $this->assertSame(1, BillingMutationReceipt::query()->whereNotNull('provider_step_key')->count());
        $worker->stop(0, 9);
        $this->assertNull($user->fresh()->stripe_id);
        BillingMutationState::query()->whereKey($state->id)->update(['lease_expires_at' => BillingClock::now()->subSecond()->format('Y-m-d H:i:s.u')]);
        config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database', 'queue.connections.database.queue' => 'default',
            'queue.connections.database.retry_after' => 150, 'queue.worker_contracts.default.timeout' => 120]);
        Bus::fake();
        $gateway = \Mockery::mock(BillingFinancialGateway::class);
        $gateway->shouldNotReceive('execute');
        app()->instance(BillingFinancialGateway::class, $gateway);
        app(BillingFinancialScanner::class)->scan(10, true);
        $this->assertSame('uncertain', $state->fresh()->status);
        $this->assertFalse(app(BillingFinancialExecutor::class)->run($state->id));
        $this->assertCount(1, glob($this->directory.'/wire-*'));
        $this->assertSame(1, BillingMutationReceipt::query()->whereNotNull('provider_step_key')->count());
        Bus::assertNothingDispatched();
    }

    private function enqueued(): array
    {
        $user = User::factory()->create();
        $intent = app(BillingFinancialAdmission::class)->admit($user, 'checkout', 'price_financial_process');
        $state = BillingMutationState::query()->sole();
        $token = (string) Str::uuid();
        $state->update(['status' => 'enqueued', 'owner_token' => $token, 'lease_expires_at' => BillingClock::now()->addSeconds(120)]);

        return [$user, $state, $intent, $token];
    }

    private function worker(string $label, string $mode, int $id, string $token = ''): Process
    {
        $connection = config('database.connections.mysql');
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/billing-financial-worker.php'), $this->directory, $label, $mode, (string) $id, $token], base_path(), [
            'APP_ENV' => 'testing', 'APP_BASE_PATH' => base_path(), 'LARAVEL_STORAGE_PATH' => storage_path(), 'APP_URL' => 'https://app.example.test', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
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

    private function releasePair(Process $first, Process $second): void
    {
        $this->waitFor('first-ready', $first);
        $this->waitFor('second-ready', $second);
        file_put_contents($this->directory.'/go', 'ready');
    }

    private function waitFor(string $file, Process $worker): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($this->directory.'/'.$file)) {
            $worker->checkTimeout();
            if (! $worker->isRunning() || microtime(true) > $deadline) {
                $this->fail('Synthetic billing worker did not reach its bounded barrier.');
            }
            usleep(20000);
        }
        if (str_ends_with($file, '-ready')) {
            $label = substr($file, 0, -6);
            $proof = json_decode(file_get_contents($this->directory.'/source-proof-'.$label), true, flags: JSON_THROW_ON_ERROR);
            foreach (['base', 'storage', 'user', 'billing', 'provider'] as $key) {
                $this->assertTrue($proof[$key] ?? false);
            }
        }
    }

    private function workerResult(Process $worker): array
    {
        $worker->wait();
        $this->assertSame(0, $worker->getExitCode(), 'Synthetic billing worker failed.');
        $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($result['ok']);
        foreach (['base', 'storage', 'user', 'billing', 'provider'] as $key) {
            $this->assertTrue($result['source_proof'][$key] ?? false);
        }
        $this->assertSame(hash_file('sha256', (new \ReflectionClass(BillingFinancialExecutor::class))->getFileName()), $result['source_proof']['billing_sha256'] ?? null);

        return $result;
    }
}
