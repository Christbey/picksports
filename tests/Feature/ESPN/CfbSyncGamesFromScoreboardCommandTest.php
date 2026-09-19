<?php

use App\Actions\ESPN\CFB\SyncGames;
use App\Console\Commands\ESPN\CFB\SyncGamesFromScoreboardCommand;
use App\Services\ESPN\CFB\EspnService;

it('maps a named CFB season to August through the following January', function () {
    $command = app(SyncGamesFromScoreboardCommand::class);
    $method = new ReflectionMethod($command, 'seasonDateRange');
    $method->setAccessible(true);
    [$start, $end] = $method->invoke($command, 2025);

    expect($start->toDateString())->toBe('2025-08-01')
        ->and($end->toDateString())->toBe('2026-01-31');
});

it('loads the CFB game importer with the shared nullable-team contract', function () {
    expect(new SyncGames(new EspnService))
        ->toBeInstanceOf(SyncGames::class);
});
