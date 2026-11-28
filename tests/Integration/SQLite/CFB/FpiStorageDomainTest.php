<?php

use App\Models\CFB\FpiRating;
use App\Models\CFB\Team;
use App\Models\CfbdTeamMapping;
use App\Services\CollegeFootballData\CollegeFootballDataService;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-27T12:00:00-06:00'));
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
});
afterEach(function () {
    $this->travelBack();
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

it('retains the existing positive signed integer season storage domain without imposing provider calendar availability', function (int $season) {
    $connection = (new FpiRating)->getConnection();
    $target = app(EloWriteTargetIdentity::class)->describe($connection)['fingerprint'];
    $exit = Artisan::call('cfb:activate-fpi-refresh', ['--season' => $season, '--target' => $target,
        '--operator' => 'synthetic-domain-test', '--reason' => 'Synthetic storage-domain test, no real provider availability claim',
        '--drained-at' => now('UTC')->subMinute()->toIso8601String(), '--drain-reference' => 'synthetic-only',
        '--source-review-reference' => 'synthetic-only', '--apply' => true]);
    expect($exit)->toBe(0);
    $team = Team::factory()->create(['school' => 'Storage Domain College', 'division' => 'FBS', 'cfbd_team_id' => 99]);
    CfbdTeamMapping::create(['sport' => 'cfb', 'cfbd_team_id' => 99, 'cfbd_team_name' => 'Storage Domain College', 'espn_team_name' => 'Storage Domain College']);
    $calls = 0;
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->with($season)->andReturnUsing(function () use (&$calls, $season) {
        $calls++;

        return ['rows' => [['team' => 'Storage Domain College', 'season' => $season, 'fpi' => 10]], 'observed_at' => now()->toIso8601String()];
    });
    expect(Artisan::call('cfb:import-fpi', ['--season' => $season, '--week' => 70000, '--require-data' => true]))->toBe(0);
    expect(FpiRating::sole()->season)->toBe($season)->and($calls)->toBe(1);
})->with([1, 2147483647]);
