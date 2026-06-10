<?php

use App\Actions\ESPN\NBA\SyncGamesFromScoreboard;
use App\Actions\ESPN\NBA\SyncPlayerInjuries;
use App\Actions\Validation\SportValidator;
use App\Services\ESPN\NBA\EspnService;
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
        '--skip-sync-pipeline' => true,
        '--skip-stats' => true,
        '--skip-queue-drain' => true,
        '--skip-model-pipeline' => true,
        '--skip-ai-analysis' => true,
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
        ->expectsOutputToContain('--repair')
        ->expectsOutputToContain('--ai')
        ->expectsOutputToContain('--skip-sync-pipeline')
        ->expectsOutputToContain('--skip-stats')
        ->expectsOutputToContain('--skip-model-pipeline')
        ->expectsOutputToContain('--skip-queue-drain')
        ->expectsOutputToContain('--queue-drain-queue')
        ->expectsOutputToContain('--queue-drain-max-time')
        ->expectsOutputToContain('--skip-ai-analysis')
        ->expectsOutputToContain('--skip-ai-review')
        ->expectsOutputToContain('--stat-lookback-days')
        ->expectsOutputToContain('--stat-limit')
        ->assertExitCode(0);
});

it('exposes inline team sync for espn team sync commands', function () {
    $this->artisan('espn:sync-cfb-teams --help')
        ->expectsOutputToContain('--sync')
        ->assertExitCode(0);
});

it('accepts explicit repair and ai operator aliases', function () {
    $this->travelTo('2026-06-01 08:00:00');

    $sync = m::mock(SyncGamesFromScoreboard::class);
    $sync->shouldReceive('execute')->once()->with('20260601')->andReturn(1);
    $this->app->instance(SyncGamesFromScoreboard::class, $sync);

    $this->artisan('sports:operations-sentinel --sport=nba --from-date=2026-06-01 --to-date=2026-06-01 --season=2026 --repair --ai --ai-rate-limit-retries=2 --ai-rate-limit-delay=1 --skip-sync-pipeline --skip-stats --skip-queue-drain --skip-model-pipeline --skip-ai-analysis --skip-ai-review --skip-validation')
        ->expectsOutput('Repair mode requested; running the canonical repair pipeline.')
        ->expectsOutput('AI mode requested; daily prediction analysis and operations review will run unless explicitly skipped.')
        ->expectsOutput('Synced 1 NBA game row update(s).')
        ->assertExitCode(0);
});

it('defaults to the stale-status repair lookback through the next seven days', function () {
    $this->travelTo('2026-06-01 08:00:00');

    $sync = m::mock(SyncGamesFromScoreboard::class);

    foreach (range(0, 14) as $offset) {
        $sync->shouldReceive('execute')
            ->once()
            ->with(now()->subDays(7)->addDays($offset)->format('Ymd'))
            ->andReturn(1);
    }

    $this->app->instance(SyncGamesFromScoreboard::class, $sync);

    $this->artisan('sports:operations-sentinel', [
        '--sport' => 'nba',
        '--season' => 2026,
        '--skip-sync-pipeline' => true,
        '--skip-stats' => true,
        '--skip-queue-drain' => true,
        '--skip-model-pipeline' => true,
        '--skip-ai-analysis' => true,
        '--skip-validation' => true,
    ])
        ->expectsOutput('Synced 15 NBA game row update(s).')
        ->assertExitCode(0);
});

it('prints the operations ai review even when final validation fails', function () {
    $this->travelTo('2026-06-01 08:00:00');

    $sync = m::mock(SyncGamesFromScoreboard::class);
    $sync->shouldReceive('execute')->once()->with('20260601')->andReturn(1);
    $this->app->instance(SyncGamesFromScoreboard::class, $sync);

    $validator = m::mock(SportValidator::class);
    $validator->shouldReceive('validate')
        ->once()
        ->with('nba')
        ->andReturn([[
            'check_type' => 'validation_stub_failure',
            'status' => 'failing',
            'message' => 'Stub validation failed.',
            'metadata' => [],
            'recommended_action' => 'repair:nba',
        ]]);
    $this->app->instance(SportValidator::class, $validator);

    $this->artisan('sports:operations-sentinel', [
        '--sport' => 'nba',
        '--from-date' => '2026-06-01',
        '--to-date' => '2026-06-01',
        '--season' => 2026,
        '--skip-sync-pipeline' => true,
        '--skip-stats' => true,
        '--skip-queue-drain' => true,
        '--skip-model-pipeline' => true,
        '--skip-ai-analysis' => true,
    ])
        ->expectsOutput('Running NBA operations AI review...')
        ->expectsOutput('NBA Operations AI Review')
        ->expectsOutputToContain('Status: blocked')
        ->assertExitCode(1);
});

