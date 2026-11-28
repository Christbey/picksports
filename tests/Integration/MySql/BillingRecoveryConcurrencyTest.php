<?php

namespace Tests\Integration\MySql;

use App\Models\BillingCustomerReconciliation;
use App\Models\BillingReconciliationAttempt;
use App\Models\BillingWebhookReceipt;
use App\Models\User;
use App\Services\Billing\BillingClock;
use App\Services\Billing\BillingReconciliationCoordinator;
use App\Services\Billing\BillingWebhookAdmission;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Isolated committed fixtures and independent DB-only workers; never invokes a provider/gateway. */
class BillingRecoveryConcurrencyTest extends TestCase
{
    private string $directory;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        config(['billing.durable.enabled' => true, 'billing.account.platform_id' => 'acct_readiness_synthetic',
            'billing.account.livemode' => false, 'billing.account.api_origin' => 'https://api.stripe.com']);
        $this->directory = sys_get_temp_dir().'/picksports-billing-'.bin2hex(random_bytes(8));
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

    public function test_independent_simultaneous_claims_create_exactly_one_live_owner(): void
    {
        [, $receipt] = $this->admitted();
        $first = $this->worker('first', 'claim', $receipt->reconciliation_id);
        $second = $this->worker('second', 'claim', $receipt->reconciliation_id);
        $this->releasePair($first, $second);
        $results = [$this->workerResult($first), $this->workerResult($second)];
        $this->assertSame(1, (int) $results[0]['claimed'] + (int) $results[1]['claimed']);
        $this->assertSame(1, BillingReconciliationAttempt::query()->where('status', 'running')->count());
        $this->assertSame(1, BillingCustomerReconciliation::query()->sole()->lease_sequence);
    }

    public function test_independent_duplicate_deliveries_start_one_observation(): void
    {
        [, $receipt] = $this->admitted();
        $owner = app(BillingReconciliationCoordinator::class)->claim($receipt->reconciliation_id);
        $first = $this->worker('first', 'begin', $owner->id, $owner->owner_token);
        $second = $this->worker('second', 'begin', $owner->id, $owner->owner_token);
        $this->releasePair($first, $second);
        $results = [$this->workerResult($first), $this->workerResult($second)];
        $this->assertSame(1, (int) $results[0]['began'] + (int) $results[1]['began']);
        $this->assertNotNull($owner->fresh()->fetch_started_at);
        $this->assertSame(1, BillingReconciliationAttempt::query()->count());
    }

    public function test_process_death_rolls_back_projection_and_retry_completes_one_receipt(): void
    {
        [$user, $receipt] = $this->admitted();
        $before = $user->name;
        $leases = app(BillingReconciliationCoordinator::class);
        $first = $leases->claim($receipt->reconciliation_id);
        $worker = $this->worker('crash', 'commit-hold', $first->id, $first->owner_token);
        $this->waitFor('crash-ready', $worker);
        file_put_contents($this->directory.'/go', 'ready');
        $this->waitFor('projected', $worker);
        $this->assertSame($before, $user->fresh()->name, 'Uncommitted worker SQL cannot leak into the other connection.');
        $worker->stop(0, 9);
        $this->assertSame($before, $user->fresh()->name);
        $this->assertSame('pending', $receipt->fresh()->status);
        BillingCustomerReconciliation::query()->whereKey($receipt->reconciliation_id)
            ->update(['lease_expires_at' => BillingClock::now()->subSecond()->format('Y-m-d H:i:s.u')]);
        $next = $leases->claim($receipt->reconciliation_id);
        $this->assertNotSame($first->id, $next->id);
        $this->assertSame('stale', $first->fresh()->status);
        $this->assertTrue($leases->begin($next));
        $this->assertTrue($leases->commit($next, $this->observation(), function ($current): array {
            DB::table('users')->where('id', $current->id)->update(['name' => 'Recovered committed projection']);

            return ['before' => [], 'after' => ['name' => 'Recovered committed projection']];
        }));
        $this->assertSame('Recovered committed projection', $user->fresh()->name);
        $this->assertSame('applied', $receipt->fresh()->status);
        $this->assertSame(1, BillingReconciliationAttempt::query()->where('status', 'succeeded')->count());
        $this->assertSame(1, BillingWebhookReceipt::query()->count());
    }

    private function admitted(): array
    {
        $user = User::factory()->create(['stripe_id' => 'cus_billing_concurrency']);
        $event = ['id' => 'evt_billing_concurrency', 'type' => 'customer.subscription.updated', 'created' => 100, 'livemode' => false,
            'data' => ['object' => ['id' => 'sub_billing_concurrency', 'customer' => $user->stripe_id, 'livemode' => false]]];

        return [$user, app(BillingWebhookAdmission::class)->admit($event, $event['type'])];
    }

    private function observation(): array
    {
        return ['account_id' => 'acct_readiness_synthetic', 'livemode' => false, 'customer_id' => 'cus_billing_concurrency',
            'customer_deleted' => false, 'fetch_started_at' => now()->getTimestamp(), 'fetch_completed_at' => now()->getTimestamp(),
            'subscriptions' => [['id' => 'sub_billing_concurrency', 'customer_id' => 'cus_billing_concurrency', 'livemode' => false,
                'type' => 'default', 'status' => 'canceled', 'created' => 1700000000, 'trial_end' => null, 'cancel_at' => null,
                'canceled_at' => null, 'ended_at' => null, 'cancel_at_period_end' => false, 'items' => []]]];
    }

    private function worker(string $label, string $mode, int $id, string $token = ''): Process
    {
        $connection = config('database.connections.mysql');
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/billing-recovery-worker.php'), $this->directory, $label, $mode, (string) $id, $token], base_path(), [
            'APP_ENV' => 'testing', 'APP_BASE_PATH' => base_path(), 'LARAVEL_STORAGE_PATH' => storage_path(), 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
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
        $this->assertSame(hash_file('sha256', (new \ReflectionClass(BillingReconciliationCoordinator::class))->getFileName()), $result['source_proof']['billing_sha256'] ?? null);

        return $result;
    }
}
