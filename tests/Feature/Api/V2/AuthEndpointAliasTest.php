<?php

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

function v2ApiB64urlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function v2ApiFakeAuthenticatorData(int $signCount = 1): string
{
    $configuredRpId = (string) (config('passkeys.rp_id') ?? '');
    $rpId = $configuredRpId !== '' ? $configuredRpId : (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    $rpIdHash = hash('sha256', $rpId, true);
    $flags = chr(0x05);

    return $rpIdHash.$flags.pack('N', $signCount);
}

it('issues a sanctum token through the v2 auth login alias', function () {
    $user = User::factory()->create([
        'email' => 'mobile@example.com',
        'password' => Hash::make('secret-pass'),
    ]);

    $response = $this->postJson('/api/v2/auth/login', [
        'email' => 'mobile@example.com',
        'password' => 'secret-pass',
        'device_name' => 'ios-iphone',
    ]);

    $response
        ->assertOk()
        ->assertJsonStructure([
            'token_type',
            'access_token',
            'user' => [
                'id',
                'name',
                'email',
                'tier' => ['slug', 'name'],
                'roles',
                'permissions',
            ],
        ])
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.email', 'mobile@example.com');

    expect($user->tokens()->count())->toBe(1);
});

it('returns current user payload through the v2 auth me alias', function () {
    $user = User::factory()->create();
    $token = $user->createToken('ios-client');

    $response = $this
        ->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
        ->getJson('/api/v2/auth/me');

    $response
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', $user->email);
});

it('revokes tokens through the v2 auth logout aliases', function () {
    $user = User::factory()->create();
    $firstToken = $user->createToken('ios-one');
    $secondToken = $user->createToken('ios-two');

    $this
        ->withHeader('Authorization', 'Bearer '.$firstToken->plainTextToken)
        ->postJson('/api/v2/auth/logout')
        ->assertNoContent();

    expect(PersonalAccessToken::find($firstToken->accessToken->id))->toBeNull();
    expect(PersonalAccessToken::find($secondToken->accessToken->id))->not->toBeNull();

    $this
        ->withHeader('Authorization', 'Bearer '.$secondToken->plainTextToken)
        ->postJson('/api/v2/auth/logout-all')
        ->assertNoContent();

    expect($user->fresh()->tokens()->count())->toBe(0);
});

it('issues a sanctum token from passkey verification through the v2 auth aliases', function () {
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

    $credentialId = v2ApiB64urlEncode(random_bytes(32));

    Passkey::query()->create([
        'user_id' => $user->id,
        'name' => 'API Key',
        'credential_id' => $credentialId,
        'public_key' => $publicPem,
        'algorithm' => -257,
        'sign_count' => 1,
    ]);

    $options = $this->postJson('/api/v2/auth/passkeys/options', [
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

    $authenticatorDataRaw = v2ApiFakeAuthenticatorData(2);
    $signaturePayload = $authenticatorDataRaw.hash('sha256', $clientDataJson ?: '', true);

    openssl_sign($signaturePayload, $signature, $privatePem, OPENSSL_ALGO_SHA256);

    $verify = $this->postJson('/api/v2/auth/passkeys/verify', [
        'challenge_id' => $challengeId,
        'credential_id' => $credentialId,
        'client_data_json' => v2ApiB64urlEncode($clientDataJson ?: ''),
        'authenticator_data' => v2ApiB64urlEncode($authenticatorDataRaw),
        'signature' => v2ApiB64urlEncode($signature),
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
