<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

it('issues a sanctum token for valid api login credentials', function () {
    $user = User::factory()->create([
        'email' => 'mobile@example.com',
        'password' => Hash::make('secret-pass'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
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

it('rejects invalid api login credentials', function () {
    User::factory()->create([
        'email' => 'mobile@example.com',
        'password' => Hash::make('correct-password'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'mobile@example.com',
        'password' => 'wrong-password',
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('returns current user payload for authenticated token request', function () {
    $user = User::factory()->create();
    $token = $user->createToken('ios-client');

    $response = $this
        ->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
        ->getJson('/api/v1/auth/me');

    $response
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', $user->email);
});

it('requires auth for me endpoint', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('revokes only current access token on logout', function () {
    $user = User::factory()->create();
    $firstToken = $user->createToken('ios-one');
    $secondToken = $user->createToken('ios-two');

    $this
        ->withHeader('Authorization', 'Bearer '.$firstToken->plainTextToken)
        ->postJson('/api/v1/auth/logout')
        ->assertNoContent();

    expect(PersonalAccessToken::find($firstToken->accessToken->id))->toBeNull();
    expect(PersonalAccessToken::find($secondToken->accessToken->id))->not->toBeNull();
});

it('revokes all access tokens on logout-all', function () {
    $user = User::factory()->create();
    $firstToken = $user->createToken('ios-one');
    $user->createToken('ios-two');

    $this
        ->withHeader('Authorization', 'Bearer '.$firstToken->plainTextToken)
        ->postJson('/api/v1/auth/logout-all')
        ->assertNoContent();

    expect($user->fresh()->tokens()->count())->toBe(0);
});
