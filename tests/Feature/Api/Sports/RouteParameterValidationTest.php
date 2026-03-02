<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('returns 404 for non-numeric identifiers on numeric sports endpoints', function (string $path) {
    $this->getJson($path)->assertNotFound();
})->with([
    '/api/v1/nba/teams/not-a-number',
    '/api/v1/nba/players/not-a-number',
    '/api/v1/nba/teams/not-a-number/players',
    '/api/v1/nba/games/not-a-number',
    '/api/v1/nba/teams/not-a-number/games',
    '/api/v1/nba/games/season/not-a-number',
    '/api/v1/nba/games/season/2025/week/not-a-number',
    '/api/v1/nba/plays/not-a-number',
    '/api/v1/nba/games/not-a-number/plays',
    '/api/v1/nba/player-stats/not-a-number',
    '/api/v1/nba/games/not-a-number/player-stats',
    '/api/v1/nba/players/not-a-number/stats',
    '/api/v1/nba/team-stats/not-a-number',
    '/api/v1/nba/games/not-a-number/team-stats',
    '/api/v1/nba/teams/not-a-number/stats',
    '/api/v1/nba/elo-ratings/not-a-number',
    '/api/v1/nba/teams/not-a-number/elo-ratings',
    '/api/v1/nba/elo-ratings/season/not-a-number',
]);

it('returns 401 for protected trends endpoint when unauthenticated', function () {
    $this->getJson('/api/v1/nba/teams/not-a-number/trends')
        ->assertUnauthorized();
});

it('returns 404 for protected trends endpoint with invalid id when authorized', function () {
    Permission::findOrCreate('view-nba-predictions', 'web');

    $user = User::factory()->create();
    $user->givePermissionTo('view-nba-predictions');
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/nba/teams/not-a-number/trends')
        ->assertNotFound();
});
