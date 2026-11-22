<?php

use App\AI\Agents\PredictionReviewAgent;
use App\Models\AiGeneration;
use App\Models\NBA\ReviewRevalidation;
use App\Models\PredictionReviewRevision;
use App\Services\Predictions\Review\CurrentPredictionReview;
use App\Services\Predictions\Review\NbaReviewReuse;
use App\Services\Predictions\Review\PredictionReviewCoordinator;
use App\Services\Predictions\Review\PredictionReviewRunner;
use App\Services\Research\GameResearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PredictionReviewFixtures;

beforeEach(function () {
    $this->travelTo('2026-11-20 12:00:00 America/Chicago');
    Http::preventStrayRequests();
    Queue::fake();
    config(['prediction_review.enabled' => true, 'prediction_review.sports.nba' => true,
        'prediction_lifecycle.canonical_reads.nba' => true, 'prediction_review.nba_provenance_reuse_enabled' => true]);
    $this->evidence = [
        'schema_version' => 'game_research_summary_v1', 'sport' => 'nba', 'game_id' => 1,
        'status' => 'partial', 'applicability' => 'current', 'expires_at' => now()->addHours(2)->toIso8601String(),
        'report_id' => 10, 'evidence_hash' => 'first', 'assessed_at' => now()->toIso8601String(),
        'facts' => [['topic' => 'availability', 'team_side' => 'home', 'espn_athlete_id' => '99',
            'claim' => 'ESPN athlete 99: reported status Out.', 'normalized_status' => 'reported_out',
            'evidence_reference' => 'nba_player_injury_snapshot_entries:10', 'snapshot_id' => 10, 'observation_id' => 10,
            'observed_at' => now()->toIso8601String(), 'evidence_status' => 'reference_verified']],
        'sources' => [['source_id' => 1, 'provider' => 'espn', 'publisher' => 'ESPN', 'url' => 'https://example.test/team',
            'parser_version' => 'availability-v1', 'content_hash' => 'same-raw-body', 'snapshot_id' => 10,
            'observed_at' => now()->toIso8601String()]],
        'source_coverage' => ['home' => ['availability' => true], 'away' => ['availability' => true]],
    ];
    $research = Mockery::mock(GameResearchService::class);
    $research->shouldReceive('forGame')->andReturnUsing(fn () => $this->evidence);
    app()->instance(GameResearchService::class, $research);
    PredictionReviewAgent::fake([[
        'recommendation' => 'pass', 'bet_classification' => 'pass', 'ai_confidence' => 50,
        'analysis_confidence' => 50, 'summary' => 'Stored availability is descriptive and the lineup remains unconfirmed.',
        'key_factors' => ['Reported availability.'], 'risk_flags' => ['lineup_unconfirmed'],
        'reason_codes' => ['evidence_gap'], 'market_notes' => ['moneyline' => null, 'spread' => null, 'total' => null, 'props' => null],
    ]])->preventStrayPrompts();
});

afterEach(fn () => $this->travelBack());

function nbaReusePaidReview(object $test): array
{
    [$game] = PredictionReviewFixtures::game('nba');
    $work = app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', $test->evidence);
    expect(app(PredictionReviewRunner::class)->run($work))->toBe('succeeded');

    return [$game, $work, PredictionReviewRevision::query()->sole()];
}

function nbaReuseNewObservation(object $test): void
{
    $test->travel(10)->minutes();
    $test->evidence['expires_at'] = now()->addHours(2)->toIso8601String();
    $test->evidence['report_id'] = 11;
    $test->evidence['evidence_hash'] = 'second';
    $test->evidence['facts'][0]['snapshot_id'] = 11;
    $test->evidence['facts'][0]['observation_id'] = 11;
    $test->evidence['facts'][0]['evidence_reference'] = 'nba_player_injury_snapshot_entries:11';
    $test->evidence['facts'][0]['observed_at'] = now()->toIso8601String();
    $test->evidence['sources'][0]['snapshot_id'] = 11;
    $test->evidence['sources'][0]['observed_at'] = now()->toIso8601String();
}

