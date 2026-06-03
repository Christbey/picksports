<?php

use App\Models\CBB\Game as CbbGame;
use App\Models\CBB\Team as CbbTeam;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    config()->set('subscriptions.enforce_tiers', true);
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

it('allows sport detailed web game routes without permission while sports are free', function () {
    $user = User::factory()->create();

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

it('allows team metrics pages without sport permission while sports are free', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/nfl/team-metrics')
        ->assertOk();
});

it('allows team metrics pages with sport permission', function () {
    $user = User::factory()->create();
    grantPermission($user, 'view-nfl-predictions');

    $this->actingAs($user)
        ->get('/nfl/team-metrics')
        ->assertOk();
});

it('allows WNBA prediction pages without the sport permission', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/wnba/predictions')
        ->assertOk();
});

it('still requires auth for WNBA prediction pages', function () {
    $this->get('/wnba/predictions')
        ->assertRedirect(route('login'));
});

it('requires auth for player props routes', function () {
    $this->get('/nfl/player-props')
        ->assertRedirect(route('login'));
});

it('allows player props routes without sport permission while sports are free', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/nfl/player-props')
        ->assertOk();
});

it('allows player props routes with sport permission', function () {
    $user = User::factory()->create();
    grantPermission($user, 'view-nfl-predictions');

    $this->actingAs($user)
        ->get('/nfl/player-props')
        ->assertOk();
});

dataset('public_sport_api_paths', [
    ['nba', '/api/v1/nba/teams'],
    ['cbb', '/api/v1/cbb/teams'],
    ['wcbb', '/api/v1/wcbb/teams'],
    ['nfl', '/api/v1/nfl/teams'],
    ['mlb', '/api/v1/mlb/teams'],
    ['cfb', '/api/v1/cfb/teams'],
    ['wnba', '/api/v1/wnba/teams'],
]);

dataset('protected_sport_api_paths', [
    ['nba', '/api/v1/nba/predictions'],
    ['cbb', '/api/v1/cbb/predictions'],
    ['wcbb', '/api/v1/wcbb/predictions'],
    ['nfl', '/api/v1/nfl/predictions'],
    ['mlb', '/api/v1/mlb/predictions'],
    ['cfb', '/api/v1/cfb/predictions'],
    ['wnba', '/api/v1/wnba/predictions'],
]);

it('allows public access to core sports api routes', function (string $sport, string $path) {
    $this->getJson($path)
        ->assertOk();
})->with('public_sport_api_paths');

it('requires auth on protected sports api routes', function (string $sport, string $path) {
    $this->getJson($path)
        ->assertUnauthorized();
})->with('protected_sport_api_paths');

it('allows authenticated users to access protected sports api routes', function (string $sport, string $path) {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson($path)
        ->assertOk();
})->with('protected_sport_api_paths');

it('also allows authenticated users with sport api permission', function (string $sport, string $path) {
    $user = User::factory()->create();
    grantPermission($user, "view-{$sport}-predictions");
    Sanctum::actingAs($user);

    $this->getJson($path)
        ->assertOk();
})->with('protected_sport_api_paths');
