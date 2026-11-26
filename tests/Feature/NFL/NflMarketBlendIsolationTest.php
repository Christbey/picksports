<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\NFL\Game;
use App\Services\NFL\Predictions\NflMarketQuoteLines;

function nflBlendProbe(array $markets, float $weight = 0.5): array
{
    config(['nfl.predictions.market_blend.enabled' => true,
        'nfl.predictions.market_blend.spread_model_weight' => $weight,
        'nfl.predictions.market_blend.total_model_weight' => 0.6]);
    $game = new Game(['odds_data' => ['home_team' => 'Home', 'bookmakers' => [['markets' => $markets]]]]);
    $calculator = app(GeneratePredictionFromHistoricalElo::class);

    return (new ReflectionMethod($calculator, 'applyMarketBlend'))->invoke($calculator, $game, 4.0, 0.73, 44.0);
}

it('does not let a totals-only quote replace the model winner probability', function () {
    expect(nflBlendProbe([['key' => 'totals', 'outcomes' => [['point' => 49]]]]))
        ->toBe([4.0, 0.73, 46.0]);
});

it('does not replace probability when the configured spread is model-only', function () {
    expect(nflBlendProbe([['key' => 'spreads', 'outcomes' => [['name' => 'Home', 'point' => -8]]]], 1.0))
        ->toBe([4.0, 0.73, 44.0]);
});

it('retains the normal spread blend and probability conversion for a valid home line', function () {
    $outputs = nflBlendProbe([['key' => 'spreads', 'outcomes' => [['name' => 'Home', 'point' => -8]]]]);
    expect($outputs[0])->toBe(6.0)
        ->and($outputs[1])->toEqualWithDelta(1 / (1 + exp(-6 / 7)), 0.0000001)
        ->and($outputs[2])->toBe(44.0);
});

it('ignores malformed quote points instead of manufacturing pickem or zero total', function () {
    $lines = app(NflMarketQuoteLines::class)->extract(['home_team' => 'Home', 'bookmakers' => [
        ['markets' => [
            ['key' => 'spreads', 'outcomes' => [['name' => 'Home', 'point' => 'bad'], ['name' => 'Home', 'point' => INF]]],
            ['key' => 'totals', 'outcomes' => [['point' => 'bad'], ['point' => 0], ['point' => -2]]],
        ]],
        ['markets' => [
            ['key' => 'spreads', 'outcomes' => [['name' => 'Home', 'point' => 0]]],
            ['key' => 'totals', 'outcomes' => [['point' => '44.5']]],
        ]],
    ]]);
    expect($lines)->toBe([0.0, 44.5]);
});

it('tolerates structurally incomplete stored odds without treating them as evidence', function () {
    expect(app(NflMarketQuoteLines::class)->extract(['bookmakers' => [null, ['markets' => 'invalid'], ['markets' => [null]]]]))
        ->toBe([null, null]);
});

it('does not infer a home-side line from an away-only quote', function () {
    expect(nflBlendProbe([['key' => 'spreads', 'outcomes' => [['name' => 'Away', 'point' => 8]]]]))
        ->toBe([4.0, 0.73, 44.0]);
});

it('converts home underdog quotes to negative home margins before blending', function () {
    $outputs = nflBlendProbe([
        ['key' => 'spreads', 'outcomes' => [['name' => 'Home', 'point' => 8]]],
        ['key' => 'totals', 'outcomes' => [['point' => 49]]],
    ]);
    expect($outputs[0])->toBe(-2.0)
        ->and($outputs[1])->toEqualWithDelta(1 / (1 + exp(2 / 7)), 0.0000001)
        ->and($outputs[2])->toBe(46.0);
});

it('does not claim market influence when both blend weights retain the model', function () {
    config(['nfl.predictions.market_blend.enabled' => true,
        'nfl.predictions.market_blend.spread_model_weight' => 1.0,
        'nfl.predictions.market_blend.total_model_weight' => 1.0]);
    $game = new Game(['odds_data' => ['home_team' => 'Home', 'bookmakers' => [['markets' => [
        ['key' => 'spreads', 'outcomes' => [['name' => 'Home', 'point' => -8]]],
        ['key' => 'totals', 'outcomes' => [['point' => 49]]],
    ]]]]]);
    $calculator = app(GeneratePredictionFromHistoricalElo::class);
    (new ReflectionMethod($calculator, 'applyMarketBlend'))->invoke($calculator, $game, 4.0, 0.73, 44.0);
    $metadata = (new ReflectionProperty($calculator, 'lastModelMetadata'))->getValue($calculator);
    expect($metadata['market_blend']['applied'])->toBeFalse()
        ->and($metadata['market_blend']['win_probability_source'])->toBe('retained_model_probability');
});
