<?php

use App\Actions\CFB\CalculateTeamMetrics;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Models\CFB\TeamSeasonAffiliation;

it('fails a metric batch if any required team cannot produce current metrics', function () {
    $teams = Team::factory()->count(3)->create();
    foreach ($teams as $team) {
        TeamSeasonAffiliation::create(['team_id' => $team->id, 'season' => 2026,
            'subdivision' => 'FBS', 'source' => 'cfbd_fbs_membership']);
    }
    $action = $this->mock(CalculateTeamMetrics::class);
    $action->shouldReceive('execute')->withArgs(fn ($team, $season) => $team->id === $teams[0]->id && $season === 2026)
        ->once()->andReturn(new TeamMetric);
    $action->shouldReceive('execute')->withArgs(fn ($team, $season) => $team->id === $teams[1]->id && $season === 2026)
        ->once()->andReturnNull();
    $this->artisan('cfb:calculate-team-metrics', ['--season' => 2026, '--team-ids' => $teams->pluck('id')->implode(',')])
        ->assertFailed();
});

it('rejects an oversized batch or unverified FBS membership before calculation', function () {
    $teams = Team::factory()->count(11)->create();
    $this->mock(CalculateTeamMetrics::class)->shouldNotReceive('execute');
    $this->artisan('cfb:calculate-team-metrics', ['--season' => 2026, '--team-ids' => $teams->pluck('id')->implode(',')])->assertFailed();
    $this->artisan('cfb:calculate-team-metrics', ['--season' => 2026, '--team-ids' => (string) $teams[0]->id])->assertFailed();
});
