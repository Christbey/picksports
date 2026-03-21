<?php

use App\Models\CbbBracket;
use App\Models\Group;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-03-18 12:00:00', 'America/Chicago'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('authenticated user can fetch current cbb bracket for a season', function () {
    $user = User::factory()->create();

    $bracket = CbbBracket::query()->create([
        'user_id' => $user->id,
        'season' => 2026,
        'picks' => [
            'game:1' => 'team:10',
            'game:2' => 'team:12',
        ],
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/cbb-brackets/current?season=2026')
        ->assertOk()
        ->assertJsonPath('data.id', $bracket->id)
        ->assertJsonPath('data.public_id', $bracket->public_id)
        ->assertJsonPath('data.season', 2026)
        ->assertJsonPath('data.picks.game:1', 'team:10')
        ->assertJsonPath('data.points_earned', 0)
        ->assertJsonPath('data.is_locked', false)
        ->assertJsonPath('data.results', []);
});

test('authenticated user can upsert current cbb bracket for a season', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/v1/cbb-brackets/current', [
            'season' => 2026,
            'picks' => [
                'game:1' => 'team:10',
                'East-round_of_32-0' => 'team:10',
            ],
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.season', 2026)
        ->assertJsonPath('data.picks.game:1', 'team:10')
        ->assertJsonPath('data.points_earned', 0)
        ->assertJsonPath('data.is_locked', false)
        ->assertJsonPath('data.correct_picks', 0)
        ->assertJsonPath('data.incorrect_picks', 0);

    $this->assertDatabaseHas('cbb_brackets', [
        'user_id' => $user->id,
        'season' => 2026,
    ]);

    $this->actingAs($user)
        ->putJson('/api/v1/cbb-brackets/current', [
            'season' => 2026,
            'picks' => [
                'game:1' => 'team:99',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.picks.game:1', 'team:99');

    expect(CbbBracket::query()->where('user_id', $user->id)->where('season', 2026)->count())->toBe(1);
});

test('authenticated user can manage multiple brackets for the same season', function () {
    $user = User::factory()->create();
    $group = Group::query()->create([
        'owner_id' => $user->id,
        'name' => 'Office Pool',
        'type' => 'bracket_pool',
        'sport' => 'cbb',
        'season' => 2026,
    ]);
    $group->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

    $firstResponse = $this->actingAs($user)
        ->postJson('/api/v1/cbb-brackets', [
            'season' => 2026,
            'name' => 'Bracket A',
            'group_id' => $group->id,
            'picks' => [
                'game:1' => 'team:10',
            ],
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'Bracket A')
        ->assertJsonPath('data.group_id', $group->id)
        ->assertJsonPath('data.group.name', 'Office Pool');

    $secondResponse = $this->actingAs($user)
        ->postJson('/api/v1/cbb-brackets', [
            'season' => 2026,
            'name' => 'Bracket B',
            'picks' => [
                'game:1' => 'team:11',
            ],
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'Bracket B');

    $firstPublicId = $firstResponse->json('data.public_id');
    $secondPublicId = $secondResponse->json('data.public_id');

    expect($firstPublicId)->not->toBe($secondPublicId);

    $this->actingAs($user)
        ->getJson('/api/v1/cbb-brackets?season=2026')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($user)
        ->patchJson("/api/v1/cbb-brackets/{$firstPublicId}", [
            'name' => 'Bracket A Updated',
            'group_id' => $group->id,
            'picks' => [
                'game:1' => 'team:99',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Bracket A Updated')
        ->assertJsonPath('data.group_id', $group->id)
        ->assertJsonPath('data.picks.game:1', 'team:99');

    $this->actingAs($user)
        ->getJson("/api/v1/cbb-brackets/{$secondPublicId}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Bracket B')
        ->assertJsonPath('data.picks.game:1', 'team:11');
});

test('cbb bracket api requires authentication', function () {
    $this->getJson('/api/v1/cbb-brackets/current?season=2026')->assertUnauthorized();
    $this->putJson('/api/v1/cbb-brackets/current', [
        'season' => 2026,
        'picks' => [],
    ])->assertUnauthorized();
});

test('authenticated user cannot update a bracket after the lock time', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-19 11:00:00', 'America/Chicago'));

    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/v1/cbb-brackets/current', [
            'season' => 2026,
            'picks' => [
                'game:1' => 'team:10',
            ],
        ])
        ->assertStatus(423)
        ->assertJsonPath('message', 'Bracket is locked.');

    $this->assertDatabaseMissing('cbb_brackets', [
        'user_id' => $user->id,
        'season' => 2026,
    ]);
});
