<?php

use App\Models\OauthAccount;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function boundaryOauthProvider(string $id, string $email): void
{
    config()->set('services.oauth.providers.google.enabled', true);
    $identity = (new SocialiteUser)->map(['id' => $id, 'name' => 'Synthetic Identity', 'email' => $email])
        ->setRaw(['email_verified' => true])->setToken('synthetic-token')->setRefreshToken('synthetic-refresh')->setExpiresIn(3600);
    $provider = Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn($identity);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);
}

it('refuses authentication and rolls back provisioning when OAuth identity creation is cancelled', function () {
    boundaryOauthProvider('synthetic-new', 'synthetic-new@example.test');
    Event::listen('eloquent.creating: '.OauthAccount::class, fn () => false);
    $response = $this->get(route('oauth.callback', ['provider' => 'google'], absolute: false));
    $this->assertGuest();
    $response->assertRedirect(route('login', absolute: false))->assertSessionHas('oauth_error');
    expect(User::query()->where('email', 'synthetic-new@example.test')->exists())->toBeFalse()
        ->and(OauthAccount::query()->where('provider_user_id', 'synthetic-new')->exists())->toBeFalse();
});

it('refuses authentication when existing OAuth identity persistence is cancelled', function () {
    $user = User::factory()->create(['email' => 'synthetic-existing@example.test']);
    $account = OauthAccount::query()->create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'synthetic-existing', 'access_token' => 'retained-token']);
    $before = $account->fresh()->getRawOriginal();
    boundaryOauthProvider('synthetic-existing', $user->email);
    Event::listen('eloquent.updating: '.OauthAccount::class, fn () => false);
    $response = $this->get(route('oauth.callback', ['provider' => 'google'], absolute: false));
    $this->assertGuest();
    $response->assertRedirect(route('login', absolute: false))->assertSessionHas('oauth_error');
    expect($account->fresh()->getRawOriginal())->toBe($before);
});

it('keeps the existing provider identity owner when its verified provider email changes', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $account = OauthAccount::query()->create(['user_id' => $owner->id, 'provider' => 'google', 'provider_user_id' => 'synthetic-stable-owner']);
    boundaryOauthProvider('synthetic-stable-owner', $other->email);
    $this->get(route('oauth.callback', ['provider' => 'google'], absolute: false))->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($owner);
    expect($account->fresh()->user_id)->toBe($owner->id);
});
