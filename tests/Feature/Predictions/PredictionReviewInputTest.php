<?php

use App\Application\Sports\ReadModels\PredictionReviewInput;
use App\Services\Predictions\PredictionReviewDecisionPolicy;
use App\Services\Predictions\PredictionReviewInputBuilder;
use App\Services\Predictions\SportsAiPredictionPayloadBuilder;
use App\Services\Predictions\SportsAiPublishingDecisionPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PredictionReviewFixtures as Fixtures;

beforeEach(function () {
    $this->travelTo('2026-11-20 12:00:00 America/Chicago');
    Http::preventStrayRequests();
    Queue::fake();
});

afterEach(fn () => $this->travelBack());

dataset('review sports', array_keys(Fixtures::baseline()['sports']));

it('resolves the published canonical identity and preserves projection conventions without reading legacy rows', function (string $sport) {
    [$game, $legacy, $native] = Fixtures::game($sport);
    config(["prediction_lifecycle.canonical_reads.{$sport}" => true]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $input = app(PredictionReviewInputBuilder::class)->forGame($game, $sport);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    expect($input->identity)->toMatchArray([
        'source' => 'canonical', 'prediction_table' => 'predictions', 'prediction_id' => $native->id,
        'public_id' => $native->public_id, 'revision' => 1, 'season' => $game->season,
        'season_type' => $game->season_type, 'input_snapshot_id' => $native->calculationRun->event_input_snapshot_id,
    ])->and($input->projection)->toMatchArray([
        'predicted_spread' => 4.5, 'source_home_spread' => -4.5, 'spread_convention' => 'positive_home_margin',
        'home_win_probability' => 0.62, 'predicted_total' => Fixtures::baseline()['sports'][$sport]['total'],
    ])->and($input->decisionContract['classification'])->toBe('pass')
        ->and($queries->contains(fn ($query) => str_contains($query['query'], 'from "'.$legacy->getTable().'"')))->toBeFalse()
        ->and($queries->contains(fn ($query) => preg_match('/^\s*(insert|update|delete)/i', $query['query'])))->toBeFalse();
})->with('review sports');

it('keeps legacy identity separate and preserves the existing NFL decision contract', function (string $sport) {
    [$game, $legacy] = Fixtures::game($sport);
    config(["prediction_lifecycle.canonical_reads.{$sport}" => false]);
    $input = app(PredictionReviewInputBuilder::class)->forGame($game, $sport);

    expect($input->identity)->toMatchArray([
        'source' => 'legacy', 'prediction_table' => $legacy->getTable(), 'prediction_id' => $legacy->id,
        'public_id' => null, 'calculation_run_id' => null, 'input_snapshot_id' => null,
    ])->and($input->projection['predicted_spread'])->toBe(4.5)
        ->and($input->projection['spread_convention'])->toBe('legacy_stored');
    if ($sport === 'nfl') {
        expect($input->decisionContract)->toBe(app(SportsAiPredictionPayloadBuilder::class)->build('nfl', $legacy->fresh())['decision_contract']);
    } else {
        expect($input->decisionContract['classification'])->toBe('pass')
            ->and($input->decisionContract['reason_codes'])->toContain('legacy_decision_contract_not_supported');
    }
})->with('review sports');

it('retains exact existing approval identity price and quote time across sports', function (string $sport) {
    [$game, , $native] = Fixtures::game($sport);
    config(["prediction_lifecycle.canonical_reads.{$sport}" => true]);
    $approval = Fixtures::approval($game, $native);
    $input = app(PredictionReviewInputBuilder::class)->forGame($game, $sport);
    $result = app(PredictionReviewDecisionPolicy::class)->constrain($input, [
        'bet_classification' => 'bet', 'recommendation' => 'total',
        'selection' => ['side' => 'away', 'line' => 99, 'odds' => 999, 'bet_decision_id' => -1],
    ]);

    expect($result['recommendation'])->toBe('spread')
        ->and($result['selection'])->toMatchArray([
            'bet_decision_id' => $approval->id, 'line' => -3.5, 'odds' => -110.0,
            'pick_side' => 'home', 'odds_updated_at' => now()->toIso8601String(),
        ]);
})->with('review sports');

it('does not borrow a legacy forecast when canonical coverage is withdrawn', function () {
    [$game, , $native] = Fixtures::game('nba');
    config(['prediction_lifecycle.canonical_reads.nba' => true]);
    $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);

    expect(app(PredictionReviewInputBuilder::class)->forGame($game, 'nba'))->toBeNull();
});

