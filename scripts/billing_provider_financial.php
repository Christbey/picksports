<?php

// Private fixed financial CLI adapter, not an HTTP entry point. Inputs/outputs contain no API secret or full provider event.
use App\Services\Billing\BillingFinancialCollector;
use App\Services\Billing\BillingProviderFailure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\ConnectionEstablished;

require dirname(__DIR__).'/vendor/autoload.php';

$emit = static function (array $value, int $status): never {
    $output = json_encode($value, JSON_THROW_ON_ERROR, 64);
    if (strlen($output) > 1048576) {
        $output = '{"ok":false,"category":"provider_output_limit"}';
        $status = 1;
    }
    fwrite(STDOUT, $output);
    exit($status);
};
if (PHP_SAPI !== 'cli' || ! function_exists('pcntl_alarm') || ! function_exists('pcntl_signal') || ! defined('SIGALRM') || ! defined('SIG_DFL')) {
    $emit(['ok' => false, 'category' => 'provider_runtime_unsupported'], 1);
}
// Kernel default action terminates even a native blocking DNS call; no userland handler must run first.
pcntl_signal(SIGALRM, SIG_DFL);
pcntl_alarm(125);
try {
    $input = stream_get_contents(STDIN, 16385);
    if (! is_string($input) || strlen($input) > 16384) {
        throw new BillingProviderFailure('provider_request_invalid');
    }
    $request = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
    if (! is_array($request) || array_is_list($request)) {
        throw new BillingProviderFailure('provider_request_invalid');
    }
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    // Bootstrap reviewed providers but do not execute an Artisan command/CommandFinished hooks or jobs.
    $app->make(Kernel::class)->bootstrap();
    $deny = static function (...$arguments): never {
        throw new LogicException('Billing provider child cannot execute SQL.');
    };
    foreach ($app->make('db')->getConnections() as $connection) {
        $connection->beforeExecuting($deny);
    }
    $app->make('events')->listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($deny): void {
        $event->connection->beforeExecuting($deny);
    });
    $seconds = min(30, (int) config('billing.fetch_budget_seconds', 60));
    if ($seconds < 1 || $seconds > 120) {
        throw new BillingProviderFailure('provider_request_invalid');
    }
    pcntl_alarm($seconds);
    $data = $app->make(BillingFinancialCollector::class)->collect($request);
    $emit(['ok' => true, 'data' => $data], 0);
} catch (BillingProviderFailure $error) {
    $emit(['ok' => false, 'category' => $error->category], 1);
} catch (Throwable) {
    $emit(['ok' => false, 'category' => 'provider_process_failure'], 1);
}
