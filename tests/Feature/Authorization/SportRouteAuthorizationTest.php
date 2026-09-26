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

dataset('core_sport_api_paths', [
    ['nba', '/api/v2/sports/nba/teams'],
    ['cbb', '/api/v2/sports/cbb/teams'],
    ['wcbb', '/api/v2/sports/wcbb/teams'],
    ['nfl', '/api/v2/sports/nfl/teams'],
    ['mlb', '/api/v2/sports/mlb/teams'],
    ['cfb', '/api/v2/sports/cfb/teams'],
    ['wnba', '/api/v2/sports/wnba/teams'],
]);

dataset('protected_sport_api_paths', [
    ['nba', '/api/v2/sports/nba/predictions'],
    ['cbb', '/api/v2/sports/cbb/predictions'],
    ['wcbb', '/api/v2/sports/wcbb/predictions'],
    ['nfl', '/api/v2/sports/nfl/predictions'],
    ['mlb', '/api/v2/sports/mlb/predictions'],
    ['cfb', '/api/v2/sports/cfb/predictions'],
    ['wnba', '/api/v2/sports/wnba/predictions'],
]);

it('requires authentication for core sports api routes', function (string $sport, string $path) {
    $this->getJson($path)
        ->assertUnauthorized();
})->with('core_sport_api_paths');

it('requires auth on protected sports api routes', function (string $sport, string $path) {
    $this->getJson($path)
        ->assertUnauthorized();
})->with('protected_sport_api_paths');

it('denies authenticated users without API entitlement', function (string $sport, string $path) {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson($path)
        ->assertForbidden();
})->with('protected_sport_api_paths');

it('also allows authenticated users with sport api permission', function (string $sport, string $path) {
    $user = User::factory()->create();
    grantPermission($user, "view-{$sport}-predictions");
    grantPermission($user, 'access-api');
    Sanctum::actingAs($user);

    $this->getJson($path)
        ->assertOk();
})->with('protected_sport_api_paths');
