<?php

use App\Services\PlayerStats\NflPlayerEpaCalculator;

test('it calculates estimated nfl player epa from box score inputs', function () {
    $calculator = new NflPlayerEpaCalculator;

    $epa = $calculator->estimateFromBoxScore([
        'passing_yards' => 300,
        'passing_touchdowns' => 3,
        'interceptions_thrown' => 1,
        'sacks_taken' => 2,
        'sack_yards_lost' => 15,
        'passing_two_point_conversions' => 1,
        'rushing_yards' => 20,
        'rushing_attempts' => 3,
    ]);

    expect($epa)->toBe(20.43);
});

test('it normalizes nfl estimated epa by opportunity', function () {
    $calculator = new NflPlayerEpaCalculator;

    $epaPerOpp = $calculator->estimatePerOpportunity(20.43, 33);

    expect($epaPerOpp)->toBe(0.619);
});
