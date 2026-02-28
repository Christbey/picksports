<?php

use App\Models\CBB\Game as CbbGame;
use App\Models\CBB\Team as CbbTeam;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function grantPermission(User $user, string $permission): void
{
    Permission::findOrCreate($permission, 'web');
    $user->givePermissionTo($permission);
}

it('requires auth for sport detailed web game routes', function () {
    $homeTeam = CbbTeam::factory()->create();
    $awayTeam = CbbTeam::factory()->create();
    $game = CbbGame::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $this->get("/cbb/games/{$game->id}")
        ->assertRedirect(route('login'));
});

it('denies sport detailed web game routes without permission', function () {
    $user = User::factory()->create();

    $homeTeam = CbbTeam::factory()->create();
    $awayTeam = CbbTeam::factory()->create();
    $game = CbbGame::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $this->actingAs($user)
        ->get("/cbb/games/{$game->id}")
        ->assertRedirect(route('subscription.plans'));
});

it('allows sport detailed web game routes with permission', function () {
    $user = User::factory()->create();
    grantPermission($user, 'view-cbb-predictions');

    $homeTeam = CbbTeam::factory()->create();
    $awayTeam = CbbTeam::factory()->create();
    $game = CbbGame::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $this->actingAs($user)
        ->get("/cbb/games/{$game->id}")
        ->assertOk();
});

it('denies team metrics pages without sport permission', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/nfl-team-metrics')
        ->assertRedirect(route('subscription.plans'));
});

it('allows team metrics pages with sport permission', function () {
    $user = User::factory()->create();
    grantPermission($user, 'view-nfl-predictions');

    $this->actingAs($user)
        ->get('/nfl-team-metrics')
        ->assertOk();
});

dataset('sport_api_paths', [
    ['nba', '/api/v1/nba/teams'],
    ['cbb', '/api/v1/cbb/teams'],
    ['wcbb', '/api/v1/wcbb/teams'],
    ['nfl', '/api/v1/nfl/teams'],
    ['mlb', '/api/v1/mlb/teams'],
    ['cfb', '/api/v1/cfb/teams'],
    ['wnba', '/api/v1/wnba/teams'],
]);

it('requires auth on sports api routes', function (string $sport, string $path) {
    $this->getJson($path)
        ->assertUnauthorized();
})->with('sport_api_paths');

it('denies authenticated users without sport api permission', function (string $sport, string $path) {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson($path)
        ->assertForbidden();
})->with('sport_api_paths');

it('allows authenticated users with sport api permission', function (string $sport, string $path) {
    $user = User::factory()->create();
    grantPermission($user, "view-{$sport}-predictions");
    Sanctum::actingAs($user);

    $this->getJson($path)
        ->assertOk();
})->with('sport_api_paths');
