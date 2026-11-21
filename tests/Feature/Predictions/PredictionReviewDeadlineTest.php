<?php

use App\AI\Agents\PredictionReviewAgent;
use App\Models\AiGeneration;
use App\Services\Predictions\Review\PredictionReviewCoordinator;
use App\Services\Predictions\Review\PredictionReviewRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PredictionReviewFixtures;

beforeEach(function () {
    $this->originalPhpTimezone = date_default_timezone_get();
    Http::preventStrayRequests();
    Queue::fake();
    config(['prediction_review.enabled' => true, 'prediction_review.sports.nba' => true,
        'prediction_lifecycle.canonical_reads.nba' => true]);
});

afterEach(function () {
    $this->travelBack();
    date_default_timezone_set($this->originalPhpTimezone);
});

it('persists the authoritative kickoff instant in the application timezone', function (string $zone) {
    config(['app.timezone' => $zone]);
    date_default_timezone_set($zone);
    $this->travelTo(CarbonImmutable::parse('2026-11-20T18:00:00Z'));
    [$game] = PredictionReviewFixtures::game('nba');
    $start = $game->sportEvent->starts_at->toImmutable();

    $work = app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba');

    expect($work->deadline_at->timestamp)->toBe($start->timestamp)
        ->and($work->getRawOriginal('deadline_at'))->toBe($start->setTimezone($zone)->toDateTimeString());
})->with(['America/Chicago', 'UTC']);

it('repairs an existing shifted deadline without resetting its retry accounting', function () {
    config(['app.timezone' => 'America/Chicago']);
    date_default_timezone_set('America/Chicago');
    $this->travelTo(CarbonImmutable::parse('2026-11-20T18:00:00Z'));
    [$game] = PredictionReviewFixtures::game('nba');
    $work = app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba');
    $retryAt = now()->addMinutes(10);
    $work->update(['deadline_at' => $work->deadline_at->addHours(6), 'state' => 'deferred',
        'attempts' => 1, 'reason_code' => 'provider_cooldown', 'next_attempt_at' => $retryAt]);

    $reconciled = app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba');

    expect($reconciled->id)->toBe($work->id)
        ->and($reconciled->desired_fingerprint)->toBe($work->desired_fingerprint)
        ->and($reconciled->deadline_at->timestamp)->toBe($game->sportEvent->starts_at->timestamp)
        ->and($reconciled->state)->toBe('deferred')->and($reconciled->attempts)->toBe(1)
        ->and($reconciled->reason_code)->toBe('provider_cooldown')
        ->and($reconciled->next_attempt_at->timestamp)->toBe($retryAt->timestamp)
        ->and(AiGeneration::count())->toBe(0);
});

it('admits no paid review after actual kickoff even while the game status is delayed', function () {
    config(['app.timezone' => 'America/Chicago']);
    date_default_timezone_set('America/Chicago');
    $this->travelTo(CarbonImmutable::parse('2026-11-20T18:00:00Z'));
    [$game] = PredictionReviewFixtures::game('nba');
    $work = app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba');
    $game->update(['status' => 'STATUS_DELAYED']);
    $this->travelTo($game->sportEvent->starts_at->toImmutable()->addSecond());
    PredictionReviewAgent::fake([])->preventStrayPrompts();

    app(PredictionReviewRunner::class)->run($work);

    expect($work->fresh()->state)->toBe('cancelled')
        ->and($work->fresh()->reason_code)->toBe('pregame_deadline_passed')
        ->and(AiGeneration::count())->toBe(0);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});
