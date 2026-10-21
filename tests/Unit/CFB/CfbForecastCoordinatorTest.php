<?php

use App\Services\CFB\Predictions\CfbForecastCoordinationStudy;
use App\Services\CFB\Predictions\CfbForecastCoordinator;

function coordinationPolicy(array $changes = []): array
{
    return [...['margin_alternative_weight' => 0.5, 'total_alternative_weight' => 0.5,
        'margin_market_weight' => 0.1, 'total_market_weight' => 0.1], ...$changes];
}

function coordinationInput(array $changes = []): array
{
    return [...['primary_margin' => 10.0, 'primary_total' => 60.0, 'result_margin' => -2.0,
        'result_total' => 50.0, 'opponent_total' => 54.0, 'market_margin' => 100.0, 'market_total' => 100.0], ...$changes];
}

function coordinationRows(int $count = 60, string $day = '2026-09-01'): array
{
    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
        $rows[] = [...coordinationInput(['market_margin' => 4.0, 'market_total' => 56.0]),
            'game_id' => $i, 'prediction_id' => 'p'.$i, 'formula_hash' => 'same-formula',
            'kickoff' => $day.'T18:00:00-05:00', 'captured_at' => $day.'T12:00:00-05:00',
            'pregame_safe' => true, 'published_margin' => 10.0, 'published_total' => 60.0,
            'actual_margin' => 4.0, 'actual_total' => 56.0];
    }

    return $rows;
}

it('combines team forecasts before applying independently bounded market influence', function () {
    $p = (new CfbForecastCoordinator)->forecast(coordinationInput(), coordinationPolicy());
    expect($p['independent_home_margin'])->toBe(4.0)->and($p['independent_total'])->toBe(56.0)
        ->and($p['home_margin'])->toBe(5.0)->and($p['home_line'])->toBe(-5.0)->and($p['total'])->toBe(58.0)
        ->and($p['market_effect_points'])->toBe(['margin' => 1.0, 'total' => 2.0])
        ->and($p['home_points'] + $p['away_points'])->toBe(58.0)->and($p['winner_disagreement'])->toBeTrue();
});

it('falls back to the independent primary without silently borrowing a market forecast', function () {
    $p = (new CfbForecastCoordinator)->forecast(coordinationInput([
        'result_margin' => null, 'result_total' => null, 'opponent_total' => 90.0,
        'market_margin' => null, 'market_total' => null,
    ]), coordinationPolicy());
    expect($p['home_margin'])->toBe(10.0)->and($p['total'])->toBe(60.0)
        ->and($p['effective_weights'])->toBe(['margin_alternative' => 0.0, 'total_alternative' => 0.0, 'margin_market' => 0.0, 'total_market' => 0.0])
        ->and($p['fallbacks'])->toHaveCount(4);
});

it('rejects market dominance, invalid weights, and nonfinite inputs', function (array $input, array $policy) {
    expect(fn () => (new CfbForecastCoordinator)->forecast($input, $policy))->toThrow(InvalidArgumentException::class);
})->with([
    'half market' => [coordinationInput(), coordinationPolicy(['margin_market_weight' => 0.5])],
    'above cap' => [coordinationInput(), coordinationPolicy(['total_market_weight' => 0.10001])],
    'negative' => [coordinationInput(), coordinationPolicy(['margin_alternative_weight' => -0.1])],
    'nonfinite' => [coordinationInput(['result_total' => INF]), coordinationPolicy()],
    'impossible primary' => [coordinationInput(['primary_total' => 5.0]), coordinationPolicy()],
]);

it('preserves feasible team scores without exceeding the market caps at the scoring boundary', function () {
    $p = (new CfbForecastCoordinator)->forecast(coordinationInput([
        'primary_margin' => 30.0, 'primary_total' => 30.0, 'result_margin' => 30.0,
        'result_total' => 30.0, 'opponent_total' => 30.0, 'market_margin' => 1000.0, 'market_total' => 0.0,
    ]), coordinationPolicy());
    expect($p['home_margin'])->toBe(31.0)->and($p['total'])->toBe(31.0)
        ->and($p['away_points'])->toBe(0.0)->and(abs($p['market_effect_points']['margin']))->toBeLessThanOrEqual(1.0)
        ->and(abs($p['market_effect_points']['total']))->toBeLessThanOrEqual(2.0);
});

