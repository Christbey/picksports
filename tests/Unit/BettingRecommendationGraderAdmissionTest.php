<?php

use App\Support\BettingRecommendationGrader;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['audit.betting.edge_thresholds.spread' => 2.5, 'audit.betting.edge_thresholds.total' => 3., 'audit.betting.edge_thresholds.moneyline' => .05]);
});

it('declines nonfinite recommendation values with serializable unknown fields', function (string $type, string $field, mixed $value) {
    $result = (new BettingRecommendationGrader)->grade(
        ['type' => $type, 'edge' => 8., 'market_line' => 0., 'kelly_bet_size_percent' => 4., $field => $value],
        (object) ['week' => 8, 'odds_updated_at' => '2026-01-01'],
        (object) ['model_metadata' => ['market_blend' => ['applied' => true]]],
        'audit',
    );
    expect($result['is_playable'])->toBeFalse()
        ->and($result['grade'])->toBe('Pass')
        ->and($result['bet_units'])->toBe(0.0)
        ->and($result['risk_flags'])->toContain('invalid_numeric_inputs')
        ->and($result[$field])->toBeNull();
    expect(json_encode($result, JSON_THROW_ON_ERROR))->toBeString();
})->with([
    ['spread', 'edge', NAN], ['spread', 'edge', INF], ['total', 'edge', -INF],
    ['moneyline', 'kelly_bet_size_percent', NAN], ['spread', 'market_line', INF],
    ['total', 'odds', '1e999'], ['moneyline', 'edge', 'unavailable'],
]);

it('assigns no stake to a passing moneyline recommendation despite positive kelly', function () {
    $result = (new BettingRecommendationGrader)->grade(
        ['type' => 'moneyline', 'edge' => 1., 'kelly_bet_size_percent' => 4.],
        (object) [], (object) [], 'audit',
    );
    expect($result['grade'])->toBe('Pass')->and($result['is_playable'])->toBeFalse()->and($result['bet_units'])->toBe(0.0);
});

it('preserves finite spread zero lines and a qualified stake', function () {
    $result = (new BettingRecommendationGrader)->grade(
        ['type' => 'spread', 'edge' => 8., 'market_line' => 0.],
        (object) ['week' => 8, 'odds_updated_at' => '2026-01-01'],
        (object) ['model_metadata' => ['market_blend' => ['applied' => true]]], 'audit',
    );
    expect($result['grade'])->toBe('A')->and($result['is_playable'])->toBeTrue()->and($result['market_line'])->toBe(0.0)->and($result['bet_units'])->toBe(1.9);
});
