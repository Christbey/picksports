<?php

use App\Support\NflBetRuleEngine;

beforeEach(function () {
    config()->set('nfl.predictions.bet_rules', ['enabled' => true, 'rules' => [[
        'name' => 'finite_fixture', 'action' => 'play', 'market' => 'spread',
        'min_trust' => 60, 'min_abs_spread_edge' => 2,
    ]]]);
});

test('nfl bet rules refuse nonfinite numerical inputs', function (float $trust, ?float $spread, ?float $total) {
    $result = app(NflBetRuleEngine::class)->evaluate([], [], $trust, $spread, $total);

    expect($result['action'])->toBe('pass')
        ->and($result['matched_rules'])->toBe([])
        ->and($result['pass_rules'])->toBe(['invalid_numeric_inputs']);
})->with([
    'nan trust' => [NAN, 3.0, null],
    'infinite trust' => [INF, 3.0, null],
    'negative infinite trust' => [-INF, 3.0, null],
    'nan spread' => [70.0, NAN, null],
    'infinite spread' => [70.0, INF, null],
    'negative infinite spread' => [70.0, -INF, null],
    'nan total' => [70.0, 3.0, NAN],
    'infinite total' => [70.0, 3.0, INF],
    'negative infinite total' => [70.0, 3.0, -INF],
]);

test('nfl bet rules preserve finite playable and below threshold inputs', function () {
    $engine = app(NflBetRuleEngine::class);
    expect($engine->evaluate([], [], 70, -3, null)['action'])->toBe('play')
        ->and($engine->evaluate([], [], 0, -3, null)['action'])->toBe('none')
        ->and($engine->evaluate([], [], 70, 0, null)['action'])->toBe('none')
        ->and($engine->evaluate([], [], 70, null, null)['action'])->toBe('none');
});

test('disabled nfl bet rules retain their disabled contract', function () {
    config()->set('nfl.predictions.bet_rules.enabled', false);
    expect(app(NflBetRuleEngine::class)->evaluate([], [], NAN, INF, null))->toBe([
        'enabled' => false, 'action' => 'disabled', 'matched_rules' => [], 'pass_rules' => [],
    ]);
});
