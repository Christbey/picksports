<?php

use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('group invite lets a new user register without age verification and redirects to the bracket page', function () {
    $admin = User::factory()->create();

    $group = Group::query()->create([
        'owner_id' => $admin->id,
        'name' => 'Office Pool',
        'type' => 'bracket_pool',
        'sport' => 'cbb',
        'season' => 2026,
    ]);
    $group->users()->attach($admin->id, ['role' => 'owner', 'joined_at' => now()]);

    $invitation = GroupInvitation::query()->create([
        'group_id' => $group->id,
        'invited_by' => $admin->id,
        'email' => 'invitee@example.com',
        'expires_at' => now()->addDays(7),
    ]);

    $this->get("/group-invitations/{$invitation->token}")
        ->assertRedirect("/register?invite={$invitation->token}");

    $response = $this->post('/register', [
        'name' => 'Invited User',
        'email' => 'invitee@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invite_token' => $invitation->token,
    ]);

    $response->assertRedirect('/march-madness-bracket');

    $user = User::query()->where('email', 'invitee@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->age_verified_at)->not->toBeNull()
        ->and(Hash::check('password', $user->password))->toBeTrue();

    $this->assertDatabaseHas('group_users', [
        'group_id' => $group->id,
        'user_id' => $user->id,
        'role' => 'member',
    ]);

    $this->assertDatabaseHas('group_invitations', [
        'id' => $invitation->id,
        'accepted_by' => $user->id,
    ]);
});
