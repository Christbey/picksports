<?php

use App\Models\BetDecision;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Services\NFL\NflReleasedBetDecisionRecorder;
use App\Services\NFL\Research\RecommendationEligibility;

function nflReleaseCandidateAnalysis(array $overrides = []): array
{
    return array_replace_recursive([
        'applied' => true,
        'bet_classification' => 'bet',
        'eligibility' => ['eligible' => true],
        'calculated_edge' => ['spread_points' => 4.0, 'market_spread' => 3.0],
        'pro_signal_layer' => [
            'tier' => 'official_candidate',
            'market_scores' => ['spread' => ['tier' => 'official_candidate']],
            'recommended_markets' => [['market' => 'spread', 'tier' => 'official_candidate', 'score' => 81]],
        ],
    ], $overrides);
}

it('uses the same strict model gate for candidate keys and recording', function (array $overrides) {
    $prediction = new Prediction(['model_metadata' => ['analysis_layer' => nflReleaseCandidateAnalysis($overrides)]]);
    $game = new Game(['season_type' => '2', 'status' => 'STATUS_SCHEDULED']);
    $game->setRelation('homeTeam', null)->setRelation('awayTeam', null)->setRelation('sportEvent', null);
    $prediction->setRelation('game', $game);
    $recorder = app(NflReleasedBetDecisionRecorder::class);

    expect($recorder->candidateMarketKeys($prediction))->toBe([])
        ->and($recorder->recordWithCoverage($prediction))->toEqual([
            'decisions' => collect(),
            'candidate_market_keys' => [],
            'released_market_keys' => [],
            'missing_market_keys' => [],
            'hold_reasons' => [],
        ]);
})->with([
    'analysis not applied' => [['applied' => false]],
    'truthy is not an applied boolean' => [['applied' => 1]],
    'model lean' => [['bet_classification' => 'lean']],
    'held model bet' => [['bet_classification' => 'research_hold']],
    'data/model eligibility denied' => [['eligibility' => ['eligible' => false]]],
    'truthy is not eligibility approval' => [['eligibility' => ['eligible' => 'true']]],
    'specialist lean is not release approval' => [['pro_signal_layer' => ['tier' => 'lean']]],
    'individual market lean is not release approval' => [['pro_signal_layer' => ['recommended_markets' => [['tier' => 'lean']]]]],
]);

it('filters unsupported markets and preserves the first official recommendation for each market', function () {
    $game = Game::factory()->create([
        'home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id,
        'season_type' => '2',
        'status' => 'STATUS_SCHEDULED',
        'game_date' => now()->addDay(),
    ]);
    $analysis = nflReleaseCandidateAnalysis();
    $analysis['pro_signal_layer']['recommended_markets'] = [
        null,
        'spread',
        ['market' => 'spread', 'tier' => 'lean', 'score' => 99],
        ['market' => 'unsupported', 'tier' => 'official_candidate'],
        ['market' => 'spread', 'tier' => 'official_candidate', 'score' => 81],
        ['market' => 'spread', 'tier' => 'official_candidate', 'score' => 90],
    ];
    $prediction = Prediction::factory()->create(['game_id' => $game->id, 'model_metadata' => ['analysis_layer' => $analysis]]);
    $recorder = app(NflReleasedBetDecisionRecorder::class);
    $coverage = $recorder->recordWithCoverage($prediction);

    expect($recorder->candidateMarketKeys($prediction))->toBe(['spreads'])
        ->and($coverage['candidate_market_keys'])->toBe(['spreads'])
        ->and($coverage['released_market_keys'])->toBe([])
        ->and($coverage['missing_market_keys'])->toBe(['spreads'])
        ->and($coverage['hold_reasons'])->toBe(['spreads' => 'pregame_feature_snapshot_missing'])
        ->and($coverage['decisions']->sole()->score)->toBe(81)
        ->and($coverage['decisions']->sole()->is_bet)->toBeFalse();
});

it('keeps model candidates separate from game eligibility and pregame availability', function (string $seasonType, string $status, bool $past, array $coverageKeys) {
    $game = new Game([
        'season_type' => $seasonType,
        'status' => $status,
        'game_date' => $past ? now()->subDay() : now()->addDay(),
    ]);
    $game->setRelation('homeTeam', null)->setRelation('awayTeam', null)->setRelation('sportEvent', null);
    $prediction = new Prediction(['model_metadata' => ['analysis_layer' => nflReleaseCandidateAnalysis()]]);
    $prediction->setRelation('game', $game);
    $recorder = app(NflReleasedBetDecisionRecorder::class);
    $coverage = $recorder->recordWithCoverage($prediction);

    expect($recorder->candidateMarketKeys($prediction))->toBe(['spreads'])
        ->and($coverage['candidate_market_keys'])->toBe($coverageKeys)
        ->and($coverage['missing_market_keys'])->toBe($coverageKeys)
        ->and($coverage['decisions'])->toBeEmpty()
        ->and(BetDecision::count())->toBe(0);
})->with([
    'preseason is not released' => ['1', 'STATUS_SCHEDULED', false, []],
    'final is not released' => ['2', 'STATUS_FINAL', true, []],
    'scheduled but kickoff passed' => ['2', 'STATUS_SCHEDULED', true, ['spreads']],
]);

it('retains data hold precedence and does not confuse eligible lean evidence with release approval', function () {
    $analysis = nflReleaseCandidateAnalysis(['pro_signal_layer' => ['tier' => 'lean']]);
    $eligibility = app(RecommendationEligibility::class)->evaluate($analysis, []);
    $prediction = new Prediction(['model_metadata' => ['analysis_layer' => $analysis]]);

    expect($eligibility['status'])->toBe('candidate')
        ->and($eligibility['eligible'])->toBeTrue()
        ->and(app(NflReleasedBetDecisionRecorder::class)->candidateMarketKeys($prediction))->toBe([]);

    $analysis['raw_bet_classification'] = 'lean';
    $held = app(RecommendationEligibility::class)->evaluate($analysis, [
        'true_epa' => ['enabled' => true, 'applied' => false],
        'qb_form' => ['reason' => 'insufficient_prior_attempts'],
    ], ['market_stale', 'market_stale']);

    expect($held)->toBe([
        'eligible' => false,
        'status' => 'hold',
        'data_complete' => false,
        'model_approved' => false,
        'data_reasons' => ['market_stale', 'missing_true_epa', 'missing_quarterback_history'],
        'model_reasons' => ['general_model_does_not_approve'],
        'reasons' => ['market_stale', 'missing_true_epa', 'missing_quarterback_history', 'general_model_does_not_approve'],
    ]);
});
