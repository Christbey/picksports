<?php

use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses()->group('mlb', 'api');

beforeEach(function () {
    Permission::findOrCreate('view-mlb-predictions', 'web');
});

it('filters mlb team trends by season type when requested', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('view-mlb-predictions');
    Sanctum::actingAs($user);

    $team = Team::factory()->create();
    $opponent = Team::factory()->create();

    Game::factory()->create([
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
        'season' => 2026,
        'season_type' => 'Pre Season',
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-03-20',
    ]);

    Game::factory()->create([
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
        'season' => 2026,
        'season_type' => 'Regular Season',
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-03-21',
    ]);

    Game::factory()->create([
        'home_team_id' => $opponent->id,
        'away_team_id' => $team->id,
        'season' => 2026,
        'season_type' => 'Regular Season',
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-03-22',
    ]);

    $response = $this->getJson(
        "/api/v1/mlb/teams/{$team->id}/trends?games=season&season=2026&season_type=Regular%20Season&before_date=2026-03-25"
    );

    $response->assertOk()
        ->assertJsonPath('team_id', $team->id)
        ->assertJsonPath('sample_size', 2);
});
