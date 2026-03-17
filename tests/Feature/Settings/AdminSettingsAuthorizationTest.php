<?php

use App\Models\CbbBracket;
use App\Models\Group;
use App\Models\OddsApiTeamMapping;
use App\Models\OddsApiPlayerMapping;
use App\Models\User;
use App\Services\Settings\FoundingUsersSettingsService;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

it('requires authentication for settings admin pages', function (string $path) {
    $this->get($path)
        ->assertRedirect(route('login'));
})->with([
    '/settings/admin',
    '/settings/prop-exports',
    '/settings/team-mappings',
    '/settings/player-mappings',
]);

it('forbids non-admin users from settings admin pages', function (string $path) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get($path)
        ->assertForbidden();
})->with([
    '/settings/admin',
    '/settings/prop-exports',
    '/settings/team-mappings',
    '/settings/player-mappings',
]);

it('allows admin users to access settings admin pages', function (string $path) {
    $this->withoutVite();

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get($path)
        ->assertOk();
})->with([
    '/settings/admin',
    '/settings/prop-exports',
    '/settings/team-mappings',
    '/settings/player-mappings',
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

it('allows admin users to search assignable users for their group', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create([
        'name' => 'Group Target',
        'email' => 'target@example.com',
    ]);
    $existingMember = User::factory()->create();

    $group = Group::query()->create([
        'owner_id' => $admin->id,
        'name' => 'Office Pool',
        'type' => 'bracket_pool',
        'sport' => 'cbb',
        'season' => 2026,
    ]);
    $group->users()->attach($admin->id, ['role' => 'owner', 'joined_at' => now()]);
    $group->users()->attach($existingMember->id, ['role' => 'member', 'joined_at' => now()]);

    $this->actingAs($admin)
        ->getJson("/settings/admin/groups/users/search?group_id={$group->id}&query=target")
        ->assertOk()
        ->assertJsonPath('users.0.email', 'target@example.com')
        ->assertJsonMissing(['email' => $existingMember->email]);
});

it('allows admin users to add an existing user to a group', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();

    $group = Group::query()->create([
        'owner_id' => $admin->id,
        'name' => 'Office Pool',
        'type' => 'bracket_pool',
        'sport' => 'cbb',
        'season' => 2026,
    ]);
    $group->users()->attach($admin->id, ['role' => 'owner', 'joined_at' => now()]);

    $this->actingAs($admin)
        ->post('/settings/admin/groups/users', [
            'group_id' => $group->id,
            'user_id' => $member->id,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('group_users', [
        'group_id' => $group->id,
        'user_id' => $member->id,
        'role' => 'member',
    ]);
});

it('allows admin users to remove a user from a group and clears their bracket group assignment', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();

    $group = Group::query()->create([
        'owner_id' => $admin->id,
        'name' => 'Office Pool',
        'type' => 'bracket_pool',
        'sport' => 'cbb',
        'season' => 2026,
    ]);
    $group->users()->attach($admin->id, ['role' => 'owner', 'joined_at' => now()]);
    $group->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

    $bracket = CbbBracket::query()->create([
        'user_id' => $member->id,
        'group_id' => $group->id,
        'season' => 2026,
        'name' => 'Member Bracket',
        'picks' => [],
    ]);

    $this->actingAs($admin)
        ->delete('/settings/admin/groups/users', [
            'group_id' => $group->id,
            'user_id' => $member->id,
        ])
        ->assertRedirect();

    $this->assertDatabaseMissing('group_users', [
        'group_id' => $group->id,
        'user_id' => $member->id,
    ]);

    expect($bracket->fresh()->group_id)->toBeNull();
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

it('forbids non-admin users from updating player mappings', function () {
    $user = User::factory()->create();
    $mapping = OddsApiPlayerMapping::query()->create([
        'sport' => 'basketball_nba',
        'odds_api_player_name' => 'J. Brown',
        'espn_player_name' => 'Jaylen Brown',
    ]);

    $this->actingAs($user)
        ->patch("/settings/player-mappings/{$mapping->id}", [
            'espn_player_name' => 'Jaylen Brown',
        ])
        ->assertForbidden();
});

it('forbids non-admin users from clearing player mappings', function () {
    $user = User::factory()->create();
    $mapping = OddsApiPlayerMapping::query()->create([
        'sport' => 'basketball_nba',
        'odds_api_player_name' => 'A. Edwards',
        'espn_player_name' => 'Anthony Edwards',
    ]);

    $this->actingAs($user)
        ->delete("/settings/player-mappings/{$mapping->id}")
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

it('allows admin users to update player mappings', function () {
    $admin = User::factory()->admin()->create();
    $mapping = OddsApiPlayerMapping::query()->create([
        'sport' => 'basketball_nba',
        'odds_api_player_name' => 'S. Gilgeous-Alexander',
        'espn_player_name' => null,
        'espn_player_id' => null,
    ]);

    $this->actingAs($admin)
        ->patch("/settings/player-mappings/{$mapping->id}", [
            'espn_player_name' => 'Shai Gilgeous-Alexander',
            'espn_player_id' => 42,
        ])
        ->assertRedirect();

    $mapping->refresh();
    expect($mapping->espn_player_name)->toBe('Shai Gilgeous-Alexander');
    expect($mapping->espn_player_id)->toBe(42);
});

it('allows admin users to clear player mappings', function () {
    $admin = User::factory()->admin()->create();
    $mapping = OddsApiPlayerMapping::query()->create([
        'sport' => 'basketball_nba',
        'odds_api_player_name' => 'L. Doncic',
        'espn_player_name' => 'Luka Doncic',
        'espn_player_id' => 77,
    ]);

    $this->actingAs($admin)
        ->delete("/settings/player-mappings/{$mapping->id}")
        ->assertRedirect();

    $mapping->refresh();
    expect($mapping->espn_player_name)->toBeNull();
    expect($mapping->espn_player_id)->toBeNull();
});

it('clears suggested player mapping fields after admin accepts a suggestion', function () {
    $admin = User::factory()->admin()->create();
    $mapping = OddsApiPlayerMapping::query()->create([
        'sport' => 'basketball_nba',
        'odds_api_player_name' => 'S. Gilgeous-Alexander',
        'espn_player_name' => null,
        'espn_player_id' => null,
        'suggested_espn_player_name' => 'Shai Gilgeous-Alexander',
        'suggested_player_id' => 12,
        'suggested_match_quality_score' => 88,
    ]);

    $this->actingAs($admin)
        ->patch("/settings/player-mappings/{$mapping->id}", [
            'espn_player_name' => 'Shai Gilgeous-Alexander',
            'espn_player_id' => 12,
        ])
        ->assertRedirect();

    $mapping->refresh();
    expect($mapping->espn_player_name)->toBe('Shai Gilgeous-Alexander');
    expect($mapping->espn_player_id)->toBe(12);
    expect($mapping->suggested_espn_player_name)->toBeNull();
    expect($mapping->suggested_player_id)->toBeNull();
    expect($mapping->suggested_match_quality_score)->toBeNull();
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
