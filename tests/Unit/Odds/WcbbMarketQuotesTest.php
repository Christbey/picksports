<?php

use App\Support\Odds\WcbbMarketQuotes;

function wcbbQuoteGame(): object
{
    return (object) [
        'homeTeam' => (object) ['school' => 'Home', 'mascot' => 'Team'],
        'awayTeam' => (object) ['school' => 'Away', 'mascot' => 'Team'],
        'odds_data' => [],
    ];
}

it('refuses non-finite WCBB prices and paired spread points from stored numeric strings', function (string $field, mixed $home, mixed $away) {
    $market = ['outcomes' => [
        ['name' => 'Home Team', 'price' => -110, 'point' => -5],
        ['name' => 'Away Team', 'price' => -110, 'point' => 5],
    ]];
    $market['outcomes'][0][$field] = $home;
    $market['outcomes'][1][$field] = $away;

    expect((new WcbbMarketQuotes)->teams(wcbbQuoteGame(), $market, 'spreads'))->toBeNull();
})->with([
    'overflowing prices' => ['price', '1e999', '-1e999'],
    'overflowing paired points' => ['point', '1e999', '-1e999'],
    'native infinite prices' => ['price', INF, -INF],
    'native NaN point' => ['point', NAN, 5],
]);

it('preserves finite signed zero and exact selected WCBB quote values', function () {
    $market = ['outcomes' => [
        ['name' => 'Away Team', 'price' => '-115', 'point' => '0'],
        ['name' => 'Home Team', 'price' => '-105', 'point' => '-0'],
    ]];

    $quotes = (new WcbbMarketQuotes)->teams(wcbbQuoteGame(), $market, 'spreads');

    expect($quotes['home'])->toBe($market['outcomes'][1])
        ->and($quotes['away'])->toBe($market['outcomes'][0]);
});
