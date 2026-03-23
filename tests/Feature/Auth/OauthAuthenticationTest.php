<?php

use App\Models\OauthAccount;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config()->set('services.oauth.providers.google.enabled', true);
});

afterEach(function () {
    Mockery::close();
});

test('oauth users can be provisioned and redirected to onboarding', function () {
    fakeSocialiteUser([
        'id' => 'google-user-1',
        'name' => 'OAuth User',
        'email' => 'oauth@example.com',
        'raw' => ['email_verified' => true],
    ]);

    $response = $this->get(route('oauth.callback', ['provider' => 'google'], absolute: false));

    $response->assertRedirect(route('oauth.onboarding.show', absolute: false));
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'oauth@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->age_verified_at)->toBeNull();

    expect(OauthAccount::query()->where('provider', 'google')->where('provider_user_id', 'google-user-1')->exists())->toBeTrue();
});

test('oauth callback links verified email to an existing user', function () {
    $user = User::factory()->create([
        'email' => 'existing@example.com',
    ]);

    fakeSocialiteUser([
        'id' => 'google-user-2',
        'name' => 'Existing User',
        'email' => 'existing@example.com',
        'raw' => ['email_verified' => true],
    ]);

    $response = $this->get(route('oauth.callback', ['provider' => 'google'], absolute: false));

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);

    expect(User::query()->where('email', 'existing@example.com')->count())->toBe(1);

    $oauthAccount = OauthAccount::query()
        ->where('provider', 'google')
        ->where('provider_user_id', 'google-user-2')
        ->first();

    expect($oauthAccount)->not->toBeNull();
    expect($oauthAccount->user_id)->toBe($user->id);
});

test('oauth callback is rejected when provider email is not verified', function () {
    fakeSocialiteUser([
        'id' => 'google-user-3',
        'name' => 'Unverified User',
        'email' => 'unverified@example.com',
        'raw' => ['email_verified' => false],
    ]);

    $response = $this->get(route('oauth.callback', ['provider' => 'google'], absolute: false));

    $response->assertRedirect(route('login', absolute: false));
    $this->assertGuest();

    expect(User::query()->where('email', 'unverified@example.com')->exists())->toBeFalse();
});

test('users without completed onboarding are redirected to oauth onboarding', function () {
    $user = User::factory()->withoutAgeVerification()->create();

    $response = $this->actingAs($user)->get(route('dashboard', absolute: false));

    $response->assertRedirect(route('oauth.onboarding.show', absolute: false));
});

test('oauth onboarding can be completed', function () {
    $user = User::factory()->withoutAgeVerification()->create();

    $response = $this->actingAs($user)->post(route('oauth.onboarding.store', absolute: false), [
        'age_verified' => '1',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    expect($user->refresh()->age_verified_at)->not->toBeNull();
});

/**
 * @param  array{id: string, name: string, email: string, raw: array<string, mixed>}  $attributes
 */
function fakeSocialiteUser(array $attributes): void
{
    $socialiteUser = (new SocialiteUser)
        ->map([
            'id' => $attributes['id'],
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'avatar' => 'https://example.com/avatar.png',
        ])
        ->setRaw($attributes['raw'])
        ->setToken('access-token')
        ->setRefreshToken('refresh-token')
        ->setExpiresIn(3600);

    $provider = Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturn($provider);
}
