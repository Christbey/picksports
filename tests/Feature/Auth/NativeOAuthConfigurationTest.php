<?php

use App\Http\Middleware\AuthenticateApiV2Client;
use App\Models\OAuthUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

it('configures passport for native authorization code with pkce', function () {
    expect(config('auth.guards.api.driver'))->toBe('passport')
        ->and(config('auth.providers.oauth_users.model'))->toBe(OAuthUser::class)
        ->and(is_a(OAuthUser::class, OAuthenticatable::class, true))->toBeTrue()
        ->and(Passport::$scopes)->toHaveKeys(['mobile:read', 'mobile:write'])
        ->and(Passport::$defaultScope)->toBe('mobile:read')
        ->and(route('passport.authorizations.authorize'))->toContain('/oauth/authorize')
        ->and(route('passport.token'))->toContain('/oauth/token');
});

it('creates one idempotent public native oauth client', function () {
    $arguments = [
        '--name' => 'PickSports iOS',
        '--redirect-uri' => ['picksports://oauth/callback'],
    ];

    $this->artisan('auth:configure-native-oauth-client', $arguments)->assertSuccessful();
    $this->artisan('auth:configure-native-oauth-client', $arguments)->assertSuccessful();

    /** @var Client $client */
    $client = Client::query()->sole();

    expect($client->name)->toBe('PickSports iOS')
        ->and($client->secret)->toBeNull()
        ->and($client->redirect_uris)->toBe(['picksports://oauth/callback'])
        ->and($client->grant_types)->toContain('authorization_code', 'refresh_token')
        ->and($client->revoked)->toBeFalse();
});

it('rejects insecure non-localhost redirect uris for the native oauth client command', function () {
    $this->artisan('auth:configure-native-oauth-client', [
        '--redirect-uri' => ['http://example.test/oauth/callback'],
    ])->assertFailed();

    expect(Client::query()->count())->toBe(0);
});

it('enforces read and write scopes for oauth-authenticated v2 requests', function () {
    $user = User::factory()->create();
    $middleware = app(AuthenticateApiV2Client::class);

    $readRequest = Request::create('/api/v2/sports', 'GET');
    $readRequest->setUserResolver(fn () => $user);
    $readRequest->attributes->set('oauth_access_token', new Token([
        'scopes' => ['mobile:read'],
    ]));
    $readResponse = $middleware->handle($readRequest, fn () => new Response(status: 204));

    $writeRequest = Request::create('/api/v2/user-bets', 'POST');
    $writeRequest->setUserResolver(fn () => $user);
    $writeRequest->attributes->set('oauth_access_token', new Token([
        'scopes' => ['mobile:read'],
    ]));
    $writeResponse = $middleware->handle($writeRequest, fn () => new Response(status: 204));

    expect($readResponse->getStatusCode())->toBe(204)
        ->and($writeResponse->getStatusCode())->toBe(403)
        ->and(json_decode($writeResponse->getContent(), true)['error']['code'])->toBe('insufficient_oauth_scope');
});
