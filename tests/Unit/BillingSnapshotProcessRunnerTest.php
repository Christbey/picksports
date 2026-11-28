<?php

use App\Services\Billing\BillingDatabaseBoundary;
use App\Services\Billing\BillingProviderFailure;
use App\Services\Billing\BillingSnapshotProcessFactory;
use App\Services\Billing\BillingSnapshotProcessRunner;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

it('passes bounded identifiers through stdin with an exact secret-free PHP command', function () {
    $factory = new BillingSnapshotProcessFactory;
    $command = $factory->create()->getCommandLine();
    expect($command)->toContain(PHP_BINARY, base_path('scripts/billing_provider_snapshot.php'))->not->toContain('Bearer', 'sk_');
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setInput')->once()->with('{"customer_id":"cus_synthetic"}')->andReturnSelf();
    $process->shouldReceive('setTimeout')->once()->with(60)->andReturnSelf();
    $process->shouldReceive('run')->once()->andReturnUsing(function ($callback) {
        $callback('out', '{"ok":true,"data":{"customer_id":"cus_synthetic"}}');

        return 0;
    });
    $process->shouldReceive('clearOutput')->once()->andReturnSelf();
    $process->shouldReceive('isSuccessful')->once()->andReturnTrue();
    $process->shouldReceive('isRunning')->once()->andReturnFalse();
    $factory = Mockery::mock(BillingSnapshotProcessFactory::class);
    $factory->shouldReceive('create')->once()->andReturn($process);
    config(['billing.fetch_budget_seconds' => 60]);
    expect((new BillingSnapshotProcessRunner(app(BillingDatabaseBoundary::class), $factory))->run(['customer_id' => 'cus_synthetic']))
        ->toBe(['customer_id' => 'cus_synthetic']);
});

it('kills a still-running fetch on stdout or stderr overflow without exposing output', function (string $stream, int $bytes) {
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setInput')->once()->andReturnSelf();
    $process->shouldReceive('setTimeout')->once()->andReturnSelf();
    $process->shouldReceive('run')->once()->andReturnUsing(function ($callback) use ($stream, $bytes) {
        $callback($stream, str_repeat('x', $bytes));

        return 0;
    });
    $process->shouldReceive('isRunning')->once()->andReturnTrue();
    $process->shouldReceive('stop')->once()->with(0.2, 9)->andReturn(9);
    $factory = Mockery::mock(BillingSnapshotProcessFactory::class);
    $factory->shouldReceive('create')->once()->andReturn($process);
    try {
        (new BillingSnapshotProcessRunner(app(BillingDatabaseBoundary::class), $factory))->run(['customer_id' => 'cus_synthetic']);
        test()->fail('Oversized output must fail.');
    } catch (BillingProviderFailure $error) {
        expect($error->category)->toBe('provider_output_limit')->and($error->getMessage())->not->toContain(str_repeat('x', 20));
    }
})->with([['out', 1048577], ['err', 8193]]);
