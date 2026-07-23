<?php

use App\Services\NFL\NflSpreadBacktestEvaluator;

it('evaluates home favorite covers against the sportsbook home spread', function () {
    $result = app(NflSpreadBacktestEvaluator::class)->evaluate(
        modelHomeMargin: 6.0,
        entryHomeSpread: -3.0,
        actualHomeMargin: 7.0
    );

    expect($result['market_spread'])->toBe(3.0)
        ->and($result['pick'])->toBe('home')
        ->and($result['cover_margin'])->toBe(4.0)
        ->and($result['result'])->toBe('win');
});

it('evaluates away favorite covers against the sportsbook home spread', function () {
    $result = app(NflSpreadBacktestEvaluator::class)->evaluate(
        modelHomeMargin: -6.0,
        entryHomeSpread: 3.0,
        actualHomeMargin: -7.0
    );

    expect($result['market_spread'])->toBe(-3.0)
        ->and($result['pick'])->toBe('away')
        ->and($result['cover_margin'])->toBe(4.0)
        ->and($result['result'])->toBe('win');
});

it('evaluates home underdog covers against the sportsbook home spread', function () {
    $result = app(NflSpreadBacktestEvaluator::class)->evaluate(
        modelHomeMargin: 1.0,
        entryHomeSpread: 3.5,
        actualHomeMargin: -1.0
    );

    expect($result['market_spread'])->toBe(-3.5)
        ->and($result['pick'])->toBe('home')
        ->and($result['cover_margin'])->toBe(2.5)
        ->and($result['result'])->toBe('win');
});

it('evaluates away underdog covers against the sportsbook home spread', function () {
    $result = app(NflSpreadBacktestEvaluator::class)->evaluate(
        modelHomeMargin: -1.0,
        entryHomeSpread: -3.5,
        actualHomeMargin: 2.0
    );

    expect($result['market_spread'])->toBe(3.5)
        ->and($result['pick'])->toBe('away')
        ->and($result['cover_margin'])->toBe(1.5)
        ->and($result['result'])->toBe('win');
});

it('marks spread pushes and calculates side-aware CLV', function () {
    $homePush = app(NflSpreadBacktestEvaluator::class)->evaluate(
        modelHomeMargin: 6.0,
        entryHomeSpread: -3.0,
        actualHomeMargin: 3.0,
        closingHomeSpread: -4.0
    );

    $awayPush = app(NflSpreadBacktestEvaluator::class)->evaluate(
        modelHomeMargin: -6.0,
        entryHomeSpread: 3.0,
        actualHomeMargin: -3.0,
        closingHomeSpread: 4.0
    );

    expect($homePush['result'])->toBe('push')
        ->and($homePush['clv'])->toBe(1.0)
        ->and($awayPush['result'])->toBe('push')
        ->and($awayPush['clv'])->toBe(1.0);
});

it('flags implausible historical market lines for spread audits', function () {
    config(['nfl.betting.backtest.max_abs_home_spread' => 21.0]);

    $result = app(NflSpreadBacktestEvaluator::class)->evaluate(
        modelHomeMargin: 6.0,
        entryHomeSpread: 27.0,
        actualHomeMargin: 10.0
    );

    expect($result['data_quality_flags'])->toContain('implausible_entry_spread');
});