it('revalidates identical NBA content without another purchase or extending original paid validity', function () {
    [$game, $work, $revision] = nbaReusePaidReview($this);
    $original = $revision->getRawOriginal();
    $usage = AiGeneration::query()->sole()->getRawOriginal();
    nbaReuseNewObservation($this);
    // A read cannot create the association or invoke a provider.
    expect(app(CurrentPredictionReview::class)->forGame($game, 'nba')['status'])->toBe('outdated')
        ->and(ReviewRevalidation::query()->count())->toBe(1);
    $same = app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', $this->evidence);
    expect($same->state)->toBe('succeeded')->and($same->reason_code)->toBe('unchanged_evidence_revalidated')
        ->and($same->desired_fingerprint)->not->toBe($work->desired_fingerprint)
        ->and(app(PredictionReviewRunner::class)->run($same))->toBe('not_due')
        ->and(PredictionReviewRevision::query()->count())->toBe(1)
        ->and($revision->fresh()->getRawOriginal())->toBe($original)
        ->and(AiGeneration::query()->sole()->getRawOriginal())->toBe($usage)
        ->and(ReviewRevalidation::query()->count())->toBe(2);
    $read = app(CurrentPredictionReview::class)->forGame($game, 'nba');
    expect($read['status'])->toBe('current')->and($read['reviewed_at'])->toBe($revision->created_at->toIso8601String());
    $again = app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', $this->evidence);
    expect($again->state)->toBe('succeeded')->and(ReviewRevalidation::query()->count())->toBe(2);
    expect(fn () => ReviewRevalidation::query()->first()->update(['material_hash' => 'changed']))->toThrow(LogicException::class);
});

it('requires new work when NBA meaning, authority, coverage, parser or archived content changes', function (string $path, mixed $value) {
    [$game] = nbaReusePaidReview($this);
    nbaReuseNewObservation($this);
    data_set($this->evidence, $path, $value);
    $changed = app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', $this->evidence);
    expect($changed->state)->toBe('pending')->and(ReviewRevalidation::query()->count())->toBe(1)
        ->and(AiGeneration::query()->count())->toBe(1);
})->with([
    'status' => ['facts.0.normalized_status', 'reported_available'],
    'player' => ['facts.0.espn_athlete_id', '100'],
    'claim' => ['facts.0.claim', 'Different lineup.'],
    'authority' => ['sources.0.source_id', 2],
    'url' => ['sources.0.url', 'https://example.test/other'],
    'parser' => ['sources.0.parser_version', 'availability-v2'],
    'content' => ['sources.0.content_hash', 'changed-raw-body'],
    'coverage' => ['source_coverage.home.availability', false],
    'revoked research' => ['applicability', 'outdated'],
    'expired research' => ['expires_at', '2026-11-20T11:00:00-06:00'],
    'missing research' => ['status', 'unavailable'],
    'manual source' => ['sources.0.provider', 'manual'],
    'missing content identity' => ['sources.0.content_hash', null],
    'unknown future field' => ['facts.0.new_material_field', 'changed'],
]);

