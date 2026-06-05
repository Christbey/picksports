<?php

use App\Models\Group;
use App\Models\User;

test('authenticated user can create and list cbb bracket groups', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/groups', [
            'name' => 'Friends Pool',
            'type' => 'bracket_pool',
            'sport' => 'cbb',
            'season' => 2026,
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'Friends Pool')
        ->assertJsonPath('data.type', 'bracket_pool')
        ->assertJsonPath('data.sport', 'cbb')
        ->assertJsonPath('data.season', 2026);

    $this->actingAs($user)
        ->getJson('/api/v1/groups?type=bracket_pool&sport=cbb&season=2026')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Friends Pool');

    expect(Group::query()->count())->toBe(1);
});

test('authenticated user can create and list cbb bracket groups via API v2', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v2/groups', [
            'name' => 'Friends Pool',
            'type' => 'bracket_pool',
            'sport' => 'cbb',
            'season' => 2026,
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'Friends Pool')
        ->assertJsonPath('data.type', 'bracket_pool')
        ->assertJsonPath('data.sport', 'cbb')
        ->assertJsonPath('data.season', 2026);

    $this->actingAs($user)
        ->getJson('/api/v2/groups?type=bracket_pool&sport=cbb&season=2026')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Friends Pool');

    expect(Group::query()->count())->toBe(1);
});

test('authenticated user can rename owned groups', function () {
    $user = User::factory()->create();

    $group = Group::query()->create([
        'owner_id' => $user->id,
        'name' => 'Original Pool',
        'type' => 'bracket_pool',
        'sport' => 'cbb',
        'season' => 2026,
    ]);
    $group->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

    $this->actingAs($user)
        ->patchJson("/api/v1/groups/{$group->public_id}", [
            'name' => 'Renamed Pool',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed Pool');
});

test('authenticated user can rename owned groups via API v2', function () {
    $user = User::factory()->create();

    $group = Group::query()->create([
        'owner_id' => $user->id,
        'name' => 'Original Pool',
        'type' => 'bracket_pool',
        'sport' => 'cbb',
        'season' => 2026,
    ]);
    $group->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

    $this->actingAs($user)
        ->patchJson("/api/v2/groups/{$group->public_id}", [
            'name' => 'Renamed Pool',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed Pool');
});
