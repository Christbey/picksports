<?php

use App\AI\Agents\PredictionReviewAgent;
use App\Models\AiGeneration;
use App\Models\AnalysisWorkItem;
use App\Models\PredictionReviewRevision;
use App\Services\AI\AiGenerationRecorder;
use App\Services\Predictions\PredictionReviewInputBuilder;
use App\Services\Predictions\Review\PredictionReviewCoordinator;
use App\Services\Predictions\Review\PredictionReviewRunner;
use App\Services\Predictions\Review\ReviewFingerprint;
use App\Services\Research\GameResearchService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PredictionReviewFixtures;

beforeEach(function () {
    $this->travelTo('2026-11-20 12:00:00 America/Chicago');
    Http::preventStrayRequests();
    Queue::fake();
    config(['prediction_review.enabled' => true, 'prediction_review.sports.nba' => true,
        'prediction_lifecycle.canonical_reads.nba' => true]);
    $research = Mockery::mock(GameResearchService::class);
    $research->shouldReceive('forGame')->andReturn(['status' => 'unavailable', 'applicability' => 'current']);
    app()->instance(GameResearchService::class, $research);
});

afterEach(fn () => $this->travelBack());

function reviewExecutionOutput(): array
{
    return ['recommendation' => 'spread', 'bet_classification' => 'bet', 'ai_confidence' => 60,
        'analysis_confidence' => 60, 'summary' => 'The supplied model favors home; research is unavailable.',
        'key_factors' => ['Stored forecast.', 'Existing quote.', 'Research gap.'],
        'risk_flags' => ['research_unavailable'], 'reason_codes' => ['model_direction', 'evidence_gap'],
        'market_notes' => ['moneyline' => null, 'spread' => null, 'total' => null, 'props' => null]];
}

function reviewExecutionWork(): array
{
    [$game, , $prediction] = PredictionReviewFixtures::game('nba');
    $evidence = app(GameResearchService::class)->forGame($game, 'nba');
    $work = app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', $evidence);

    return [$work, $game, $prediction];
}

it('reconciles work idempotently and purchases one interpretation without promoting a pass', function () {
    [$work, $game] = reviewExecutionWork();
    PredictionReviewAgent::fake([reviewExecutionOutput()])->preventStrayPrompts();
    expect(app(PredictionReviewRunner::class)->run($work))->toBe('succeeded');
    $same = app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', app(GameResearchService::class)->forGame($game, 'nba'));

    expect($same->id)->toBe($work->id)->and($same->state)->toBe('succeeded')
        ->and(app(PredictionReviewRunner::class)->run($same))->toBe('not_due')
        ->and(PredictionReviewRevision::query()->count())->toBe(1)
        ->and(PredictionReviewRevision::query()->sole()->decision['bet_classification'])->toBe('pass')
        ->and(AiGeneration::where('purpose', 'prediction_review')->count())->toBe(1)
        ->and(AiGeneration::where('purpose', 'prediction_review')->sole()->status)->toBe('completed');
});

it('retains an immutable exact revision and quote instead of an AI replacement', function () {
    [$game, , $prediction] = PredictionReviewFixtures::game('nba');
    $approval = PredictionReviewFixtures::approval($game, $prediction);
    $work = app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', app(GameResearchService::class)->forGame($game, 'nba'));
    PredictionReviewAgent::fake([reviewExecutionOutput()])->preventStrayPrompts();
    app(PredictionReviewRunner::class)->run($work);
    $revision = PredictionReviewRevision::query()->sole();

    expect($revision->canonical_prediction_id)->toBe($prediction->id)
        ->and($revision->decision['selection']['bet_decision_id'])->toBe($approval->id)
        ->and($revision->decision['selection']['line'])->toBe(-3.5)
        ->and(fn () => $revision->update(['decision' => []]))->toThrow(LogicException::class);
});

