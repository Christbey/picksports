<?php

use App\Models\CBB\Team as CbbTeam;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Team as NflTeam;

it('exposes leaderboard only for configured sports', function () {
    $this->getJson('/api/v1/nba/player-stats/leaderboard')
        ->assertOk()
        ->assertJsonStructure(['data']);

    $this->getJson('/api/v1/cbb/player-stats/leaderboard')
        ->assertOk()
        ->assertJsonStructure(['data']);

    $this->getJson('/api/v1/nfl/player-stats/leaderboard')
        ->assertNotFound();
});

it('exposes team season averages only for configured sports', function () {
    $nbaTeam = NbaTeam::factory()->create();
    $cbbTeam = CbbTeam::factory()->create();
    $mlbTeam = MlbTeam::factory()->create();
    $nflTeam = NflTeam::factory()->create();

    $this->getJson('/api/v1/nba/team-stats/season-averages')
        ->assertOk()
        ->assertJsonStructure(['data']);

    $this->getJson("/api/v1/nba/teams/{$nbaTeam->id}/stats/season-averages")
        ->assertNotFound(); // no team stats yet

    $this->getJson("/api/v1/cbb/teams/{$cbbTeam->id}/stats/season-averages")
        ->assertNotFound(); // no team stats yet

    $this->getJson("/api/v1/mlb/teams/{$mlbTeam->id}/stats/season-averages")
        ->assertOk()
        ->assertJsonStructure(['data']);

    $this->getJson("/api/v1/nfl/teams/{$nflTeam->id}/stats/season-averages")
        ->assertNotFound();
});
