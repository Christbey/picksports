<?php

use App\Actions\NFL\CalculateTeamMetrics;
use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\NFL\Game;
use App\Services\NFL\NflSampleReliability;
use App\Services\NFL\NflSpreadProbability;
use App\Services\NFL\NflSpreadWalkForward;
use App\Services\NFL\NflTrueEpaReadinessService;

it('quarantines custom EPA even when its old production switch is enabled', function () {
    config(['nfl.predictions.true_epa.enabled' => true, 'nfl.predictions.true_epa.custom_epa_quarantined' => true]);
    $action = app(GeneratePredictionFromHistoricalElo::class);
    $result = (new ReflectionMethod($action, 'applyTrueEpaBlend'))->invoke($action, new Game, 3.0, .6, 44.0);
    $metadata = (new ReflectionProperty($action, 'lastModelMetadata'))->getValue($action);
    expect($result)->toBe([3.0, .6, 44.0])
        ->and($metadata['true_epa'])->toMatchArray(['enabled' => false, 'applied' => false, 'quarantined' => true, 'reason' => 'custom_epa_quarantined']);
    $calculator = Mockery::mock(CalculateTeamMetrics::class);
    $calculator->shouldNotReceive('execute');
    expect((new NflTrueEpaReadinessService($calculator))->prepare(collect([new Game]))['attempted'])->toBe(0);
});

it('uses the smaller actual team sample and preserves the prior early in the season', function () {
    config(['nfl.predictions.sample_reliability.prior_games' => 8]);
    $service = app(NflSampleReliability::class);
    expect($service->weight(.35, 0, 5))->toBe(0.0)
        ->and($service->weight(.35, 2, 8))->toEqualWithDelta(.07, .000001)
        ->and($service->weight(.35, 8, 8))->toBe(.175)
        ->and($service->weight(.35, 17, 17))->toBeLessThan(.35);
});

it('keeps cover pushes losses and price-aware shadow EV separate', function () {
    $residuals = [...array_fill(0, 100, 0.0), ...array_fill(0, 50, -1.0), ...array_fill(0, 50, -2.0)];
    $p = app(NflSpreadProbability::class)->estimate($residuals, 4, -3, -110);
    expect($p)->toMatchArray(['selection' => 'home', 'cover_probability' => .5, 'push_probability' => .25, 'loss_probability' => .25, 'calibrated' => false, 'bet_eligible' => false])
        ->and($p['conditional_cover_probability'])->toBe(2 / 3)
        ->and($p['expected_profit_per_unit'])->toBeGreaterThan(.20);
    $away = app(NflSpreadProbability::class)->estimate(array_map(fn ($r) => -$r, $residuals), -4, 3);
    expect($away['selection'])->toBe('away')->and($away['cover_probability'])->toBe(.5);
    $half = app(NflSpreadProbability::class)->estimate($residuals, 4, -3.5);
    expect($half['push_probability'])->toBe(0);
});

it('never substitutes winner confidence for missing spread evidence', function () {
    $service = app(NflSpreadProbability::class);
    expect($service->estimate([], 10, -7)['cover_probability'])->toBeNull()
        ->and($service->estimate(array_fill(0, 200, 0), 7, -7)['reason'])->toBe('no_directional_edge')
        ->and($service->estimate(array_fill(0, 200, 0), 10, -7.2)['reason'])->toBe('invalid_margin_or_line')
        ->and($service->estimate([...array_fill(0, 199, 0), NAN], 10, -7)['reason'])->toBe('invalid_residual');
});

function nflValidationRow(int $id, array $overrides = []): array
{
    return array_replace([
        'game_id' => $id, 'season' => 2025, 'week' => 1, 'model_version' => 'test-v1',
        'kickoff' => '2025-09-07T17:00:00Z', 'generated_at' => '2025-09-07T15:00:00Z',
        'quote_at' => '2025-09-07T14:00:00Z', 'result_available_at' => '2025-09-07T21:00:00Z',
        'home_line' => -3, 'model_margin' => 4, 'actual_margin' => 7, 'elo_market_margin' => 3.5,
    ], $overrides);
}

it('normalizes timezones excludes hindsight and never pools future or different-version residuals', function () {
    $rows = [nflValidationRow(1), nflValidationRow(2, ['generated_at' => '2025-09-07T16:00:00Z']),
        nflValidationRow(3, ['kickoff' => '2025-09-14T17:00:00Z', 'generated_at' => '2025-09-14T10:00:00-05:00', 'result_available_at' => '2025-09-14T21:00:00Z']),
        nflValidationRow(4, ['model_version' => 'different', 'kickoff' => '2025-09-14T17:00:00Z', 'generated_at' => '2025-09-14T15:00:00Z', 'result_available_at' => '2025-09-14T21:00:00Z']),
        nflValidationRow(5, ['generated_at' => '2025-09-07T13:00:00-05:00']),
        nflValidationRow(6, ['quote_at' => '2025-09-08T14:00:00Z']),
        nflValidationRow(7, ['kickoff' => 'not-a-date']),
    ];
    $report = app(NflSpreadWalkForward::class)->evaluate($rows);
    $predictions = collect($report['predictions'])->where('variant', 'recorded_model')->keyBy('game_id');
    expect($report['accepted_games'])->toBe(4)
        ->and($report['excluded']['not_observed_pregame'])->toBe(2)
        ->and($report['excluded']['invalid_timestamp'])->toBe(1)
        ->and($predictions[1]['training_samples'])->toBe(0)
        ->and($predictions[2]['training_samples'])->toBe(0)
        ->and($predictions[3]['training_samples'])->toBe(2)
        ->and($predictions[4]['training_samples'])->toBe(0)
        ->and($report['coverage']['validated_epa_market'])->toBe(['available' => 0, 'missing' => 4])
        ->and($report['reports']['market']['all']['no_pick'])->toBe(4)
        ->and($report['promotion_allowed'])->toBeFalse();
});

it('selects only one pregame forecast per game and reports pushes separately', function () {
    $report = app(NflSpreadWalkForward::class)->evaluate([
        nflValidationRow(1), nflValidationRow(1, ['generated_at' => '2025-09-07T16:00:00Z', 'model_margin' => 2]),
        nflValidationRow(2, ['actual_margin' => 3]),
    ]);
    expect($report['accepted_games'])->toBe(2)
        ->and($report['reports']['recorded_model']['all'])->toMatchArray(['wins' => 0, 'losses' => 1, 'pushes' => 1, 'win_rate' => 0]);
});