it('uses shared UTC kickoff and Central business dates across midnight and DST', function (string $observedAt) {
    $this->travelTo($observedAt);
    config(['sports.business_timezone' => 'America/Chicago']);
    [$game, , $native] = Fixtures::game('nba');
    $expectedStart = $native->sportEvent->starts_at->utc()->toIso8601String();
    $expectedDate = $native->sportEvent->starts_at->setTimezone('America/Chicago')->toDateString();
    foreach ([false, true] as $canonical) {
        config(['prediction_lifecycle.canonical_reads.nba' => $canonical]);
        $identity = app(PredictionReviewInputBuilder::class)->forGame($game, 'nba')->identity;
        expect($identity['starts_at'])->toBe($expectedStart)
            ->and($identity['business_date'])->toBe($expectedDate);
    }
})->with(['2026-11-20 19:00:00 America/Chicago', '2026-03-08 00:30:00 America/Chicago']);

it('does not confuse matching numeric IDs in canonical and legacy prediction tables', function () {
    [$game, $legacy, $native] = Fixtures::game('nba');
    expect($legacy->id)->toBe($native->id);
    $legacy->update(['predicted_spread' => 77]);
    config(['prediction_lifecycle.canonical_reads.nba' => true]);
    $input = app(PredictionReviewInputBuilder::class)->forGame($game, 'nba');

    expect($input->projection['predicted_spread'])->toBe(4.5)
        ->and($input->identity['prediction_table'])->toBe('predictions');
});

it('separates frozen NFL recommendation evidence from the primary forecast', function () {
    $frozen = ['version' => 'nfl-recommendation-context-v1', 'outputs' => ['predicted_spread' => 9.0], 'model_metadata' => ['analysis_layer' => ['bet_classification' => 'watch']]];
    [$game] = Fixtures::game('nfl', ['recommendation_context' => $frozen]);
    config(['prediction_lifecycle.canonical_reads.nfl' => true]);
    $input = app(PredictionReviewInputBuilder::class)->forGame($game, 'nfl');

    expect($input->recommendationContext)->toEqual($frozen)
        ->and($input->projection['predicted_spread'])->toBe(4.5)
        ->and($input->decisionContract['classification'])->toBe('pass');
});

it('does not substitute legacy evidence for an unsupported frozen NFL context version', function () {
    [$game] = Fixtures::game('nfl', ['recommendation_context' => ['version' => 'future-version', 'outputs' => ['predicted_spread' => 99]]]);
    config(['prediction_lifecycle.canonical_reads.nfl' => true]);
    expect(app(PredictionReviewInputBuilder::class)->forGame($game, 'nfl')->recommendationContext)->toBeNull();
});

it('excludes unsafe or stale existing approvals rather than letting AI create one', function (array $overrides) {
    [$game, , $native] = Fixtures::game('nba');
    config(['prediction_lifecycle.canonical_reads.nba' => true]);
    Fixtures::approval($game, $native, $overrides);
    $input = app(PredictionReviewInputBuilder::class)->forGame($game, 'nba');
    $result = app(PredictionReviewDecisionPolicy::class)->constrain($input, ['bet_classification' => 'bet', 'recommendation' => 'spread']);

    expect($result['bet_classification'])->toBe('pass')->and($result['selection'])->toBeNull();
})->with([
    'tracking' => [['is_tracking_only' => true]],
    'private' => [['is_public' => false]],
    'withdrawn' => [['status' => 'withdrawn']],
    'missing price' => [['price' => null]],
    'unsafe' => [['pregame_safe' => false]],
    'expired quote' => [['market_snapshot' => ['quote' => ['captured_at' => '2026-11-17T12:00:00-06:00']]]],
    'wrong revision' => [['prediction_id' => 99999]],
]);

it('rejects a mismatched sport and game', function () {
    [$game] = Fixtures::game('nba');
    expect(fn () => app(PredictionReviewInputBuilder::class)->forGame($game, 'nfl'))->toThrow(InvalidArgumentException::class);
});

