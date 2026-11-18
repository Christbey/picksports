<?php

use App\Actions\CBB\RepairTournamentStructure;
use App\Actions\ESPN\CBB\SyncTeamSchedule;
use App\Models\CBB\Team;
use Carbon\CarbonImmutable;

beforeEach(function () {
    Team::factory()->create(['espn_id' => '123']);
    $sync = Mockery::mock(SyncTeamSchedule::class);
    $sync->shouldReceive('execute')->with('123', 2027)->once()->andReturn(0);
    $this->app->instance(SyncTeamSchedule::class, $sync);
    $repair = Mockery::mock(RepairTournamentStructure::class);
    $repair->shouldReceive('execute')->with(2027)->once()->andReturn(0);
    $this->app->instance(RepairTournamentStructure::class, $repair);
});

it('defers scheduled tournament completeness until bracket season without weakening the manual audit', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 01:00:00', 'America/Chicago'));

    $this->artisan('cbb:sync-tournament-structure --season=2027 --skip-backfill')
        ->expectsOutputToContain('Tournament completeness audit deferred until March 2027')
        ->assertSuccessful();

    $this->artisan('cbb:audit-tournament-structure --season=2027')->assertFailed();
});

it('still fails the scheduled tournament audit when March bracket data is incomplete', function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-17 01:00:00', 'America/Chicago'));

    $this->artisan('cbb:sync-tournament-structure --season=2027 --skip-backfill')
        ->expectsOutputToContain('Tournament structure audit failed for 2027:')
        ->assertFailed();
});
