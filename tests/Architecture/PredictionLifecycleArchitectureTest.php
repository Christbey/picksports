<?php

function canonicalSportCalculatorSources(): array
{
    $root = dirname(__DIR__, 2).'/app/Services';
    $paths = array_merge(
        glob($root.'/*/Predictions/*Calculator.php') ?: [],
        glob($root.'/Predictions/Basketball/*Calculator.php') ?: [],
        glob($root.'/Predictions/Football/*Calculator.php') ?: [],
    );

    return collect($paths)
        ->mapWithKeys(fn (string $path): array => [
            str_replace($root.'/', '', $path) => file_get_contents($path),
        ])
        ->all();
}

it('keeps canonical sport calculators pure and independent from legacy persistence', function () {
    $forbidden = [
        'Eloquent or query builder' => '/Illuminate\\\\Database|App\\\\Models|(?:DB|Schema)::|::query\s*\(/',
        'framework side effect' => '/Illuminate\\\\Support\\\\Facades|dispatch\s*\(|event\s*\(|Http::|Cache::|Queue::/',
        'legacy generator' => '/AbstractPredictionGenerator|Legacy.*Prediction|Actions\\\\.*GeneratePrediction/',
    ];
    $violations = [];

    foreach (canonicalSportCalculatorSources() as $file => $source) {
        foreach ($forbidden as $reason => $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $violations[$file][] = $reason;
            }
        }
    }

    expect(canonicalSportCalculatorSources())->not->toBeEmpty()
        ->and($violations)->toBe([], 'Canonical sport calculators must operate only on immutable DTOs and frozen release data.');
});
