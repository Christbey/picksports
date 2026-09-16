<?php

use App\Services\NFL\NflPregamePipelineRunner;
use Illuminate\Contracts\Console\Kernel;

it('runs NFL pregame steps sequentially and stops after the first failure', function () {
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('call')
        ->once()
        ->ordered()
        ->with('nfl:sync-odds', ['--days' => 8])
        ->andReturn(0);
    $kernel->shouldReceive('output')->once()->ordered()->andReturn('odds synced');
    $kernel->shouldReceive('call')
        ->once()
        ->ordered()
        ->with('nfl:generate-predictions', ['--season' => 2026])
        ->andReturn(1);
    $kernel->shouldReceive('output')->once()->ordered()->andReturn('legacy held');
    $kernel->shouldNotReceive('call')->with('nfl:generate-canonical-predictions', Mockery::any());

    $completed = [];
    $result = (new NflPregamePipelineRunner($kernel))->run([
        ['name' => 'odds_sync', 'command' => 'nfl:sync-odds', 'arguments' => ['--days' => 8]],
        ['name' => 'legacy_generation', 'command' => 'nfl:generate-predictions', 'arguments' => ['--season' => 2026]],
        ['name' => 'canonical_generation', 'command' => 'nfl:generate-canonical-predictions', 'arguments' => ['--season' => 2026]],
    ], function (string $name, string $output, int $exitCode) use (&$completed): void {
        $completed[] = compact('name', 'output', 'exitCode');
    });

    expect($result)->toBe([
        'successful' => false,
        'failed_step' => 'legacy_generation',
        'exit_code' => 1,
    ])->and($completed)->toHaveCount(2)
        ->and($completed[0])->toBe(['name' => 'odds_sync', 'output' => 'odds synced', 'exitCode' => 0])
        ->and($completed[1])->toBe(['name' => 'legacy_generation', 'output' => 'legacy held', 'exitCode' => 1]);
});

it('records canonical observations after explicit legacy holds but never reports the pipeline successful', function () {
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('call')->once()->ordered()->with('legacy', [])->andReturn(1);
    $kernel->shouldReceive('output')->once()->ordered()->andReturn('one game held');
    $kernel->shouldReceive('call')->once()->ordered()->with('canonical', [])->andReturn(0);
    $kernel->shouldReceive('output')->once()->ordered()->andReturn('canonical records persisted');

    $completed = [];
    $result = (new NflPregamePipelineRunner($kernel))->run([
        ['name' => 'legacy', 'command' => 'legacy', 'arguments' => [], 'continue_on_failure' => true],
        ['name' => 'canonical', 'command' => 'canonical', 'arguments' => []],
    ], function (string $name) use (&$completed): void {
        $completed[] = $name;
    });

    expect($completed)->toBe(['legacy', 'canonical'])
        ->and($result)->toBe(['successful' => false, 'failed_step' => 'legacy', 'exit_code' => 1]);
});
