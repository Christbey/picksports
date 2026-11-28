<?php

use App\Models\BillingMutationState;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Billing\BillingFinancialAdmission;
use App\Services\Billing\BillingFinancialExecutor;
use App\Services\Billing\BillingFinancialGateway;
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
        'billing' => realpath((new ReflectionClass(BillingFinancialExecutor::class))->getFileName()) === $sourceRoot.'/app/Services/Billing/BillingFinancialExecutor.php'];
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
    $proof['billing_sha256'] = hash_file('sha256', (new ReflectionClass(BillingFinancialExecutor::class))->getFileName());
    Http::preventStrayRequests();
    config(['billing.durable.enabled' => true, 'billing.entitlements.enabled' => true, 'app.url' => 'https://app.example.test', 'billing.account.platform_id' => 'acct_readiness_synthetic',
        'billing.account.livemode' => false, 'billing.account.api_origin' => 'https://api.stripe.com']);
    [$script, $directory, $label, $mode, $id, $token] = $argv;
    $canonical = realpath($directory);
    $temporary = realpath(sys_get_temp_dir());
    if ($canonical === false || $temporary === false || is_link($directory)
        || ! str_starts_with($canonical, $temporary.'/picksports-billing-')
        || (fileperms($canonical) & 0777) !== 0700 || ! function_exists('posix_geteuid') || fileowner($canonical) !== posix_geteuid()
        || ! in_array($label, ['first', 'second', 'crash'], true)
        || ! in_array($mode, ['admit', 'start', 'send-hold'], true) || ! ctype_digit($id)
        || ($mode !== 'admit' && preg_match('/\A[0-9a-f-]{36}\z/', $token) !== 1)) {
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
    if ($mode === 'admit') {
        $user = User::query()->findOrFail((int) $id);
        $intent = app(BillingFinancialAdmission::class)->admit($user, 'checkout', 'price_financial_process');
        $result = ['intent_id' => $intent->id];
    } else {
        $state = BillingMutationState::query()->findOrFail((int) $id);
        $gateway = new class($directory, $label, $mode, $wait) extends BillingFinancialGateway
        {
            public function __construct(private string $directory, private string $label, private string $mode, private Closure $wait) {}

            public function execute(array $request): array
            {
                if (DB::transactionLevel() !== 0) {
                    throw new LogicException('Synthetic provider seam is inside SQL.');
                }
                if ($request['operation'] === 'prepare') {
                    $p = ['kind' => 'customer', 'path' => '/v1/customers', 'form' => [], 'customer_id' => null];

                    return ['prepared' => $p, 'request_hash' => hash('sha256', json_encode($p, JSON_THROW_ON_ERROR))];
                }
                if ($request['operation'] !== 'send') {
                    throw new LogicException('Unexpected synthetic provider operation.');
                }
                file_put_contents($this->directory.'/wire-'.$this->label, 'synthetic', LOCK_EX);
                if ($this->mode === 'send-hold') {
                    file_put_contents($this->directory.'/possible-send', 'ready', LOCK_EX);
                    ($this->wait)('acknowledge');
                }

                return ['id' => 'cus_financial_process', 'customer_id' => 'cus_financial_process'];
            }
        };
        app()->instance(BillingFinancialGateway::class, $gateway);
        $result = ['ran' => app(BillingFinancialExecutor::class)->run($state->id, $state->active_intent_id, $token)];
    }
    fwrite(STDOUT, json_encode(['ok' => true, 'source_proof' => $proof, ...$result], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Synthetic financial worker refused or failed.\n");
    exit(1);
}
