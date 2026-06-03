<?php

use App\Actions\ESPN\NBA\SyncGamesFromScoreboard;
use Mockery as m;

uses()->group('sports');

afterEach(function () {
    m::close();
});

it('runs the configured operations sentinel for every supported sport', function (string $sport, string $syncClass) {
    $sync = m::mock($syncClass);
    $sync->shouldReceive('execute')->once()->with('20260531')->andReturn(2);
    $sync->shouldReceive('execute')->once()->with('20260601')->andReturn(3);
    $sync->shouldReceive('execute')->once()->with('20260602')->andReturn(1);
    $this->app->instance($syncClass, $sync);

    $this->artisan('sports:operations-sentinel', [
        '--sport' => $sport,
        '--from-date' => '2026-05-31',
        '--to-date' => '2026-06-02',
        '--season' => 2026,
        '--skip-stats' => true,
        '--skip-model-pipeline' => true,
        '--skip-validation' => true,
    ])
        ->expectsOutput('Synced 6 '.strtoupper($sport).' game row update(s).')
        ->assertExitCode(0);
})->with([
    'nba' => ['nba', SyncGamesFromScoreboard::class],
    'nfl' => ['nfl', App\Actions\ESPN\NFL\SyncGamesFromScoreboard::class],
    'mlb' => ['mlb', App\Actions\ESPN\MLB\SyncGamesFromScoreboard::class],
    'cbb' => ['cbb', App\Actions\ESPN\CBB\SyncGamesFromScoreboard::class],
    'cfb' => ['cfb', App\Actions\ESPN\CFB\SyncGamesFromScoreboard::class],
    'wcbb' => ['wcbb', App\Actions\ESPN\WCBB\SyncGamesFromScoreboard::class],
    'wnba' => ['wnba', App\Actions\ESPN\WNBA\SyncGamesFromScoreboard::class],
]);

it('fails when a sport is unsupported', function () {
    $this->artisan('sports:operations-sentinel', [
        '--sport' => 'nhl',
    ])
        ->expectsOutput('Unsupported sport: nhl.')
        ->assertExitCode(1);
});

it('exposes player and team stat refresh controls on the sentinel command', function () {
    $this->artisan('sports:operations-sentinel --help')
        ->expectsOutputToContain('--skip-stats')
        ->expectsOutputToContain('--skip-model-pipeline')
        ->expectsOutputToContain('--stat-lookback-days')
        ->expectsOutputToContain('--stat-limit')
        ->assertExitCode(0);
});