it('invalidates desired work when the execution model changes but ignores read timestamp churn', function () {
    [$work, $game] = reviewExecutionWork();
    $input = app(PredictionReviewInputBuilder::class)->forGame($game, 'nba');
    $hash = app(ReviewFingerprint::class);
    expect($hash->forInput($input, ['status' => 'ready', 'assessed_at' => 'a']))->toBe($hash->forInput($input, ['status' => 'ready', 'assessed_at' => 'b']));
    config(['prediction_review.model' => 'different-model']);
    $changed = app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', app(GameResearchService::class)->forGame($game, 'nba'));
    expect($changed->id)->toBe($work->id)->and($changed->desired_fingerprint)->not->toBe($work->desired_fingerprint)
        ->and($changed->state)->toBe('pending');
});

it('defers at an application budget without a provider call or attempt charge', function () {
    [$work] = reviewExecutionWork();
    config(['prediction_review.budget.application_daily_usd' => 0]);
    PredictionReviewAgent::fake([])->preventStrayPrompts();

    expect(app(PredictionReviewRunner::class)->run($work))->toBe('deferred')
        ->and($work->fresh()->reason_code)->toBe('application_budget_reached')
        ->and($work->fresh()->attempts)->toBe(0)
        ->and(AiGeneration::query()->count())->toBe(0);
});

it('accounts for paid malformed output and retries through durable delayed work', function () {
    [$work] = reviewExecutionWork();
    PredictionReviewAgent::fake([array_replace(reviewExecutionOutput(), ['summary' => ''])])->preventStrayPrompts();

    expect(app(PredictionReviewRunner::class)->run($work))->toBe('deferred')
        ->and($work->fresh()->next_attempt_at->isFuture())->toBeTrue()
        ->and(AiGeneration::where('purpose', 'prediction_review')->sole()->status)->toBe('completed')
        ->and(PredictionReviewRevision::query()->count())->toBe(0)
        ->and(app(PredictionReviewRunner::class)->run($work->fresh()))->toBe('not_due');
});

it('recovers an abandoned lease without duplicate results', function () {
    [$work] = reviewExecutionWork();
    $work->update(['state' => 'running', 'lease_token' => 'abandoned', 'lease_expires_at' => now()->subMinute()]);
    PredictionReviewAgent::fake([reviewExecutionOutput()])->preventStrayPrompts();
    expect(app(PredictionReviewRunner::class)->run($work))->toBe('succeeded')
        ->and(PredictionReviewRevision::query()->count())->toBe(1);
});

it('cancels withdrawn forecasts without falling back to legacy input', function () {
    [$work, , $prediction] = reviewExecutionWork();
    $prediction->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    PredictionReviewAgent::fake([])->preventStrayPrompts();
    expect(app(PredictionReviewRunner::class)->run($work))->toBe('outdated')
        ->and($work->fresh()->state)->toBe('cancelled')->and(AiGeneration::query()->count())->toBe(0);
});