it('passes a bounded max time to the queue drain worker', function () {
    $this->travelTo('2026-06-01 08:00:00');

    $sync = m::mock(SyncGamesFromScoreboard::class);
    $sync->shouldReceive('execute')->once()->with('20260601')->andReturn(1);
    $this->app->instance(SyncGamesFromScoreboard::class, $sync);

    $this->artisan('sports:operations-sentinel --sport=nba --from-date=2026-06-01 --to-date=2026-06-01 --season=2026 --skip-sync-pipeline --skip-stats --queue-drain-queue=sync --queue-drain-max-time=60 --skip-model-pipeline --skip-ai-analysis --skip-validation')
        ->expectsOutputToContain('Draining queued NBA sync jobs before model generation and validation')
        ->assertExitCode(0);
});

it('binds every sentinel scoreboard action to a sport-specific espn service', function (string $actionClass, string $expectedServiceClass) {
    $action = app($actionClass);
    $property = new ReflectionProperty($action, 'espnService');
    $service = $property->getValue($action);

    expect($service)->toBeInstanceOf($expectedServiceClass);
})->with([
    'nba' => [SyncGamesFromScoreboard::class, EspnService::class],
    'nfl' => [App\Actions\ESPN\NFL\SyncGamesFromScoreboard::class, App\Services\ESPN\NFL\EspnService::class],
    'mlb' => [App\Actions\ESPN\MLB\SyncGamesFromScoreboard::class, App\Services\ESPN\MLB\EspnService::class],
    'cbb' => [App\Actions\ESPN\CBB\SyncGamesFromScoreboard::class, App\Services\ESPN\CBB\EspnService::class],
    'cfb' => [App\Actions\ESPN\CFB\SyncGamesFromScoreboard::class, App\Services\ESPN\CFB\EspnService::class],
    'wcbb' => [App\Actions\ESPN\WCBB\SyncGamesFromScoreboard::class, App\Services\ESPN\WCBB\EspnService::class],
    'wnba' => [App\Actions\ESPN\WNBA\SyncGamesFromScoreboard::class, App\Services\ESPN\WNBA\EspnService::class],
]);

it('binds every sentinel injury action to a sport-specific espn service', function (string $actionClass, string $expectedServiceClass) {
    $action = app($actionClass);
    $property = new ReflectionProperty($action, 'espnService');
    $service = $property->getValue($action);

    expect($service)->toBeInstanceOf($expectedServiceClass);
})->with([
    'nba' => [SyncPlayerInjuries::class, EspnService::class],
    'nfl' => [App\Actions\ESPN\NFL\SyncPlayerInjuries::class, App\Services\ESPN\NFL\EspnService::class],
    'mlb' => [App\Actions\ESPN\MLB\SyncPlayerInjuries::class, App\Services\ESPN\MLB\EspnService::class],
    'cbb' => [App\Actions\ESPN\CBB\SyncPlayerInjuries::class, App\Services\ESPN\CBB\EspnService::class],
    'cfb' => [App\Actions\ESPN\CFB\SyncPlayerInjuries::class, App\Services\ESPN\CFB\EspnService::class],
    'wcbb' => [App\Actions\ESPN\WCBB\SyncPlayerInjuries::class, App\Services\ESPN\WCBB\EspnService::class],
    'wnba' => [App\Actions\ESPN\WNBA\SyncPlayerInjuries::class, App\Services\ESPN\WNBA\EspnService::class],
]);
