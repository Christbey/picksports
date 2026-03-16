<?php

use App\Models\Group;
use App\Models\GroupJoinLink;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('group join link lets a new user register without age verification and redirects to the bracket page', function () {
    $admin = User::factory()->create();

    $group = Group::query()->create([
        'owner_id' => $admin->id,
        'name' => 'Office Pool',
        'type' => 'bracket_pool',
        'sport' => 'cbb',
        'season' => 2026,
    ]);
    $group->users()->attach($admin->id, ['role' => 'owner', 'joined_at' => now()]);

    $joinLink = GroupJoinLink::query()->create([
        'group_id' => $group->id,
        'created_by' => $admin->id,
    ]);

    $this->get("/groups/join/{$joinLink->token}")
        ->assertRedirect("/register?join={$joinLink->token}");

    $response = $this->post('/register', [
        'name' => 'Join Link User',
        'email' => 'joiner@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'join_token' => $joinLink->token,
    ]);

    $response->assertRedirect('/march-madness-bracket');

    $user = User::query()->where('email', 'joiner@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->age_verified_at)->not->toBeNull()
        ->and(Hash::check('password', $user->password))->toBeTrue();

    $this->assertDatabaseHas('group_users', [
        'group_id' => $group->id,
        'user_id' => $user->id,
        'role' => 'member',
    ]);
});
