<?php

use App\Models\PredictionReviewRevision;
use App\Services\Predictions\PredictionReviewInputBuilder;
use App\Services\Predictions\Review\CurrentPredictionReview;
use App\Services\Predictions\Review\PredictionReviewCoordinator;
use App\Services\Predictions\Review\ReviewFingerprint;
use App\Services\Research\GameResearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PredictionReviewFixtures as Fixtures;

beforeEach(function () {
    $this->travelTo('2026-11-20 12:00:00 America/Chicago');
    config(['prediction_review.enabled' => true, 'prediction_review.sports.nba' => true,
        'prediction_lifecycle.canonical_reads.nba' => true]);
    Http::preventStrayRequests();
    Queue::fake();
});

afterEach(fn () => $this->travelBack());

function storedCurrentReviewFixture(): array
{
    [$game, , $native] = Fixtures::game('nba');
    $approval = Fixtures::approval($game, $native);
    $input = app(PredictionReviewInputBuilder::class)->forGame($game, 'nba');
    $evidence = app(GameResearchService::class)->forGame($game, 'nba');
    $hashes = app(ReviewFingerprint::class)->forInput($input, $evidence);
    $work = app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', $evidence);
    $revision = PredictionReviewRevision::create([
        'analysis_work_item_id' => $work->id, 'sport_event_id' => $game->sport_event_id,
        'canonical_prediction_id' => $native->id, 'sport' => 'nba', 'prediction_table' => 'predictions',
        'prediction_id' => $native->id, 'desired_fingerprint' => $hashes['fingerprint'],
        'input_hash' => $hashes['input_hash'], 'execution_hash' => $hashes['execution_hash'], 'evidence_hash' => $hashes['evidence_hash'],
        'input_payload' => $input->toArray(), 'execution_spec' => app(ReviewFingerprint::class)->executionSpec(),
        'output' => ['summary' => 'Current grounded interpretation.', 'bet_classification' => 'bet', 'recommendation' => 'spread', 'key_factors' => ['Stored evidence'], 'risk_flags' => []],
        'decision' => ['bet_classification' => 'bet', 'recommendation' => 'spread'],
        'expires_at' => now()->addHour(), 'created_at' => now(),
    ]);
    $work->update(['state' => 'succeeded']);

    return [$game, $approval, $revision];
}

it('serves only a current review and rechecks the deterministic stored quote without writes', function () {
    [$game, $approval] = storedCurrentReviewFixture();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = app(CurrentPredictionReview::class)->forGame($game, 'nba');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($result['status'])->toBe('current')
        ->and($result['context_summary'])->toBe('Current grounded interpretation.')
        ->and($result['summary'])->toContain('Existing deterministic selection: home spread -3.5 at -110')
        ->and($result['decision']['selection']['bet_decision_id'])->toBe($approval->id)
        ->and($result['decision']['selection']['odds'])->toBe(-110.0)
        ->and(collect($queries)->contains(fn ($query) => preg_match('/^\s*(insert|update|delete)/i', $query['query'])))->toBeFalse();
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('hides a saved review after an approval is withdrawn', function () {
    [$game, $approval] = storedCurrentReviewFixture();
    $approval->update(['status' => 'withdrawn']);
    $result = app(CurrentPredictionReview::class)->forGame($game, 'nba');

    expect($result['status'])->toBe('outdated')->and($result['summary'])->toBeNull()
        ->and($result['decision'])->toBeNull();
});

it('does not treat an expired or newly incompatible review as current', function (string $change, string $status) {
    [$game] = storedCurrentReviewFixture();
    if ($change === 'time') {
        $this->travel(61)->minutes();
    } else {
        config(['prediction_review.model' => 'different-model']);
    }
    $result = app(CurrentPredictionReview::class)->forGame($game, 'nba');
    expect($result['status'])->toBe($status)->and($result['summary'])->toBeNull();
})->with([['time', 'expired'], ['model', 'outdated']]);

it('returns disabled or pending states without creating a work item', function () {
    [$game] = Fixtures::game('nba');
    expect(app(CurrentPredictionReview::class)->forGame($game, 'nba')['status'])->toBe('pending');
    config(['prediction_review.enabled' => false]);
    expect(app(CurrentPredictionReview::class)->forGame($game, 'nba')['reason_code'])->toBe('review_disabled');
    $this->assertDatabaseCount('analysis_work_items', 0);
});

it('keeps review history immutable', function () {
    [, , $revision] = storedCurrentReviewFixture();
    expect(fn () => $revision->update(['output' => []]))->toThrow(LogicException::class)
        ->and(fn () => $revision->delete())->toThrow(LogicException::class);
});
