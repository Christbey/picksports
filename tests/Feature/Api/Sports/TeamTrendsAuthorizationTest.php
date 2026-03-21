<?php

use App\Models\NBA\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    config()->set('subscriptions.enforce_tiers', true);
});

it('requires sanctum auth for team trends endpoint', function () {
    $team = Team::factory()->create();

    $this->getJson("/api/v1/nba/teams/{$team->id}/trends")
        ->assertUnauthorized();
});

it('denies authenticated users without sport permission on team trends endpoint', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/nba/teams/{$team->id}/trends")
        ->assertForbidden();
});

it('allows authenticated users with sport permission on team trends endpoint', function () {
    Permission::findOrCreate('view-nba-predictions', 'web');

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $user->givePermissionTo('view-nba-predictions');
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/nba/teams/{$team->id}/trends")
        ->assertOk();
});
