<?php

use App\Models\OauthAccount;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function subjectBoundaryOauthProvider(string $id, string $email): void
{
    config()->set('services.oauth.providers.google.enabled', true);
    $identity = (new SocialiteUser)->map(['id' => $id, 'name' => 'Synthetic Identity', 'email' => $email])
        ->setRaw(['email_verified' => true])->setToken('synthetic-token')->setRefreshToken('synthetic-refresh')->setExpiresIn(3600);
    $provider = Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn($identity);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);
}

it('does not authenticate an unrelated existing owner through a missing provider subject', function (string $subject) {
    $owner = User::factory()->create();
    $verifiedEmailOwner = User::factory()->create();
    $account = OauthAccount::query()->create(['user_id' => $owner->id, 'provider' => 'google', 'provider_user_id' => $subject]);
    $before = $account->fresh()->getRawOriginal();
    subjectBoundaryOauthProvider($subject, $verifiedEmailOwner->email);
    $response = $this->get(route('oauth.callback', ['provider' => 'google'], absolute: false));
    expect(auth()->id())->not->toBe($owner->id);
    $this->assertGuest();
    $response->assertRedirect(route('login', absolute: false))->assertSessionHas('oauth_error');
    expect($account->fresh()->getRawOriginal())->toBe($before);
})->with(['empty' => [''], 'whitespace' => [' ']]);

it('preserves a nonempty zero subject as an opaque identity', function () {
    $user = User::factory()->create();
    subjectBoundaryOauthProvider('0', $user->email);
    $this->get(route('oauth.callback', ['provider' => 'google'], absolute: false))->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
    expect(OauthAccount::query()->where('provider_user_id', '0')->sole()->user_id)->toBe($user->id);
});
