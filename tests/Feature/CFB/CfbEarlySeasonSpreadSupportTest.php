<?php

use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\EventInputSnapshot;
use App\Models\PredictionMarket;
use App\Services\CFB\CfbMarketMovementSignalService;
use App\Services\CFB\CfbSpreadMarketConfirmation;
use App\Services\CFB\Predictions\CfbCanonicalSpreadValueSignalService;
use App\Services\CFB\Predictions\CfbEarlySeasonSpreadSupport;
use App\Services\CFB\Predictions\CfbSpreadCoverProbabilityService;

function earlySpreadEvidence(): array
{
    $team = ['elo' => 1550, 'metrics' => ['record_season' => 2026, 'wins' => 2, 'losses' => 0,
        'points_per_game' => 30, 'points_allowed_per_game' => 20, 'fpi' => 10],
        'prior_metrics' => ['record_season' => 2025, 'wins' => 8, 'losses' => 4,
            'points_per_game' => 28, 'points_allowed_per_game' => 21],
        'prior_metric_evidence' => ['metric_id' => 1, 'observed_at' => now()->subDays(200)->toIso8601String()],
        'rating_evidence' => ['rating_id' => 1, 'source' => 'cfbd_fpi', 'units' => 'points_above_average',
            'season' => 2026, 'observed_at' => now()->subDay()->toIso8601String()],
        'availability' => ['status' => 'last_observed', 'quarterback_hold' => false]];

    return ['event' => ['season' => 2026, 'week' => 3], 'home' => $team, 'away' => $team];
}

it('supports one to three real games with independently verified prior evidence without inflating the sample', function (int $games) {
    $inputs = earlySpreadEvidence();
    $inputs['home']['metrics']['wins'] = $games;
    $result = app(CfbEarlySeasonSpreadSupport::class)->assess($inputs, ['spread_baseline' => 'fpi_points'], now()->toImmutable(), 'verified');
    expect($result['eligible'])->toBeTrue()->and($result['current_games']['home'])->toBe($games)
        ->and($result['prior_games']['home'])->toBe(12);
})->with([1, 2, 3]);

it('rejects missing, stale, wrong-season, future, or unresolved evidence', function (string $path, mixed $value) {
    $inputs = earlySpreadEvidence();
    data_set($inputs, $path, match ($value) {
        'FUTURE' => now()->addDay()->toIso8601String(),
        'STALE' => now()->subDays(15)->toIso8601String(),
        default => $value,
    });
    expect(app(CfbEarlySeasonSpreadSupport::class)->assess($inputs, ['spread_baseline' => 'fpi_points'], now()->toImmutable(), 'verified')['eligible'])->toBeFalse();
})->with([
    ['home.prior_metrics', null], ['away.prior_metrics.record_season', 2024],
    ['away.prior_metrics.wins', 1], ['home.prior_metrics.points_per_game', null],
    ['home.prior_metric_evidence.observed_at', 'FUTURE'], ['away.prior_metric_evidence.metric_id', null],
    ['home.rating_evidence.observed_at', 'STALE'], ['away.rating_evidence.observed_at', 'FUTURE'],
    ['home.rating_evidence.season', 2025], ['away.rating_evidence.units', 'rank'],
    ['home.metrics.fpi', null], ['away.metrics.record_season', 2025], ['home.metrics.wins', 0],
    ['home.availability.status', 'unknown'], ['away.availability.quarterback_hold', true],
    ['event.week', 9], ['event.week', null],
]);

it('requires the point scale model and a verified snapshot and respects the kill switch', function () {
    $service = app(CfbEarlySeasonSpreadSupport::class);
    expect($service->assess(earlySpreadEvidence(), [], now()->toImmutable(), 'verified')['eligible'])->toBeFalse()
        ->and($service->assess(earlySpreadEvidence(), ['spread_baseline' => 'fpi_points'], now()->toImmutable(), 'unknown')['eligible'])->toBeFalse();
    config()->set('cfb.predictions.spread_value.early_season.enabled', false);
    expect($service->assess(earlySpreadEvidence(), ['spread_baseline' => 'fpi_points'], now()->toImmutable(), 'verified')['eligible'])->toBeFalse();
});

function earlySpreadSignal(array $inputs, bool $freshQuote = true, string $baseline = 'fpi_points', float $modelLine = -25, float $marketLine = -20.5, bool $calibrated = true): array
{
    $snapshot = new EventInputSnapshot(['inputs' => $inputs, 'captured_at' => now(), 'pregame_safety_status' => 'verified']);
    $run = new CalculationRun(['diagnostics' => ['spread_baseline' => $baseline, 'metric_reliability' => min(1, min($inputs['home']['metrics']['wins'], $inputs['away']['metrics']['wins']) / 6)]]);
    $run->setRelation('inputSnapshot', $snapshot);
    $prediction = new CanonicalPrediction(['generated_at' => now()]);
    $prediction->setRelation('calculationRun', $run);
    $prediction->setRelation('markets', collect([new PredictionMarket(['market_type' => 'spread', 'selection' => 'home', 'projected_line' => $modelLine])]));
    $game = new Game(['status' => 'STATUS_SCHEDULED']);
    $game->setRelation('homeTeam', null)->setRelation('awayTeam', null);
    $market = Mockery::mock(CfbMarketMovementSignalService::class);
    $market->shouldReceive('spreadContext')->andReturn(['current_bookmaker_home_line' => $marketLine,
        'current_home_margin' => -$marketLine, 'current_book_count' => 2,
        'current_captured_at' => ($freshQuote ? now() : now()->subDay())->toIso8601String()]);

    app()->instance(CfbSpreadMarketConfirmation::class,
        Mockery::mock(CfbSpreadMarketConfirmation::class)->shouldReceive('assess')->andReturn([
            'supported' => $freshQuote, 'confirming_book_count' => 2,
            'risk_flags' => $freshQuote ? [] : ['stale_market_quote'],
            'best_quote' => ['line' => -$modelLine + $marketLine >= 0 ? $marketLine : -$marketLine, 'price' => -110, 'bookmaker' => 'test', 'quote_id' => 1,
                'provider_observed_at' => now()->toIso8601String()],
        ])->getMock());
    app()->instance(CfbSpreadCoverProbabilityService::class,
        Mockery::mock(CfbSpreadCoverProbabilityService::class)->shouldReceive('assess')->andReturn([
            'status' => $calibrated ? 'calibrated' : 'unavailable', 'cover_probability' => $calibrated ? .57 : null, 'expected_value_per_unit' => $calibrated ? .08 : null,
            'positive_expected_value' => $calibrated, 'risk_flags' => $calibrated ? [] : ['spread_calibration_unavailable'],
        ])->getMock());

    return (new CfbCanonicalSpreadValueSignalService($market))->forPrediction($prediction, $game);
}

