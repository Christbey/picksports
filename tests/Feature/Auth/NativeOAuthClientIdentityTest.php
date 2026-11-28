<?php

use App\Models\OAuthUser;
use App\Models\User;
use Laravel\Passport\ClientRepository;

it('refuses converting a confidential same-name client into a public native client', function () {
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Collision', ['https://server.example.test/callback'], confidential: true);
    $before = $client->fresh()->getRawOriginal();
    $this->artisan('auth:configure-native-oauth-client', ['--name' => 'Collision', '--redirect-uri' => ['picksports://oauth/callback']])->assertFailed();
    expect($client->fresh()->getRawOriginal())->toBe($before);
});

it('refuses ambiguous same-name native clients without changing either', function () {
    $clients = collect(['picksports://first', 'picksports://second'])->map(fn ($uri) => app(ClientRepository::class)->createAuthorizationCodeGrantClient('Collision', [$uri], confidential: false));
    $before = $clients->map(fn ($client) => $client->fresh()->getRawOriginal())->all();
    $this->artisan('auth:configure-native-oauth-client', ['--name' => 'Collision', '--redirect-uri' => ['picksports://oauth/callback']])->assertFailed();
    expect($clients->map(fn ($client) => $client->fresh()->getRawOriginal())->all())->toBe($before);
});

it('refuses adopting a user-owned public client by its display name', function () {
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Collision', ['picksports://owned'], confidential: false, user: OAuthUser::query()->findOrFail(User::factory()->create()->getKey()));
    $before = $client->fresh()->getRawOriginal();
    $this->artisan('auth:configure-native-oauth-client', ['--name' => 'Collision', '--redirect-uri' => ['picksports://oauth/callback']])->assertFailed();
    expect($client->fresh()->getRawOriginal())->toBe($before);
});
