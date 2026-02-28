<?php

use App\Models\CBB\Game as CbbGame;
use App\Models\CBB\Team as CbbTeam;
use App\Models\CFB\Game as CfbGame;
use App\Models\CFB\Team as CfbTeam;
use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Game as NbaGame;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Game as NflGame;
use App\Models\NFL\Team as NflTeam;
use App\Models\User;
use App\Models\WCBB\Game as WcbbGame;
use App\Models\WCBB\Team as WcbbTeam;
use App\Models\WNBA\Game as WnbaGame;
use App\Models\WNBA\Team as WnbaTeam;
use Laravel\Sanctum\Sanctum;

dataset('contractSports', [
    'nba' => ['nba', NbaTeam::class, NbaGame::class],
    'nfl' => ['nfl', NflTeam::class, NflGame::class],
    'mlb' => ['mlb', MlbTeam::class, MlbGame::class],
    'cbb' => ['cbb', CbbTeam::class, CbbGame::class],
    'cfb' => ['cfb', CfbTeam::class, CfbGame::class],
    'wcbb' => ['wcbb', WcbbTeam::class, WcbbGame::class],
    'wnba' => ['wnba', WnbaTeam::class, WnbaGame::class],
]);

it('returns consistent team and game core payloads for supported sports', function (string $slug, string $teamModel, string $gameModel) {
    $homeTeam = $teamModel::factory()->create();
    $awayTeam = $teamModel::factory()->create();

    $game = $gameModel::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
    ]);

    $this->getJson("/api/v1/{$slug}/teams/{$homeTeam->id}")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'id',
                'espn_id',
                'abbreviation',
            ],
        ])
        ->assertJsonPath('data.id', $homeTeam->id);

    $this->getJson("/api/v1/{$slug}/games/{$game->id}")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'id',
                'espn_id',
                'home_team_id',
                'away_team_id',
                'status',
                'home_team',
                'away_team',
            ],
        ])
        ->assertJsonPath('data.id', $game->id)
        ->assertJsonPath('data.home_team_id', $homeTeam->id)
        ->assertJsonPath('data.away_team_id', $awayTeam->id);
})->with('contractSports');

it('enforces sanctum auth for protected sport endpoints', function (string $slug, string $teamModel, string $gameModel) {
    $this->getJson("/api/v1/{$slug}/predictions")->assertUnauthorized();
    $this->getJson("/api/v1/{$slug}/team-metrics")->assertUnauthorized();
})->with('contractSports');

it('allows sanctum-authenticated access to protected sport endpoints', function (string $slug, string $teamModel, string $gameModel) {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/v1/{$slug}/predictions")
        ->assertOk()
        ->assertJsonStructure(['data']);

    $this->getJson("/api/v1/{$slug}/team-metrics")
        ->assertOk()
        ->assertJsonStructure(['data']);
})->with('contractSports');
