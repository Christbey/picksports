<?php

use App\Support\Odds\MarketSpread;

it('normalizes bookmaker home lines to the home margin convention', function () {
    expect(MarketSpread::bookmakerHomeLineToHomeMargin(-3.5))->toBe(3.5)
        ->and(MarketSpread::bookmakerHomeLineToHomeMargin(2.5))->toBe(-2.5)
        ->and(MarketSpread::homeMarginToBookmakerHomeLine(3.5))->toBe(-3.5);
});

it('calculates model edge after normalizing the bookmaker line', function () {
    expect(MarketSpread::edge(5.0, -3.5))->toBe(1.5)
        ->and(MarketSpread::edge(-1.0, 2.5))->toBe(1.5);
});