it('promotes a supported early-season edge while retaining the low actual reliability and sample', function () {
    $result = earlySpreadSignal(earlySpreadEvidence());
    expect($result['has_playable_value'])->toBeTrue()
        ->and($result['best']['statistical_support']['support_path'])->toBe('early_season_prior_and_fpi')
        ->and($result['best']['statistical_support']['home_sample_games'])->toBe(2)
        ->and($result['best']['statistical_support']['metric_reliability'])->toBe(0.333);
});

it('does not let the exception override quote or availability holds', function () {
    expect(earlySpreadSignal(earlySpreadEvidence(), false)['has_playable_value'])->toBeFalse();
    $inputs = earlySpreadEvidence();
    $inputs['away']['availability']['quarterback_hold'] = true;
    expect(earlySpreadSignal($inputs)['has_playable_value'])->toBeFalse();
});

it('keeps the mature current-season support path without requiring historical priors', function () {
    $inputs = earlySpreadEvidence();
    foreach (['home', 'away'] as $side) {
        $inputs[$side]['metrics']['wins'] = 6;
        unset($inputs[$side]['prior_metrics'], $inputs[$side]['prior_metric_evidence']);
    }
    $result = earlySpreadSignal($inputs);
    expect($result['has_playable_value'])->toBeTrue()
        ->and($result['best']['statistical_support']['support_path'])->toBe('current_season_sample');
});

it('shows Auburn style small-edge selections with actual prices and specific personnel warnings', function () {
    $inputs = earlySpreadEvidence();
    $inputs['require_personnel_evidence'] = true;
    foreach (['home', 'away'] as $side) {
        $inputs[$side]['personnel'] = ['coverage_complete' => false, 'missing_components' => ['head_coach']];
    }
    $result = earlySpreadSignal($inputs, modelLine: 0, marketLine: 2.5, calibrated: false);
    expect($result['decision_status'])->toBe('provisional')
        ->and($result['best']['side'])->toBe('home')->and($result['best']['edge'])->toBe(2.5)
        ->and($result['best']['price'])->toBe(-110)->and($result['best']['market_line'])->toBe(2.5)
        ->and($result['best']['risk_flags'])->toContain('home_personnel_head_coach_unverified', 'point_edge_below_threshold')
        ->and($result['best']['risk_flags'])->not->toContain('insufficient_team_metric_sample', 'spread_price_unavailable')
        ->and($result['best']['cover_probability'])->toBeNull()->and($result['has_playable_value'])->toBeFalse();
});

it('keeps supported selections visible without calibration and reports exact ties without a forced side', function () {
    $result = earlySpreadSignal(earlySpreadEvidence(), calibrated: false);
    expect($result['decision_status'])->toBe('provisional')->and($result['best']['grade'])->toBe('Provisional')
        ->and($result['best']['expected_value_per_unit'])->toBeNull()->and($result['has_playable_value'])->toBeFalse();
    $tie = earlySpreadSignal(earlySpreadEvidence(), modelLine: -20.5, calibrated: false);
    expect($tie['decision_status'])->toBe('no_edge')->and($tie['best']['label'])->toBe('No directional edge');
});

it('keeps explicit quarterback conflicts blocked while retaining the model direction', function () {
    $inputs = earlySpreadEvidence();
    $inputs['home']['availability']['quarterback_hold'] = true;
    $result = earlySpreadSignal($inputs, calibrated: false);
    expect($result['decision_status'])->toBe('blocked')->and($result['best']['side'])->toBe('home')
        ->and($result['has_playable_value'])->toBeFalse();
});

it('still suppresses directional picks with missing essential model inputs', function () {
    $inputs = earlySpreadEvidence();
    $inputs['away']['metrics']['points_per_game'] = null;
    $result = earlySpreadSignal($inputs, calibrated: false);
    expect($result['decision_status'])->toBe('unavailable')->and($result['best'])->toBeNull();
});

it('preserves the away underdog sign when the model is a tie', function () {
    $result = earlySpreadSignal(earlySpreadEvidence(), modelLine: 0, marketLine: -2.5, calibrated: false);
    expect($result['decision_status'])->toBe('provisional')->and($result['best']['side'])->toBe('away')
        ->and($result['best']['market_line'])->toBe(2.5)->and($result['best']['edge'])->toBe(2.5)
        ->and($result['best']['market_home_line'])->toBe(-2.5);
});