it('does not reuse a changed execution configuration or forecast input', function () {
    [$game] = nbaReusePaidReview($this);
    nbaReuseNewObservation($this);
    config(['prediction_review.model' => 'different-model']);
    expect(app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', $this->evidence)->state)->toBe('pending')
        ->and(ReviewRevalidation::query()->count())->toBe(1);
});

it('never resurrects an expired original review with newer source validity', function () {
    config(['prediction_review.valid_minutes' => 5]);
    [$game] = nbaReusePaidReview($this);
    nbaReuseNewObservation($this);
    expect(app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', $this->evidence)->state)->toBe('pending')
        ->and(ReviewRevalidation::query()->count())->toBe(1);
});

it('preserves legacy behavior while the NBA reuse flag is off and never enables NFL reuse', function () {
    config(['prediction_review.nba_provenance_reuse_enabled' => false]);
    [$game] = nbaReusePaidReview($this);
    nbaReuseNewObservation($this);
    expect(app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', $this->evidence)->state)->toBe('pending')
        ->and(ReviewRevalidation::query()->count())->toBe(0);
    config(['prediction_review.nba_provenance_reuse_enabled' => true]);
    expect(app(NbaReviewReuse::class)->enabled('nfl'))->toBeFalse();
});

it('does not steal a running lease during a provenance refresh', function () {
    [$game, $work] = nbaReusePaidReview($this);
    $work->update(['state' => 'running', 'lease_token' => 'active', 'lease_expires_at' => now()->addMinute()]);
    nbaReuseNewObservation($this);
    // Existing reconciliation invalidates a changed-input lease, but cannot reuse its paid result.
    expect(app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', $this->evidence)->state)->toBe('pending')
        ->and(ReviewRevalidation::query()->count())->toBe(1);
});

it('rechecks evidence after reuse and does not write during a read', function () {
    [$game] = nbaReusePaidReview($this);
    nbaReuseNewObservation($this);
    app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', $this->evidence);
    $this->evidence['applicability'] = 'outdated';
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(app(CurrentPredictionReview::class)->forGame($game, 'nba')['status'])->toBe('outdated');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(collect($queries)->contains(fn ($query) => preg_match('/^\s*(insert|update|delete)/i', $query['query'])))->toBeFalse()
        ->and(ReviewRevalidation::query()->count())->toBe(2)->and(AiGeneration::query()->count())->toBe(1);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('does not reuse after an approval quote or forecast changes', function (string $change) {
    [$game, , $prediction] = PredictionReviewFixtures::game('nba');
    $approval = PredictionReviewFixtures::approval($game, $prediction);
    $work = app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', $this->evidence);
    expect(app(PredictionReviewRunner::class)->run($work))->toBe('succeeded');
    nbaReuseNewObservation($this);
    match ($change) {
        'withdrawal' => $approval->update(['status' => 'withdrawn']),
        'quote' => $approval->update(['price' => -115]),
        'forecast' => $prediction->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]),
    };
    $changed = app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', $this->evidence);
    expect($changed?->state)->not->toBe('succeeded')->and(ReviewRevalidation::query()->count())->toBe(1);
})->with(['withdrawal', 'quote', 'forecast']);

it('records a new original receipt after expiry without overwriting the previous association', function () {
    config(['prediction_review.valid_minutes' => 5]);
    [$game, $work, $original] = nbaReusePaidReview($this);
    $this->travel(6)->minutes();
    // Same desired fingerprint, but original paid validity has ended.
    $new = app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', $this->evidence);
    expect($new->desired_fingerprint)->toBe($work->desired_fingerprint)->and($new->state)->toBe('pending');
    PredictionReviewAgent::fake([$original->output])->preventStrayPrompts();
    expect(app(PredictionReviewRunner::class)->run($new))->toBe('succeeded')
        ->and(ReviewRevalidation::query()->count())->toBe(2)
        ->and(PredictionReviewRevision::query()->count())->toBe(2);
    $this->travel(1)->minutes();
    $this->evidence['report_id'] = 20;
    $this->evidence['evidence_hash'] = 'third';
    $reused = app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', $this->evidence);
    expect($reused->state)->toBe('succeeded')->and(AiGeneration::query()->count())->toBe(2)
        ->and(ReviewRevalidation::query()->latest('id')->first()->prediction_review_revision_id)->not->toBe($original->id);
});

it('reports the original expiry after a reused review expires', function () {
    config(['prediction_review.valid_minutes' => 15]);
    [$game] = nbaReusePaidReview($this);
    nbaReuseNewObservation($this);
    expect(app(PredictionReviewCoordinator::class)->reconcileGame($game, 'nba', $this->evidence)->state)->toBe('succeeded');
    $this->travel(6)->minutes();
    expect(app(CurrentPredictionReview::class)->forGame($game, 'nba')['status'])->toBe('expired');
});
