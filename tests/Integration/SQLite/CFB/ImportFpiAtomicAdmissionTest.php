<?php

use App\Models\CFB\FpiRating;
use App\Models\CFB\Team;
use App\Models\CfbdTeamMapping;
use App\Services\CollegeFootballData\CollegeFootballDataService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../../../Support/FpiSeasonFixture.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-27T12:00:00-06:00'));
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    activateSyntheticFpiSeasonScope();
});
afterEach(function () {
    $this->travelBack();
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

it('preserves existing rating bytes when required source admission fails', function (array $rows, string $requirement) {
    $team = Team::factory()->create(['school' => 'Known College', 'division' => 'FBS']);
    $rating = FpiRating::factory()->create(['team_id' => $team->id, 'season' => 2026, 'week' => 3, 'fpi' => 5])->fresh();
    $before = $rating->getRawOriginal();
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->with(2026)->andReturn(['rows' => $rows, 'observed_at' => now()->toIso8601String()]);
    $this->artisan('cfb:import-fpi', ['--season' => 2026, '--week' => 3, $requirement => true])->assertFailed();
    expect($rating->fresh()->getRawOriginal())->toBe($before);
})->with([
    'unmatched partial batch' => [[['team' => 'Known College', 'fpi' => 10], ['team' => 'Missing College', 'fpi' => 12]], '--require-complete'],
    'duplicate team batch' => [[['team' => 'Known College', 'fpi' => 10], ['team' => 'Known College', 'fpi' => 20]], '--require-complete'],
    'unusable required row' => [[['team' => 'Known College']], '--require-data'],
]);

it('does not persist inferred identity bindings from a rejected complete batch', function () {
    $team = Team::factory()->create(['school' => 'Existing College', 'division' => 'FBS', 'cfbd_team_id' => null]);
    CfbdTeamMapping::query()->create(['cfbd_team_id' => 99, 'cfbd_team_name' => 'Provider College', 'espn_team_name' => 'Existing College', 'sport' => 'cfb']);
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->with(2026)->andReturn(['rows' => [
        ['team' => 'Provider College', 'fpi' => 10], ['team' => 'Missing College', 'fpi' => 12],
    ], 'observed_at' => now()->toIso8601String()]);
    $this->artisan('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true])->assertFailed();
    expect($team->fresh()->cfbd_team_id)->toBeNull()->and(FpiRating::query()->count())->toBe(0);
});

it('rolls back earlier rating writes when a later source row throws', function () {
    $team = Team::factory()->create(['school' => 'Known College', 'division' => 'FBS']);
    $rating = FpiRating::factory()->create(['team_id' => $team->id, 'season' => 2026, 'week' => 3, 'fpi' => 5])->fresh();
    $before = $rating->getRawOriginal();
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->with(2026)->andReturn(['rows' => [
        ['team' => 'Known College', 'fpi' => 10], 'invalid source row',
    ], 'observed_at' => now()->toIso8601String()]);
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]))->toBe(1);
    expect($rating->fresh()->getRawOriginal())->toBe($before);
});

it('uses only the two supported football mapping scopes', function (string $scope, int $exitCode) {
    $team = Team::factory()->create(['school' => 'Existing College', 'division' => 'FBS', 'cfbd_team_id' => null]);
    CfbdTeamMapping::query()->create(['cfbd_team_id' => 99, 'cfbd_team_name' => 'Provider College', 'espn_team_name' => 'Existing College', 'sport' => $scope]);
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->with(2026)->andReturn(['rows' => [
        ['team' => 'Provider College', 'fpi' => 10],
    ], 'observed_at' => now()->toIso8601String()]);
    $this->artisan('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true])->assertExitCode($exitCode);
    expect($team->fresh()->cfbd_team_id)->toBe($exitCode === 0 ? 99 : null)
        ->and(FpiRating::query()->count())->toBe($exitCode === 0 ? 1 : 0);
})->with([
    ['cfb', 0], ['americanfootball_ncaaf', 0], ['cbb', 1],
]);
