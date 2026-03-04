<?php

use App\Models\OddsApiTeamMapping;
use App\Models\User;
use App\Services\Settings\FoundingUsersSettingsService;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

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

it('returns founding users panel data on admin settings page', function () {
    config()->set('founding_users.enabled', true);
    config()->set('founding_users.limit', 5);
    config()->set('founding_users.role', 'founding_user');
    config()->set('founding_users.tier_slug', 'premium');

    Role::query()->firstOrCreate([
        'name' => 'founding_user',
        'guard_name' => 'web',
    ]);

    $admin = User::factory()->admin()->create();
    $founder = User::factory()->create();
    $founder->assignRole('founding_user');

    $this->actingAs($admin)
        ->get('/settings/admin')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Admin')
            ->where('foundingUsers.enabled', true)
            ->where('foundingUsers.limit', 5)
            ->where('foundingUsers.used', 1)
            ->where('foundingUsers.remaining', 4)
            ->where('foundingUsers.role', 'founding_user')
            ->has('foundingUsers.users', 1)
        );
});

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

it('forbids non-admin users from granting founding access', function () {
    config()->set('founding_users.enabled', true);
    config()->set('founding_users.limit', 1);

    $user = User::factory()->create();
    $target = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/admin/founding-users/grant', [
            'email' => $target->email,
        ])
        ->assertForbidden();
});

it('forbids non-admin users from searching founding user candidates', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/settings/admin/founding-users/search?query=jo')
        ->assertForbidden();
});

it('forbids non-admin users from revoking founding access', function () {
    config()->set('founding_users.enabled', true);
    config()->set('founding_users.role', 'founding_user');

    Role::query()->firstOrCreate([
        'name' => 'founding_user',
        'guard_name' => 'web',
    ]);

    $user = User::factory()->create();
    $target = User::factory()->create();
    $target->assignRole('founding_user');

    $this->actingAs($user)
        ->post('/settings/admin/founding-users/revoke', [
            'user_id' => $target->id,
        ])
        ->assertForbidden();
});

it('forbids non-admin users from updating founding user limit', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/admin/founding-users/limit', [
            'limit' => 99,
        ])
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

it('allows admin users to grant and revoke founding access from settings', function () {
    config()->set('founding_users.enabled', true);
    config()->set('founding_users.limit', 1);
    config()->set('founding_users.role', 'founding_user');
    config()->set('founding_users.tier_slug', 'premium');

    Role::query()->firstOrCreate([
        'name' => 'founding_user',
        'guard_name' => 'web',
    ]);

    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->post('/settings/admin/founding-users/grant', [
            'email' => $target->email,
        ])
        ->assertRedirect();

    expect($target->fresh()->hasRole('founding_user'))->toBeTrue();

    $this->actingAs($admin)
        ->post('/settings/admin/founding-users/revoke', [
            'user_id' => $target->id,
        ])
        ->assertRedirect();

    expect($target->fresh()->hasRole('founding_user'))->toBeFalse();
});

it('allows admin users to update founding user limit from settings', function () {
    config()->set('founding_users.limit', 5);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post('/settings/admin/founding-users/limit', [
            'limit' => 42,
        ])
        ->assertRedirect();

    $this->actingAs($admin)
        ->get('/settings/admin')
        ->assertInertia(fn (Assert $page) => $page
            ->where('foundingUsers.limit', 42)
        );

    expect(app(FoundingUsersSettingsService::class)->getLimit())->toBe(42);
});

it('allows admin users to search founding user candidates and excludes existing founders', function () {
    config()->set('founding_users.role', 'founding_user');

    Role::query()->firstOrCreate([
        'name' => 'founding_user',
        'guard_name' => 'web',
    ]);

    $admin = User::factory()->admin()->create();
    $candidate = User::factory()->create([
        'name' => 'John Candidate',
        'email' => 'john.candidate@example.com',
    ]);
    $founder = User::factory()->create([
        'name' => 'John Founder',
        'email' => 'john.founder@example.com',
    ]);
    $founder->assignRole('founding_user');

    $response = $this->actingAs($admin)
        ->get('/settings/admin/founding-users/search?query=john')
        ->assertOk()
        ->assertJsonStructure(['users']);

    $users = $response->json('users');
    $emails = collect($users)->pluck('email')->all();

    expect($emails)->toContain($candidate->email);
    expect($emails)->not->toContain($founder->email);
});
