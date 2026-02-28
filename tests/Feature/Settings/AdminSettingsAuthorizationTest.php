<?php

use App\Models\OddsApiTeamMapping;
use App\Models\User;

it('requires authentication for settings admin pages', function (string $path) {
    $this->get($path)
        ->assertRedirect(route('login'));
})->with([
    '/settings/admin',
    '/settings/team-mappings',
]);

it('forbids non-admin users from settings admin pages', function (string $path) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get($path)
        ->assertForbidden();
})->with([
    '/settings/admin',
    '/settings/team-mappings',
]);

it('allows admin users to access settings admin pages', function (string $path) {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get($path)
        ->assertOk();
})->with([
    '/settings/admin',
    '/settings/team-mappings',
]);

it('forbids non-admin users from updating team mappings', function () {
    $user = User::factory()->create();
    $mapping = OddsApiTeamMapping::query()->create([
        'sport' => 'basketball_ncaab',
        'odds_api_team_name' => 'Purdue Boilermakers',
        'espn_team_name' => 'Purdue',
    ]);

    $this->actingAs($user)
        ->patch("/settings/team-mappings/{$mapping->id}", [
            'espn_team_name' => 'Purdue Boilermakers',
        ])
        ->assertForbidden();
});

it('forbids non-admin users from clearing team mappings', function () {
    $user = User::factory()->create();
    $mapping = OddsApiTeamMapping::query()->create([
        'sport' => 'basketball_ncaab',
        'odds_api_team_name' => 'Michigan State Spartans',
        'espn_team_name' => 'Michigan State',
    ]);

    $this->actingAs($user)
        ->delete("/settings/team-mappings/{$mapping->id}")
        ->assertForbidden();
});

it('allows admin users to update team mappings', function () {
    $admin = User::factory()->admin()->create();
    $mapping = OddsApiTeamMapping::query()->create([
        'sport' => 'basketball_ncaab',
        'odds_api_team_name' => 'Duke Blue Devils',
        'espn_team_name' => 'Duke',
    ]);

    $this->actingAs($admin)
        ->patch("/settings/team-mappings/{$mapping->id}", [
            'espn_team_name' => 'Duke Blue Devils',
        ])
        ->assertRedirect();

    $mapping->refresh();
    expect($mapping->espn_team_name)->toBe('Duke Blue Devils');
});

it('allows admin users to clear team mappings', function () {
    $admin = User::factory()->admin()->create();
    $mapping = OddsApiTeamMapping::query()->create([
        'sport' => 'basketball_ncaab',
        'odds_api_team_name' => 'Kansas Jayhawks',
        'espn_team_name' => 'Kansas',
    ]);

    $this->actingAs($admin)
        ->delete("/settings/team-mappings/{$mapping->id}")
        ->assertRedirect();

    $mapping->refresh();
    expect($mapping->espn_team_name)->toBeNull();
});
