<?php

use App\Models\Passkey;
use App\Models\User;
use App\Services\Auth\PasskeyService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['passkeys.enabled' => true, 'passkeys.rp_id' => 'localhost', 'passkeys.origin' => 'http://localhost']);
    $service = Mockery::mock(PasskeyService::class)->makePartial();
    // Registration ownership proof only: retain real challenge/origin/RP/UV parsing.
    $service->shouldReceive('publicKeyPemFromSpki')->andReturn('synthetic-public-key-decoding-boundary');
    app()->instance(PasskeyService::class, $service);
});

function registrationOwnershipPayload($test, User $user, string $credential): array
{
    $response = $test->actingAs($user)->postJson('/passkeys/registration/options')->assertOk();
    $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

    return ['name' => 'Owned synthetic key', 'credential_id' => $credential,
        'public_key' => $encode('synthetic-SPKI'), 'algorithm' => -7,
        'client_data_json' => $encode(json_encode(['type' => 'webauthn.create',
            'challenge' => $response->json('publicKey.challenge'), 'origin' => 'http://localhost'], JSON_THROW_ON_ERROR)),
        'authenticator_data' => $encode(hash('sha256', 'localhost', true).chr(5).pack('N', 9)),
        'transports' => ['internal']];
}

function registrationOwnedKey(User $user, string $credential): Passkey
{
    return $user->passkeys()->create(['credential_id' => $credential, 'name' => 'Existing owner key',
        'public_key' => 'synthetic-existing-public-key', 'algorithm' => -7, 'sign_count' => 1]);
}

it('refuses a foreign owner inserted between enrollment lookup and persistence', function () {
    $user = User::factory()->create();
    $foreign = User::factory()->create();
    $credential = 'synthetic-intervening-credential';
    $payload = registrationOwnershipPayload($this, $user, $credential);
    $connection = (new Passkey)->getConnection();
    $original = $connection->getEventDispatcher();
    $dispatcher = clone $original;
    $seen = false;
    $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query) use ($credential, $foreign, &$seen): void {
        if (! $seen && str_starts_with(strtolower($query->sql), 'select')
            && str_contains($query->sql, 'passkeys') && in_array($credential, $query->bindings, true)) {
            $seen = true;
            registrationOwnedKey($foreign, $credential);
        }
    });
    $connection->setEventDispatcher($dispatcher);
    try {
        $response = $this->postJson('/passkeys/registration/verify', $payload);
    } finally {
        $connection->setEventDispatcher($original);
    }
    expect($seen)->toBeTrue();
    expect(Passkey::query()->where('credential_id', $credential)->value('user_id'))->not->toBe($user->id);
    $response->assertUnprocessable();
    Http::assertNothingSent();
});

it('preserves an existing foreign credential and refuses enrollment', function () {
    $user = User::factory()->create();
    $key = registrationOwnedKey(User::factory()->create(), 'synthetic-foreign');
    $before = $key->fresh()->getRawOriginal();
    $payload = registrationOwnershipPayload($this, $user, $key->credential_id);
    $this->postJson('/passkeys/registration/verify', $payload)->assertUnprocessable();
    expect($key->fresh()->getRawOriginal())->toBe($before);
});

it('retains same owner row identity on repeated enrollment', function () {
    $user = User::factory()->create();
    $key = registrationOwnedKey($user, 'synthetic-same-owner');
    foreach (['First name', 'Updated name'] as $name) {
        $payload = registrationOwnershipPayload($this, $user, $key->credential_id);
        $payload['name'] = $name;
        $this->postJson('/passkeys/registration/verify', $payload)->assertOk()->assertJsonPath('passkey.id', $key->id);
        expect(Passkey::query()->where('credential_id', $key->credential_id)->count())->toBe(1)
            ->and($key->fresh()->user_id)->toBe($user->id)->and($key->fresh()->name)->toBe($name);
    }
});

it('refuses cancelled passkey persistence without a saved success response', function (bool $existing) {
    $user = User::factory()->create();
    $credential = 'synthetic-cancelled';
    if ($existing) {
        registrationOwnedKey($user, $credential);
    }
    $before = Passkey::query()->get()->map->getRawOriginal()->all();
    $payload = registrationOwnershipPayload($this, $user, $credential);
    $original = Passkey::getEventDispatcher();
    Passkey::setEventDispatcher(clone $original);
    $seen = false;
    Passkey::saving(function () use (&$seen): bool {
        $seen = true;

        return false;
    });
    try {
        $response = $this->postJson('/passkeys/registration/verify', $payload);
    } finally {
        Passkey::setEventDispatcher($original);
    }
    expect($seen)->toBeTrue();
    $response->assertUnprocessable();
    expect(Passkey::query()->get()->map->getRawOriginal()->all())->toBe($before);
})->with([false, true]);
