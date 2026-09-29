<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

it('requires authentication for frontend context and exposes no session secrets', function () {
    $this->getJson('/api/v2/auth/context')->assertUnauthorized();
    config(['subscriptions.enforce_tiers' => false]);
    $user = User::factory()->create();
    $response = $this->actingAs($user)->getJson('/api/v2/auth/context')->assertSuccessful()
        ->assertJsonPath('data.auth.user.id', $user->id)
        ->assertJsonPath('data.subscription.tiers_enabled', false)
        ->assertJsonPath('data.subscription.is_subscribed', true)
        ->assertJsonMissingPath('data.auth.user.password')
        ->assertJsonMissingPath('data.auth.user.two_factor_secret');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('only permits configured frontend origins with credentials and exposes diagnostic headers', function () {
    config(['cors.allowed_origins' => ['https://app.picksports.test']]);
    $this->withHeaders(['Origin' => 'https://app.picksports.test', 'Access-Control-Request-Method' => 'GET'])
        ->options('/api/v2/auth/context')->assertSuccessful()
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.picksports.test')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
    $this->withHeaders(['Origin' => 'https://untrusted.test', 'Access-Control-Request-Method' => 'GET'])
        ->options('/api/v2/auth/context')->assertHeader('Access-Control-Allow-Origin', 'https://app.picksports.test');
});

it('keeps every portable frontend API path registered in Laravel', function () {
    $source = file_get_contents(resource_path('js/lib/apiV2Routes.ts'));
    preg_match_all("~['\"](/api/v2/[^'\"]+)['\"]~", $source, $matches);
    $registered = collect(Route::getRoutes())->map(fn ($route) => '/'.$route->uri());
    expect($matches[1])->not->toBeEmpty();
    foreach ($matches[1] as $path) {
        expect($registered->contains($path))->toBeTrue("Unregistered frontend API path: {$path}");
    }
});

it('uses the existing Fortify session login and logout with JSON responses', function () {
    $user = User::factory()->create(['password' => bcrypt('valid-password')]);
    $this->postJson('/login', ['email' => $user->email, 'password' => 'valid-password'])->assertSuccessful();
    $this->getJson('/api/v2/auth/context')->assertSuccessful()->assertJsonPath('data.auth.user.id', $user->id);
    $this->postJson('/logout')->assertNoContent();
    $this->assertGuest('web');
});

it('does not reflect an untrusted origin when multiple frontends are configured', function () {
    config(['cors.allowed_origins' => ['https://app.picksports.test', 'https://preview.picksports.test']]);
    $this->withHeaders(['Origin' => 'https://untrusted.test', 'Access-Control-Request-Method' => 'POST'])
        ->options('/login')->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('recognizes a real login cookie on the next first-party browser API request', function () {
    config(['session.driver' => 'database', 'subscriptions.enforce_tiers' => false]);
    $user = User::factory()->create(['password' => bcrypt('valid-password')]);
    $login = $this->postJson('https://picksports.test/login', ['email' => $user->email, 'password' => 'valid-password'])->assertSuccessful();
    $cookie = collect($login->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
    expect($cookie)->not->toBeNull();
    app('auth')->forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    $this->withCredentials()->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
        ->withHeaders(['Referer' => 'https://picksports.test/nfl/predictions'])
        ->getJson('https://picksports.test/api/v2/auth/context')
        ->assertSuccessful()->assertJsonPath('data.auth.user.id', $user->id);
});
