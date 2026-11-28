<?php

use App\Actions\OddsApi\MLB\SyncHistoricalOddsForGames;
use App\Console\Commands\MLB\SyncHistoricalOddsCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('refuses malformed historical odds bounds before discovery or dispatch', function (string $sport, array $options) {
    $actionClass = 'App\\Actions\\OddsApi\\'.strtoupper($sport).'\\SyncHistoricalOddsForGames';
    $commandClass = 'App\\Console\\Commands\\'.strtoupper($sport).'\\SyncHistoricalOddsCommand';
    $calls = 0;
    $action = Mockery::mock($actionClass);
    $action->shouldReceive('executeHistorical')->andReturnUsing(function () use (&$calls): array {
        $calls++;

        return ['processed_games' => 0, 'matched_games' => 0, 'created_snapshots' => 0, 'hydrated_current_games' => 0];
    });
    app()->instance($actionClass, $action);
    Artisan::registerCommand(app($commandClass));
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'nfl_games')) {
            $queries[] = $query->sql;
        }
    });
    expect(Artisan::call($sport.':sync-historical-odds', $options))->toBe(1)
        ->and($calls)->toBe(0)->and($queries)->toBe([]);
})->with([
    ['mlb', ['--season' => '2026', '--limit' => '-1']],
    ['mlb', ['--season' => '2026', '--limit' => 'garbage']],
    ['mlb', ['--season' => '2026', '--limit' => '1.5']],
    ['mlb', ['--season' => '2026', '--hours-before' => '-1']],
    ['mlb', ['--season' => '2026', '--hours-before' => 'garbage']],
    ['mlb', ['--season' => '0']],
    ['mlb', ['--season' => 'garbage']],
    ['mlb', ['--from-date' => '']],
    ['mlb', ['--from-date' => 'not-a-date']],
    ['mlb', ['--from-date' => '2026-02-30']],
    ['mlb', ['--from-date' => '2026-11-27', '--to-date' => '2026-11-26']],
    ['nfl', ['--season' => '2026', '--limit' => '-1']],
    ['nfl', []],
]);

it('preserves explicit zero and valid historical odds scope forwarding', function (array $options, array $expected) {
    $action = Mockery::mock(SyncHistoricalOddsForGames::class);
    $action->shouldReceive('executeHistorical')->once()->with(...$expected)
        ->andReturn(['processed_games' => 0, 'matched_games' => 0, 'created_snapshots' => 0, 'hydrated_current_games' => 0]);
    app()->instance(SyncHistoricalOddsForGames::class, $action);
    Artisan::registerCommand(app(SyncHistoricalOddsCommand::class));
    expect(Artisan::call('mlb:sync-historical-odds', $options))->toBe(0);
})->with([
    [['--season' => '2026'], [24, 2026, null, null, 0, 'baseball_mlb', false]],
    [['--season' => '2026', '--hours-before' => '0', '--limit' => '0'], [0, 2026, null, null, 0, 'baseball_mlb', false]],
    [['--from-date' => '2026-02-28', '--to-date' => '2026-03-01', '--limit' => '2'], [24, null, '2026-02-28', '2026-03-01', 2, 'baseball_mlb', false]],
    [['--to-date' => '2026-02-28'], [24, null, null, '2026-02-28', 0, 'baseball_mlb', false]],
]);
