<?php

use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

test('group creation rolls back when owner membership fails and the same request can safely retry', function () {
    $user = User::factory()->create();
    $connection = DB::connection();
    $originalDispatcher = $connection->getEventDispatcher();
    $faultDispatcher = clone $originalDispatcher;
    $faultDispatcher->listen(QueryExecuted::class, function (QueryExecuted $query): void {
        if (preg_match('/insert into ["`]?group_users/i', $query->sql) === 1) {
            throw new RuntimeException('Injected membership failure');
        }
    });
    $connection->setEventDispatcher($faultDispatcher);
    $payload = ['name' => 'Atomic pool', 'sport' => 'cbb', 'season' => 2026];

    try {
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'group-owner-failure')
            ->postJson('/api/v2/groups', $payload)
            ->assertStatus(500);
    } finally {
        $connection->setEventDispatcher($originalDispatcher);
    }

    expect(Group::query()->count())->toBe(0)
        ->and(DB::table('group_users')->count())->toBe(0);

    $this->postJson('/api/v2/groups', $payload)->assertCreated();

    $group = Group::query()->sole();
    expect($group->owner_id)->toBe($user->id)
        ->and($group->users()->whereKey($user->id)->sole()->pivot->role)->toBe('owner')
        ->and(DB::table('group_users')->count())->toBe(1);
});

test('authenticated user can create and list cbb bracket groups', function () {
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
        ->patchJson("/api/v2/groups/{$group->public_id}", [
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
