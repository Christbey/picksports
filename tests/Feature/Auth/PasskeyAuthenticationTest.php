<?php

use App\Models\Passkey;
use App\Models\User;

function b64urlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function pemToDer(string $pem): string
{
    $clean = preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----|\s+/', '', $pem);

    return base64_decode((string) $clean, true) ?: '';
}

function fakeAuthenticatorData(int $signCount = 1): string
{
    $configuredRpId = (string) (config('passkeys.rp_id') ?? '');
    $rpId = $configuredRpId !== '' ? $configuredRpId : (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    $rpIdHash = hash('sha256', $rpId, true);
    $flags = chr(0x05); // user present + user verified

    return $rpIdHash.$flags.pack('N', $signCount);
}

test('authenticated user can register a passkey', function () {
    config([
        'passkeys.algorithms' => [-257],
        'passkeys.rp_id' => parse_url((string) config('app.url'), PHP_URL_HOST),
        'passkeys.origin' => rtrim((string) config('app.url'), '/'),
    ]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/passkeys/registration/options');

    $response->assertOk();

    $challenge = $response->json('publicKey.challenge');

    expect($challenge)->not->toBeEmpty();

    $keyResource = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);

    expect($keyResource)->not->toBeFalse();

    openssl_pkey_export($keyResource, $privatePem);
    $details = openssl_pkey_get_details($keyResource);

    expect($details)->toBeArray();

    $publicPem = $details['key'];
    $clientDataJson = json_encode([
        'type' => 'webauthn.create',
        'challenge' => $challenge,
        'origin' => rtrim((string) config('passkeys.origin', config('app.url')), '/'),
    ], JSON_UNESCAPED_SLASHES);

    $register = $this->actingAs($user)->postJson('/passkeys/registration/verify', [
        'name' => 'MacBook',
        'credential_id' => b64urlEncode(random_bytes(32)),
        'public_key' => b64urlEncode(pemToDer($publicPem)),
        'algorithm' => -257,
        'client_data_json' => b64urlEncode($clientDataJson ?: ''),
        'authenticator_data' => b64urlEncode(fakeAuthenticatorData(9)),
        'transports' => ['internal'],
    ]);

    $register->assertOk();

    $this->assertDatabaseCount('passkeys', 1);
    $this->assertDatabaseHas('passkeys', [
        'user_id' => $user->id,
        'name' => 'MacBook',
        'sign_count' => 9,
    ]);
});

test('guest user can authenticate with passkey assertion', function () {
    config([
        'passkeys.algorithms' => [-257],
        'passkeys.rp_id' => parse_url((string) config('app.url'), PHP_URL_HOST),
        'passkeys.origin' => rtrim((string) config('app.url'), '/'),
    ]);

    $user = User::factory()->create();

    $keyResource = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);

    expect($keyResource)->not->toBeFalse();

    openssl_pkey_export($keyResource, $privatePem);
    $details = openssl_pkey_get_details($keyResource);
    $publicPem = $details['key'];

    $credentialId = b64urlEncode(random_bytes(32));

    Passkey::query()->create([
        'user_id' => $user->id,
        'name' => 'Test Key',
        'credential_id' => $credentialId,
        'public_key' => $publicPem,
        'algorithm' => -257,
        'sign_count' => 1,
    ]);

    $options = $this->postJson('/passkeys/authentication/options', [
        'email' => $user->email,
    ]);

    $options->assertOk();

    $challenge = $options->json('publicKey.challenge');
    $challengeId = $options->json('challenge_id');

    expect($challengeId)->not->toBeEmpty();

    $clientDataJson = json_encode([
        'type' => 'webauthn.get',
        'challenge' => $challenge,
        'origin' => rtrim((string) config('passkeys.origin', config('app.url')), '/'),
    ], JSON_UNESCAPED_SLASHES);

    $authenticatorDataRaw = fakeAuthenticatorData(2);
    $signaturePayload = $authenticatorDataRaw.hash('sha256', $clientDataJson ?: '', true);

    openssl_sign($signaturePayload, $signature, $privatePem, OPENSSL_ALGO_SHA256);

    $verify = $this->postJson('/passkeys/authentication/verify', [
        'challenge_id' => $challengeId,
        'credential_id' => $credentialId,
        'client_data_json' => b64urlEncode($clientDataJson ?: ''),
        'authenticator_data' => b64urlEncode($authenticatorDataRaw),
        'signature' => b64urlEncode($signature),
    ]);

    $verify->assertOk()->assertJsonStructure(['redirect']);
    $this->assertAuthenticatedAs($user);

    expect(Passkey::query()->where('credential_id', $credentialId)->value('sign_count'))->toBe(2);
});