it('does not activate work for disabled sports', function () {
    [$game] = PredictionReviewFixtures::game('nfl');
    expect(app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nfl'))->toBeNull()
        ->and(AnalysisWorkItem::query()->count())->toBe(0);
});

it('supports read-only dry runs and reports dispatch separately from completion', function () {
    [$work] = reviewExecutionWork();
    $this->artisan('sports:run-prediction-reviews', ['--dry-run' => true])->assertSuccessful()->expectsOutputToContain('Dispatch is not completion');
    expect($work->fresh()->state)->toBe('pending')->and(AiGeneration::query()->count())->toBe(0);
});

it('stores a paid old result historically when the desired execution changes during the request', function () {
    [$work] = reviewExecutionWork();
    PredictionReviewAgent::fake(function () {
        config(['prediction_review.model' => 'changed-during-request']);

        return reviewExecutionOutput();
    })->preventStrayPrompts();

    expect(app(PredictionReviewRunner::class)->run($work))->toBe('outdated')
        ->and(PredictionReviewRevision::query()->sole()->applicability)->toBe('superseded')
        ->and($work->fresh()->state)->toBe('pending')
        ->and($work->fresh()->desired_fingerprint)->not->toBe($work->desired_fingerprint)
        ->and(AiGeneration::query()->sole()->status)->toBe('completed');
});

it('keeps timeout usage reserved and opens the provider circuit without blocking sleeps', function () {
    [$work] = reviewExecutionWork();
    PredictionReviewAgent::fake(fn () => throw new RuntimeException('provider status 429'))->preventStrayPrompts();

    expect(app(PredictionReviewRunner::class)->run($work))->toBe('deferred')
        ->and(AiGeneration::query()->sole()->status)->toBe('failed')
        ->and(AiGeneration::query()->sole()->cost_usd)->toBeNull()
        ->and(data_get(AiGeneration::query()->sole()->metadata, 'reserved_cost_usd'))->toBe(0.05);
    $this->travel(10)->minutes();
    expect(app(PredictionReviewRunner::class)->run($work->fresh()))->toBe('deferred')
        ->and($work->fresh()->reason_code)->toBe('provider_cooldown')
        ->and(AiGeneration::query()->count())->toBe(1);
});

it('rotates sport admission even when the global batch contains only one game', function () {
    reviewExecutionWork();
    config(['prediction_review.sports.nfl' => true, 'prediction_lifecycle.canonical_reads.nfl' => true]);
    [$game] = PredictionReviewFixtures::game('nfl');
    app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nfl', app(GameResearchService::class)->forGame($game, 'nfl'));
    PredictionReviewAgent::fake([reviewExecutionOutput(), reviewExecutionOutput()])->preventStrayPrompts();
    $this->artisan('sports:run-prediction-reviews', ['--limit' => 1, '--inline' => true])->assertSuccessful();
    $this->artisan('sports:run-prediction-reviews', ['--limit' => 1, '--inline' => true])->assertSuccessful();

    expect(PredictionReviewRevision::query()->pluck('sport')->sort()->values()->all())->toBe(['nba', 'nfl']);
});

it('recovers a recorded paid response after a persistence crash without a second purchase', function () {
    [$work] = reviewExecutionWork();
    $recorder = app(AiGenerationRecorder::class);
    $generation = $recorder->start('prediction_review', 'prediction-review-v1', 'openai', 'gpt-4o-mini', $work->input_payload,
        'analysis_work_item', (string) $work->id, ['desired_fingerprint' => $work->desired_fingerprint, 'reserved_cost_usd' => 0.05]);
    $recorder->complete($generation, reviewExecutionOutput(), 1, metadata: ['review_output' => reviewExecutionOutput(), 'review_validated' => true]);
    PredictionReviewAgent::fake([])->preventStrayPrompts();

    expect(app(PredictionReviewRunner::class)->run($work))->toBe('succeeded')
        ->and(AiGeneration::query()->count())->toBe(1)
        ->and(PredictionReviewRevision::query()->sole()->ai_generation_id)->toBe($generation->id);
});

it('allows an explicit operational retry without resetting paid usage or stealing a running lease', function () {
    [$work, $game] = reviewExecutionWork();
    $work->update(['state' => 'failed', 'attempts' => 3, 'reason_code' => 'review_failed']);
    $evidence = app(GameResearchService::class)->forGame($game, 'nba');
    $retry = app(PredictionReviewCoordinator::class)->retryGame($game, 'nba', $evidence);
    expect($retry->state)->toBe('pending')->and($retry->attempts)->toBe(0);
    $retry->update(['state' => 'running', 'lease_token' => 'active', 'lease_expires_at' => now()->addMinute()]);
    $same = app(PredictionReviewCoordinator::class)->retryGame($game, 'nba', $evidence);
    expect($same->state)->toBe('running')->and($same->lease_token)->toBe('active');
});

it('does not publish a result after its lease is replaced', function () {
    [$work] = reviewExecutionWork();
    PredictionReviewAgent::fake(function () use ($work) {
        $work->fresh()->update(['lease_token' => 'new-owner']);

        return reviewExecutionOutput();
    })->preventStrayPrompts();

    expect(app(PredictionReviewRunner::class)->run($work))->toBe('outdated')
        ->and(PredictionReviewRevision::query()->sole()->applicability)->toBe('superseded')
        ->and($work->fresh()->lease_token)->toBe('new-owner');
});

it('marks dispatches queued so a second small batch progresses before workers start', function () {
    [$first] = reviewExecutionWork();
    [$second] = reviewExecutionWork();
    $this->artisan('sports:run-prediction-reviews', ['--limit' => 1])->assertSuccessful();
    $this->artisan('sports:run-prediction-reviews', ['--limit' => 1])->assertSuccessful();

    expect($first->fresh()->state)->toBe('queued')->and($second->fresh()->state)->toBe('queued');
});

it('rotates queued sport dispatch before workers begin provider attempts', function () {
    [$first] = reviewExecutionWork();
    [$second] = reviewExecutionWork();
    config(['prediction_review.sports.nfl' => true, 'prediction_lifecycle.canonical_reads.nfl' => true]);
    [$game] = PredictionReviewFixtures::game('nfl');
    $other = app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nfl', app(GameResearchService::class)->forGame($game, 'nfl'));
    $this->artisan('sports:run-prediction-reviews', ['--limit' => 1])->assertSuccessful();
    $this->artisan('sports:run-prediction-reviews', ['--limit' => 1])->assertSuccessful();

    expect($first->fresh()->state)->toBe('queued')
        ->and($second->fresh()->state)->toBe('pending')
        ->and($other->fresh()->state)->toBe('queued');
});

it('fails an inline partial run honestly when budget admission defers work', function () {
    reviewExecutionWork();
    config(['prediction_review.budget.application_daily_usd' => 0]);
    PredictionReviewAgent::fake([])->preventStrayPrompts();
    $this->artisan('sports:run-prediction-reviews', ['--inline' => true])->assertFailed();
    expect(AiGeneration::query()->count())->toBe(0);
});

it('leaves existing work and providers untouched when global admission is disabled', function () {
    [$work, $game] = reviewExecutionWork();
    config(['prediction_review.enabled' => false]);
    PredictionReviewAgent::fake([])->preventStrayPrompts();

    expect(app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba'))->toBeNull()
        ->and(app(PredictionReviewRunner::class)->run($work))->toBe('disabled')
        ->and($work->fresh()->state)->toBe('pending')->and(AiGeneration::query()->count())->toBe(0);

    $work->update(['deadline_at' => now()->subMinute()]);
    $this->artisan('sports:run-prediction-reviews')->assertSuccessful();
    expect($work->fresh()->state)->toBe('pending');
});

it('leaves admitted NBA work untouched after NBA rollback while global reviews stay enabled', function () {
    [$work, $game] = reviewExecutionWork();
    config(['prediction_review.sports.nba' => false, 'prediction_review.sports.nfl' => false]);
    PredictionReviewAgent::fake([])->preventStrayPrompts();
    $before = $work->getRawOriginal();

    expect(app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba'))->toBeNull()
        ->and(app(PredictionReviewRunner::class)->run($work))->toBe('disabled');
    $this->artisan('sports:run-prediction-reviews', ['--sport' => ['nba'], '--inline' => true])->assertSuccessful();

    expect($work->fresh()->getRawOriginal())->toBe($before)
        ->and(AiGeneration::query()->count())->toBe(0)
        ->and(PredictionReviewRevision::query()->count())->toBe(0)
        ->and(app(PredictionReviewCoordinator::class)->enabled('nfl'))->toBeFalse();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});
