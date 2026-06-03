<?php

use App\Actions\ESPN\MLB\SyncGamesFromScoreboard;
use App\Actions\MLB\GradePredictions;
use Mockery as m;

uses()->group('mlb');

afterEach(function () {
    m::close();
});

it('syncs recent mlb scoreboards and grades predictions', function () {
    $sync = m::mock(SyncGamesFromScoreboard::class);
    $sync->shouldReceive('execute')->once()->with('20260531')->andReturn(2);
    $sync->shouldReceive('execute')->once()->with('20260601')->andReturn(3);
    $sync->shouldReceive('execute')->once()->with('20260602')->andReturn(1);
    $this->app->instance(SyncGamesFromScoreboard::class, $sync);

    $grader = m::mock(GradePredictions::class);
    $grader->shouldReceive('execute')->once()->with(2026)->andReturn([
        'graded' => 4,
        'total_games' => 4,
        'winner_accuracy' => 75.0,
        'avg_spread_error' => 1.5,
        'avg_total_error' => 6.2,
    ]);
    $this->app->instance(GradePredictions::class, $grader);

    $this->artisan('mlb:operations-sentinel', [
        '--from-date' => '2026-05-31',
        '--to-date' => '2026-06-02',
        '--season' => 2026,
        '--skip-validation' => true,
    ])
        ->expectsOutput('Synced 6 MLB game row update(s).')
        ->expectsOutput('Graded 4 MLB prediction(s) for season 2026.')
        ->assertExitCode(0);
});
