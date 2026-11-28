<?php

use App\Models\BillingCustomerReconciliation;
use App\Models\BillingReconciliationAttempt;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Billing\BillingReconciliationCoordinator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestDatabaseSafety;

require dirname(__DIR__, 2).'/vendor/autoload.php';
try {
    $sourceRoot = realpath(dirname(__DIR__, 2));
    $expectedBase = getenv('APP_BASE_PATH');
    $expectedStorage = getenv('LARAVEL_STORAGE_PATH');
    if (! is_string($sourceRoot) || ! is_string($expectedBase) || realpath($expectedBase) !== $sourceRoot
        || ! is_string($expectedStorage) || $expectedStorage === '' || ! str_starts_with($expectedStorage, '/')) {
        throw new RuntimeException('Explicit isolated worker base/storage required.');
    }
    $app = require $sourceRoot.'/bootstrap/app.php';
    $proof = ['base' => realpath($app->basePath()) === $sourceRoot,
        'storage' => rtrim($app->storagePath(), '/') === rtrim($expectedStorage, '/'),
        'user' => realpath((new ReflectionClass(User::class))->getFileName()) === $sourceRoot.'/app/Models/User.php',
        'provider' => realpath((new ReflectionClass(AppServiceProvider::class))->getFileName()) === $sourceRoot.'/app/Providers/AppServiceProvider.php',
        'billing' => realpath((new ReflectionClass(BillingReconciliationCoordinator::class))->getFileName()) === $sourceRoot.'/app/Services/Billing/BillingReconciliationCoordinator.php'];
    if (in_array(false, $proof, true)) {
        throw new RuntimeException('Worker source/storage isolation refused.');
    }

    $app->beforeBootstrapping(LoadConfiguration::class, function (Application $app): void {
        if ($app->configurationIsCached()) {
            throw new RuntimeException('Cached worker configuration refused.');
        }
    });
    $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
        TestDatabaseSafety::assertSafe($app['config']->all(), false, Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
        if ($app['config']->get('database.default') !== 'mysql') {
            throw new RuntimeException('Isolated MySQL required.');
        }
    });
    $app->make(Kernel::class)->bootstrap();
    if (realpath($app->basePath()) !== $sourceRoot || rtrim($app->storagePath(), '/') !== rtrim($expectedStorage, '/')) {
        throw new RuntimeException('Worker bootstrap changed isolated paths.');
    }
    $proof['billing_sha256'] = hash_file('sha256', (new ReflectionClass(BillingReconciliationCoordinator::class))->getFileName());
    Http::preventStrayRequests();
    config(['billing.durable.enabled' => true, 'billing.account.platform_id' => 'acct_readiness_synthetic',
        'billing.account.livemode' => false, 'billing.account.api_origin' => 'https://api.stripe.com']);
    [$script, $directory, $label, $mode, $id, $token] = $argv;
    $canonical = realpath($directory);
    $temporary = realpath(sys_get_temp_dir());
    if ($canonical === false || $temporary === false || is_link($directory)
        || ! str_starts_with($canonical, $temporary.'/picksports-billing-')
        || (fileperms($canonical) & 0777) !== 0700 || ! function_exists('posix_geteuid') || fileowner($canonical) !== posix_geteuid()
        || ! in_array($label, ['first', 'second', 'crash'], true)
        || ! in_array($mode, ['claim', 'begin', 'commit-hold'], true) || ! ctype_digit($id)
        || ($mode !== 'claim' && preg_match('/\A[0-9a-f-]{36}\z/', $token) !== 1)) {
        throw new RuntimeException('Worker arguments refused.');
    }
    DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
    $wait = static function (string $file) use ($directory): void {
        $deadline = microtime(true) + 15;
        while (! is_file($directory.'/'.$file)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Worker barrier expired.');
            }
            usleep(20000);
        }
    };
    file_put_contents($directory.'/source-proof-'.$label, json_encode($proof, JSON_THROW_ON_ERROR), LOCK_EX);
    file_put_contents($directory.'/'.$label.'-ready', 'ready', LOCK_EX);
    $wait('go');
    $leases = app(BillingReconciliationCoordinator::class);
    if ($mode === 'claim') {
        $owner = $leases->claim((int) $id);
        $result = ['claimed' => $owner !== null, 'attempt_id' => $owner?->id];
    } else {
        $owner = BillingReconciliationAttempt::query()->findOrFail((int) $id);
        if (! hash_equals($owner->owner_token, $token)) {
            throw new RuntimeException('Worker owner refused.');
        }
        if ($mode === 'begin') {
            $result = ['began' => $leases->begin($owner)];
        } else {
            if (! $leases->begin($owner)) {
                throw new RuntimeException('Worker execution refused.');
            }
            $state = BillingCustomerReconciliation::query()->findOrFail($owner->reconciliation_id);
            if (count($owner->targets) !== 1) {
                throw new RuntimeException('Worker target scope refused.');
            }
            $observation = ['account_id' => $state->account_id, 'livemode' => false, 'customer_id' => $state->customer_id,
                'customer_deleted' => false, 'fetch_started_at' => now()->getTimestamp(), 'fetch_completed_at' => now()->getTimestamp(),
                'subscriptions' => [['id' => $owner->targets[0], 'customer_id' => $state->customer_id, 'livemode' => false,
                    'type' => 'default', 'status' => 'canceled', 'created' => 1700000000, 'trial_end' => null,
                    'cancel_at' => null, 'canceled_at' => null, 'ended_at' => null, 'cancel_at_period_end' => false, 'items' => []]]];
            $result = ['committed' => $leases->commit($owner, $observation, function ($user) use ($directory, $wait): array {
                DB::table('users')->where('id', $user->id)->update(['name' => 'Uncommitted worker projection']);
                file_put_contents($directory.'/projected', 'ready', LOCK_EX);
                $wait('commit');

                return ['before' => [], 'after' => ['name' => 'Uncommitted worker projection']];
            })];
        }
    }
    echo json_encode(['ok' => true, 'source_proof' => $proof, ...$result], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, 'Synthetic billing DB worker failed safely.');
    exit(1);
}
