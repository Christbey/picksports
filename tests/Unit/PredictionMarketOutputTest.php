<?php

use App\Application\Predictions\Data\PredictionMarketOutput;

it('rejects non-finite prediction output numbers at construction', function (string $field, float $value) {
    expect(fn () => new PredictionMarketOutput('spread', 'home', ...[$field => $value]))
        ->toThrow(InvalidArgumentException::class, 'finite');
})->with([
    ['projectedLine', NAN], ['projectedLine', INF], ['projectedLine', -INF],
    ['probability', NAN], ['probability', INF], ['probability', -INF],
    ['confidenceScore', NAN], ['confidenceScore', INF], ['confidenceScore', -INF],
]);

it('preserves finite boundary values and unavailable prediction output fields', function () {
    expect((new PredictionMarketOutput('moneyline', 'home', probability: 0, confidenceScore: 0))->toArray())
        ->toMatchArray(['probability' => 0.0, 'confidence_score' => 0.0, 'projected_line' => null])
        ->and((new PredictionMarketOutput('moneyline', 'home', probability: 1, confidenceScore: 100))->toArray())
        ->toMatchArray(['probability' => 1.0, 'confidence_score' => 100.0])
        ->and((new PredictionMarketOutput('spread', 'home', projectedLine: -4.5))->toArray())
        ->toMatchArray(['projected_line' => -4.5, 'probability' => null, 'confidence_score' => null]);
});
