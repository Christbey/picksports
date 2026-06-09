<?php

use App\Models\CommandHeartbeat;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Event;
use Mockery as m;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;

afterEach(function () {
    m::close();
});

it('records manual successful pipeline command heartbeats with rendered options', function () {
    $input = m::mock(InputInterface::class);
    $input->shouldReceive('getOptions')->andReturn([
        'sport' => ['mlb'],
        'season' => '2026',
        'date' => '2026-06-08',
        'force' => true,
        'help' => false,
    ]);

    Event::dispatch(new CommandFinished(
        'sports:ai-daily-predictions',
        $input,
        new BufferedOutput,
        0,
    ));

    $heartbeat = CommandHeartbeat::query()->first();

    expect($heartbeat)->not->toBeNull()
        ->and($heartbeat->sport)->toBe('mlb')
        ->and($heartbeat->status)->toBe('success')
        ->and($heartbeat->source)->toBe('manual')
        ->and($heartbeat->command)->toBe('sports:ai-daily-predictions --sport=mlb --season=2026 --date=2026-06-08 --force');
});

it('does not record manual heartbeats for commands outside the pipeline order rules', function () {
    $input = m::mock(InputInterface::class);
    $input->shouldReceive('getOptions')->andReturn([]);

    Event::dispatch(new CommandFinished(
        'healthcheck:validate-data',
        $input,
        new BufferedOutput,
        0,
    ));

    expect(CommandHeartbeat::query()->count())->toBe(0);
});
