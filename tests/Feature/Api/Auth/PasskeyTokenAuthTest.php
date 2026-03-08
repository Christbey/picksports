<?php

use App\Models\Passkey;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

function apiB64urlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function apiFakeAuthenticatorData(int $signCount = 1): string
{
    $configuredRpId = (string) (config('passkeys.rp_id') ?? '');
    $rpId = $configuredRpId !== '' ? $configuredRpId : (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    $rpIdHash = hash('sha256', $rpId, true);
    $flags = chr(0x05); // user present + user verified

    return $rpIdHash.$flags.pack('N', $signCount);
}

it('issues sanctum token from passkey verification via api auth endpoints', function () {
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

    $credentialId = apiB64urlEncode(random_bytes(32));

    Passkey::query()->create([
        'user_id' => $user->id,
        'name' => 'API Key',
        'credential_id' => $credentialId,
        'public_key' => $publicPem,
        'algorithm' => -257,
        'sign_count' => 1,
    ]);

    $options = $this->postJson('/api/v1/auth/passkeys/options', [
        'email' => $user->email,
    ]);

    $options->assertOk();
    $challenge = $options->json('publicKey.challenge');
    $challengeId = $options->json('challenge_id');

    expect($challenge)->not->toBeEmpty();
    expect($challengeId)->not->toBeEmpty();

    $clientDataJson = json_encode([
        'type' => 'webauthn.get',
        'challenge' => $challenge,
        'origin' => rtrim((string) config('passkeys.origin', config('app.url')), '/'),
    ], JSON_UNESCAPED_SLASHES);

    $authenticatorDataRaw = apiFakeAuthenticatorData(2);
    $signaturePayload = $authenticatorDataRaw.hash('sha256', $clientDataJson ?: '', true);

    openssl_sign($signaturePayload, $signature, $privatePem, OPENSSL_ALGO_SHA256);

    $verify = $this->postJson('/api/v1/auth/passkeys/verify', [
        'challenge_id' => $challengeId,
        'credential_id' => $credentialId,
        'client_data_json' => apiB64urlEncode($clientDataJson ?: ''),
        'authenticator_data' => apiB64urlEncode($authenticatorDataRaw),
        'signature' => apiB64urlEncode($signature),
        'device_name' => 'ios-passkey',
    ]);

    $verify->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonStructure([
            'token_type',
            'access_token',
            'user' => ['id', 'email', 'tier'],
        ]);

    $plainTextToken = (string) $verify->json('access_token');
    $tokenId = (int) explode('|', $plainTextToken, 2)[0];
    $tokenModel = PersonalAccessToken::find($tokenId);

    expect($tokenModel)->not->toBeNull();
    expect($tokenModel?->tokenable_id)->toBe($user->id);
    expect(Passkey::query()->where('credential_id', $credentialId)->value('sign_count'))->toBe(2);
});
