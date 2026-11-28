<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('refuses invalid odds windows before local discovery or action dispatch', function (string $sport, string $days) {
    $actionClass = 'App\\Actions\\OddsApi\\'.strtoupper($sport).'\\SyncOddsForGames';
    $commandClass = 'App\\Console\\Commands\\'.strtoupper($sport).'\\SyncOddsCommand';
    $calls = 0;
    $action = Mockery::mock($actionClass);
    $action->shouldReceive('execute')->andReturnUsing(function () use (&$calls): int {
        $calls++;

        return 0;
    });
    app()->instance($actionClass, $action);
    Artisan::registerCommand(app($commandClass));
    $gameQueries = [];
    DB::listen(function ($query) use (&$gameQueries) {
        if (str_contains($query->sql, 'mlb_games')) {
            $gameQueries[] = $query->sql;
        }
    });

    expect(Artisan::call($sport.':sync-odds', ['--days' => $days]))->toBe(1)
        ->and($calls)->toBe(0)
        ->and($gameQueries)->toBe([]);
})->with([
    ['cbb', '0'], ['cbb', '-1'], ['cbb', '1.5'], ['cbb', 'garbage'],
    ['mlb', '0'], ['mlb', '-1'], ['mlb', '1.5'], ['mlb', 'garbage'],
]);

it('retains omitted and explicit valid odds windows and zero-result success', function (string $sport, ?string $days, int $expected) {
    $actionClass = 'App\\Actions\\OddsApi\\'.strtoupper($sport).'\\SyncOddsForGames';
    $commandClass = 'App\\Console\\Commands\\'.strtoupper($sport).'\\SyncOddsCommand';
    $action = Mockery::mock($actionClass);
    $action->shouldReceive('execute')->once()->with($expected, 'synthetic_sport')->andReturn(0);
    app()->instance($actionClass, $action);
    Artisan::registerCommand(app($commandClass));
    $arguments = ['--odds-sport' => 'synthetic_sport'];
    if ($days !== null) {
        $arguments['--days'] = $days;
    }
    expect(Artisan::call($sport.':sync-odds', $arguments))->toBe(0);
})->with([
    ['cbb', null, 7], ['cbb', '1', 1], ['cbb', '14', 14],
    ['mlb', null, 7], ['mlb', '1', 1], ['mlb', '14', 14],
]);
