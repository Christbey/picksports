<?php

use App\Models\Passkey;
use App\Models\User;

function makePasskeyForUser(User $user): Passkey
{
    return Passkey::query()->create([
        'user_id' => $user->id,
        'name' => 'Test Key',
        'credential_id' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
        'public_key' => "-----BEGIN PUBLIC KEY-----\nFAKEKEY\n-----END PUBLIC KEY-----\n",
        'algorithm' => -7,
        'sign_count' => 1,
        'transports' => ['internal'],
    ]);
}

it('allows an authenticated user to delete their own passkey', function () {
    $user = User::factory()->create();
    $passkey = makePasskeyForUser($user);

    $response = $this->actingAs($user)->deleteJson("/passkeys/{$passkey->id}");

    $response
        ->assertOk()
        ->assertJson([
            'message' => 'Passkey deleted.',
        ]);

    $this->assertDatabaseMissing('passkeys', [
        'id' => $passkey->id,
    ]);
});

it('returns 404 when deleting another users passkey', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $passkey = makePasskeyForUser($owner);

    $this->actingAs($otherUser)
        ->deleteJson("/passkeys/{$passkey->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('passkeys', [
        'id' => $passkey->id,
    ]);
});

it('requires authentication to delete passkeys', function () {
    $passkey = makePasskeyForUser(User::factory()->create());

    $this->deleteJson("/passkeys/{$passkey->id}")
        ->assertStatus(401);
});