it('removes indirect market rules while preserving team-only coefficients and recomputing caps', function () {
    $configuration = ['weighting' => 'joint_ridge_v1', 'maximum_spread_adjustment' => 2,
        'catalog' => ['market' => ['inputs' => ['context.spread']], 'team' => ['inputs' => ['team.metrics.points_per_game']]]];
    $p = (new CfbForecastCoordinator)->independentSignals(['signals' => [
        ['id' => 'market', 'market' => 'spread', 'family' => 'market', 'raw_correction' => -10.0],
        ['id' => 'team', 'market' => 'spread', 'family' => 'team', 'raw_correction' => 3.0],
    ]], $configuration);
    expect($p)->toBe(['spread' => 2.0, 'total' => 0.0, 'removed_rule_ids' => ['market']]);
    foreach (['team.history.away.ats_cover_rate', 'team.history.last3.over_rate', 'team.history.conference.under_rate'] as $path) {
        expect((new CfbForecastCoordinator)->marketDependent(['inputs' => [$path]]))->toBeTrue();
    }
});

it('rejects contributing signals whose original definitions are unavailable', function () {
    expect(fn () => (new CfbForecastCoordinator)->independentSignals(['signals' => [
        ['id' => 'unknown', 'market' => 'spread', 'family' => 'team', 'raw_correction' => 1],
    ]], []))->toThrow(InvalidArgumentException::class);
});

it('selects on training only and evaluates later games without changing the policy', function () {
    $study = new CfbForecastCoordinationStudy(new CfbForecastCoordinator);
    $training = coordinationRows();
    $artifact = $study->select($training);
    $validation = array_map(fn ($r) => [...$r, 'game_id' => $r['game_id'] + 100], coordinationRows(40, '2026-09-08'));
    $frozen = $artifact;
    $good = $study->evaluate($artifact, $validation);
    $bad = $study->evaluate($artifact, array_map(fn ($r) => [...$r, 'actual_margin' => 10.0, 'actual_total' => 60.0], $validation));
    expect($artifact)->toBe($frozen)->and($good['policy_hash'])->toBe($bad['policy_hash'])
        ->and($good['temporal_validation_passed'])->toBeTrue()->and($bad['temporal_validation_passed'])->toBeFalse()
        ->and($artifact['policy']['margin_market_weight'])->toBe(0.0)
        ->and($artifact['policy']['total_market_weight'])->toBe(0.0)
        ->and($good['automatic_release_activation'])->toBeFalse();
});

it('rejects duplicate games, future input capture, and incompatible formulas', function (string $change) {
    $rows = coordinationRows();
    if ($change === 'duplicate') {
        $rows[] = $rows[0];
    } elseif ($change === 'future') {
        $rows[0]['captured_at'] = '2026-09-02T00:00:00-05:00';
    } else {
        $rows[0]['formula_hash'] = 'different';
    }
    expect(fn () => (new CfbForecastCoordinationStudy(new CfbForecastCoordinator))->select($rows))->toThrow(InvalidArgumentException::class);
})->with(['duplicate', 'future', 'formula']);

it('prioritizes correct winners over smaller margin errors when selecting an offline challenger', function () {
    $rows = array_map(fn ($r) => [...$r, 'primary_margin' => 1.0, 'primary_total' => 200.0,
        'result_margin' => -100.0, 'result_total' => 200.0, 'opponent_total' => 200.0,
        'market_margin' => null, 'market_total' => null, 'actual_margin' => -1.0, 'actual_total' => 200.0], coordinationRows());
    $artifact = (new CfbForecastCoordinationStudy(new CfbForecastCoordinator))->select($rows);
    expect($artifact['policy']['margin_alternative_weight'])->toBe(0.25)
        ->and($artifact['selection_objective']['margin'])->toBe('fewest_outright_winner_mistakes_then_margin_mae')
        ->and($artifact['automatic_release_activation'])->toBeFalse();
});

it('rejects overlapping validation dates and policy tampering', function () {
    $study = new CfbForecastCoordinationStudy(new CfbForecastCoordinator);
    $artifact = $study->select(coordinationRows());
    $sameDay = array_map(fn ($r) => [...$r, 'game_id' => $r['game_id'] + 100], coordinationRows(40));
    expect(fn () => $study->evaluate($artifact, $sameDay))->toThrow(InvalidArgumentException::class);
    $artifact['policy']['margin_market_weight'] = 0.1;
    expect(fn () => $study->evaluate($artifact, []))->toThrow(InvalidArgumentException::class);
});

it('never lets the market overturn a team-model winner or decide a team-model tie', function (float $margin) {
    $p = (new CfbForecastCoordinator)->forecast(coordinationInput([
        'primary_margin' => $margin, 'result_margin' => $margin, 'market_margin' => $margin > 0 ? -1000 : 1000,
    ]), coordinationPolicy());
    expect($margin == 0 ? $p['home_margin'] == 0 : $p['home_margin'] * $margin > 0)->toBeTrue()
        ->and(abs($p['market_effect_points']['margin']))->toBeLessThanOrEqual(1.0);
})->with([0.2, -0.2, 0.0]);