it('keeps missing canonical markets unavailable instead of fabricating projections', function () {
    [$game, , $native] = Fixtures::game('nba');
    config(['prediction_lifecycle.canonical_reads.nba' => true]);
    // Simulate incomplete stored market coverage without editing released output.
    DB::table('prediction_markets')->where('prediction_id', $native->id)->delete();
    $input = app(PredictionReviewInputBuilder::class)->forGame($game, 'nba');

    expect($input->projection)->toMatchArray([
        'predicted_spread' => null, 'source_home_spread' => null,
        'predicted_total' => null, 'home_win_probability' => null,
    ]);
});

it('permits a review to downgrade or pass an existing selection without changing its quote', function (string $tier, string $market, string $expectedTier, string $expectedMarket) {
    [$game, , $native] = Fixtures::game('nba');
    config(['prediction_lifecycle.canonical_reads.nba' => true]);
    Fixtures::approval($game, $native);
    $input = app(PredictionReviewInputBuilder::class)->forGame($game, 'nba');
    $result = app(PredictionReviewDecisionPolicy::class)->constrain($input, ['bet_classification' => $tier, 'recommendation' => $market]);

    expect($result['bet_classification'])->toBe($expectedTier)
        ->and($result['recommendation'])->toBe($expectedMarket)
        ->and($result['selection'])->toBe($expectedMarket === 'pass' ? null : $input->decisionContract['selection']);
})->with([
    ['lean', 'spread', 'lean', 'spread'],
    ['watch', 'spread', 'watch', 'pass'],
    ['bet', 'pass', 'pass', 'pass'],
    ['unexpected', 'spread', 'pass', 'pass'],
    ['bet', 'unexpected', 'pass', 'pass'],
]);

it('preserves real NFL watch/pass and enforced guardrail behavior', function (bool $enforced, ?array $guardrail) {
    [$game] = Fixtures::game('nfl');
    config(['prediction_lifecycle.canonical_reads.nfl' => false, 'ai.features.publishing_guardrail_review.enforced' => $enforced]);
    $input = app(PredictionReviewInputBuilder::class)->forGame($game, 'nfl');
    $analysis = ['summary' => 'Fixture interpretation.', 'recommendation' => 'spread', 'bet_classification' => 'official_candidate'];
    $existing = app(SportsAiPublishingDecisionPolicy::class)->decide('nfl', ['decision_contract' => $input->decisionContract], $analysis, $guardrail);
    $proposed = app(PredictionReviewDecisionPolicy::class)->constrain($input, $analysis, $guardrail);

    expect($proposed['bet_classification'])->toBe($existing['bet_classification'])
        ->and($proposed['recommendation'])->toBe($existing['recommendation']);
})->with([
    [false, null],
    [true, null],
    [true, ['decision' => 'hold', 'publishable_classification' => 'pass']],
]);

it('locks the existing NFL policy ceiling and compares the new interpretation ceiling', function (array $case) {
    $contract = [
        'schema_version' => 'nfl_deterministic_decision_v1', 'authority' => 'nfl_analysis_and_pro_signal_consensus',
        'classification' => $case['ceiling'], 'recommendation' => $case['market'],
        'selection' => ['market' => 'spread', 'team' => 'Fixture home', 'line' => -3.5, 'edge' => 3.0],
    ];
    $analysis = ['bet_classification' => $case['ai_tier'], 'recommendation' => $case['ai_market'], 'summary' => 'Fixture analysis.'];
    config(['ai.features.publishing_guardrail_review.enforced' => false]);
    $existing = app(SportsAiPublishingDecisionPolicy::class)->decide('nfl', ['decision_contract' => $contract], $analysis, null);
    $input = new PredictionReviewInput(['sport' => 'nfl'], [], null, $contract);
    $proposed = app(PredictionReviewDecisionPolicy::class)->constrain($input, $analysis);

    expect($existing['bet_classification'])->toBe($case['expected_tier'])
        ->and($existing['recommendation'])->toBe($case['expected_market'])
        ->and($proposed['bet_classification'])->toBe($existing['bet_classification'])
        ->and($proposed['recommendation'])->toBe($existing['recommendation']);
})->with(array_map(fn ($case) => [$case], Fixtures::baseline()['nfl_policy_cases']));
