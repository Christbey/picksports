<?php

use App\Jobs\Predictions\RefreshGameAnalysis;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\PredictionReviewFixtures;

beforeEach(function () {
    $this->travelTo('2026-11-20 12:00:00 America/Chicago');
    Http::preventStrayRequests();
    Queue::fake();
    config(['subscriptions.enforce_tiers' => false, 'research.enabled' => true, 'research.sports.nba.enabled' => true, 'prediction_review.enabled' => false]);
});

afterEach(fn () => $this->travelBack());

it('queues an explicit admin refresh with requester identity without calling providers', function (bool $canonicalId) {
    [$game] = PredictionReviewFixtures::game('nba');
    $user = User::factory()->admin()->create();
    Sanctum::actingAs($user);
    $identity = $canonicalId ? $game->sportEvent->public_id : $game->id;

    $this->postJson("/api/v2/admin/nba/games/{$identity}/analysis/retry")
        ->assertAccepted()->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.game_id', $game->id)->assertJsonPath('data.sport', 'nba');

    Queue::assertPushed(RefreshGameAnalysis::class, fn ($job) => $job->sport === 'nba' && $job->gameId === $game->id && $job->requestedBy === $user->id);
    Http::assertNothingSent();
})->with([false, true]);

it('does not let subscribers queue research even with prediction permissions', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    $user = User::factory()->create();
    foreach (['view-nba-predictions', 'view-prediction-spread', 'view-prediction-win-probability', 'view-prediction-betting-value'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }
    Sanctum::actingAs($user);

    $this->postJson("/api/v2/admin/nba/games/{$game->id}/analysis/retry")->assertForbidden();
    Queue::assertNotPushed(RefreshGameAnalysis::class);
});

it('rejects refresh when both research and review are disabled', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    config(['research.enabled' => false]);
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->postJson("/api/v2/admin/nba/games/{$game->id}/analysis/retry")->assertUnprocessable();
    Queue::assertNotPushed(RefreshGameAnalysis::class);
    Http::assertNothingSent();
});

it('rejects games that have started or no longer have pregame status', function (bool $started) {
    [$game] = PredictionReviewFixtures::game('nba');
    if ($started) {
        $this->travelTo(now()->addHours(5));
    } else {
        $game->update(['status' => 'STATUS_FINAL']);
    }
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->postJson("/api/v2/admin/nba/games/{$game->id}/analysis/retry")->assertUnprocessable();
    Queue::assertNotPushed(RefreshGameAnalysis::class);
    Http::assertNothingSent();
})->with([false, true]);

it('rejects cross-sport event identity and unknown sports', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->postJson("/api/v2/admin/nfl/games/{$game->sportEvent->public_id}/analysis/retry")->assertNotFound();
    $this->postJson("/api/v2/admin/unknown/games/{$game->id}/analysis/retry")->assertNotFound();
    Queue::assertNotPushed(RefreshGameAnalysis::class);
});

it('retains prediction disclosure requirements for an admin retry with enforced tiers', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    config(['subscriptions.enforce_tiers' => true, 'subscriptions.tier_bypass_user_ids' => []]);
    $user = User::factory()->admin()->create();
    foreach (['view-nba-predictions', 'view-prediction-win-probability', 'view-prediction-betting-value'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }
    Sanctum::actingAs($user);

    $this->postJson("/api/v2/admin/nba/games/{$game->id}/analysis/retry")->assertForbidden();
    Queue::assertNotPushed(RefreshGameAnalysis::class);
});
