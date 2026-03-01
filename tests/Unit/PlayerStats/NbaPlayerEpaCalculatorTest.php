<?php

use App\Services\PlayerStats\NbaPlayerEpaCalculator;

test('it calculates estimated epa from nba box score inputs', function () {
    $calculator = new NbaPlayerEpaCalculator;

    $epa = $calculator->estimateFromBoxScore(
        points: 20,
        assists: 8,
        reboundsTotal: 5,
        steals: 2,
        blocks: 1,
        turnovers: 3,
        fieldGoalsMade: 8,
        fieldGoalsAttempted: 15,
        freeThrowsMade: 4,
        freeThrowsAttempted: 5
    );

    expect($epa)->toBe(15.89);
});

test('it calculates estimated epa from cbb profile inputs', function () {
    $calculator = new NbaPlayerEpaCalculator;

    $epa = $calculator->estimateFromBoxScore(
        points: 20,
        assists: 8,
        reboundsTotal: 5,
        steals: 2,
        blocks: 1,
        turnovers: 3,
        fieldGoalsMade: 8,
        fieldGoalsAttempted: 15,
        freeThrowsMade: 4,
        freeThrowsAttempted: 5,
        profile: NbaPlayerEpaCalculator::PROFILE_CBB
    );

    expect($epa)->toBe(14.17);
});

test('it normalizes estimated epa per 36 minutes', function () {
    $calculator = new NbaPlayerEpaCalculator;

    $epaPer36 = $calculator->estimatePer36(15.89, '30:00');

    expect($epaPer36)->toBe(19.07);
});

test('it parses minutes in multiple formats', function () {
    $calculator = new NbaPlayerEpaCalculator;

    expect($calculator->minutesToDecimal('33:30'))->toBe(33.5)
        ->and($calculator->minutesToDecimal('30'))->toBe(30.0)
        ->and($calculator->minutesToDecimal(28.2))->toBe(28.2)
        ->and($calculator->minutesToDecimal(null))->toBe(0.0);
});
