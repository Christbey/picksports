<?php

use App\Jobs\Predictions\RefreshGameAnalysis;
use App\Models\User;
use App\Services\Auth\Native\DeviceSessionTokenService;
use App\Services\Auth\TokenAuthService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Tests\Support\PredictionReviewFixtures;

beforeEach(function () {
    $this->travelTo('2026-11-20 12:00:00 America/Chicago');
    Http::preventStrayRequests();
    Queue::fake();
    config(['subscriptions.enforce_tiers' => false, 'research.enabled' => true,
        'research.sports.nba.enabled' => true, 'prediction_review.enabled' => false]);
});

afterEach(fn () => $this->travelBack());

it('allows reads but denies an admin retry with a real read-only native bearer token', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    $user = User::factory()->admin()->create();
    $pair = app(DeviceSessionTokenService::class)->issue($user, 'scope-test', 'ios', abilities: ['mobile:read']);

    $this->withToken($pair->accessToken)->getJson('/api/v2/auth/me')->assertOk();
    // HTTP workers start with fresh authentication guards on the next request.
    Auth::forgetGuards();
    $this->withToken($pair->accessToken)->postJson("/api/v2/admin/nba/games/{$game->id}/analysis/retry")
        ->assertForbidden()->assertJsonPath('error.code', 'insufficient_token_scope');

    Queue::assertNotPushed(RefreshGameAnalysis::class);
    Http::assertNothingSent();
});

it('permits native write abilities and legacy wildcard tokens through the existing admin policy', function (bool $legacy) {
    [$game] = PredictionReviewFixtures::game('nba');
    $user = User::factory()->admin()->create();
    $token = $legacy
        ? app(TokenAuthService::class)->issueAccessToken($user, 'legacy-client')
        : app(DeviceSessionTokenService::class)->issue($user, 'scope-test', 'ios', abilities: ['mobile:read', 'mobile:write'])->accessToken;

    $this->withToken($token)->postJson("/api/v2/admin/nba/games/{$game->id}/analysis/retry")->assertAccepted();

    Queue::assertPushed(RefreshGameAnalysis::class, fn ($job) => $job->requestedBy === $user->id);
    Http::assertNothingSent();
})->with([false, true]);

it('rejects a real scoped token lacking read ability', function () {
    $pair = app(DeviceSessionTokenService::class)->issue(User::factory()->create(), 'scope-test', 'ios', abilities: ['mobile:write']);

    $this->withToken($pair->accessToken)->getJson('/api/v2/auth/me')
        ->assertForbidden()->assertJsonPath('error.code', 'insufficient_token_scope');
});

it('does not turn write ability into admin authorization', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    $pair = app(DeviceSessionTokenService::class)->issue(User::factory()->create(), 'scope-test', 'ios', abilities: ['mobile:read', 'mobile:write']);

    $this->withToken($pair->accessToken)->postJson("/api/v2/admin/nba/games/{$game->id}/analysis/retry")->assertForbidden();

    Queue::assertNotPushed(RefreshGameAnalysis::class);
    Http::assertNothingSent();
});

it('does not apply bearer abilities to a stateful application session', function (bool $transient) {
    [$game] = PredictionReviewFixtures::game('nba');
    $user = User::factory()->admin()->create();
    if ($transient) {
        $user->withAccessToken(new TransientToken);
    }

    $this->actingAs($user, 'web')->postJson("/api/v2/admin/nba/games/{$game->id}/analysis/retry")->assertAccepted();

    Queue::assertPushed(RefreshGameAnalysis::class);
    Http::assertNothingSent();
})->with([false, true]);

it('rejects revoked and expired native tokens before route execution', function (bool $expired) {
    $pair = app(DeviceSessionTokenService::class)->issue(User::factory()->admin()->create(), 'scope-test', 'ios', abilities: ['mobile:read', 'mobile:write']);
    $token = PersonalAccessToken::findOrFail($pair->deviceSession->access_token_id);
    if ($expired) {
        $token->update(['expires_at' => now()->subMinute()]);
    } else {
        $token->delete();
    }

    $this->withToken($pair->accessToken)->getJson('/api/v2/auth/me')->assertUnauthorized();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([false, true]);
