<?php

namespace App\Services\Predictions\Basketball;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Exceptions\Predictions\PredictionLifecycleException;
use Closure;

/** Preserve established basketball projections without running generators at read time. */
class EstablishedBasketballModel
{
    /** @param list<string> $configurationKeys */
    public function capture(EventInputSnapshotData $snapshot, CalculationReleaseData $release, string $sport, string $schema, string $expectedSource, array $configurationKeys, Closure $previewModel): EventInputSnapshotData
    {
        $label = strtoupper($sport);
        $source = data_get($release->configuration, 'forecast.source');
        if ($source === null) {
            return $snapshot;
        }
        if ($source !== $expectedSource
            || $release->inputSchemaVersion !== $schema) {
            throw new PredictionLifecycleException($label.' full-model release inputs are incompatible.');
        }
        $frozen = data_get($release->configuration, 'forecast.configuration');
        $expectedKeys = $configurationKeys;
        if (! is_array($frozen) || count($frozen) !== count($expectedKeys)
            || array_diff($expectedKeys, array_keys($frozen)) !== []) {
            throw new PredictionLifecycleException($label.' full-model configuration is unavailable.');
        }
        $original = collect(array_keys($frozen))->mapWithKeys(fn (string $key): array => [$key => config($key)])->all();
        try {
            config($frozen);
            $preview = $previewModel();
        } finally {
            config($original);
        }
        if ($preview === null) {
            throw new PredictionLifecycleException($label.' established model inputs are unavailable.');
        }
        $capturedAt = now()->toImmutable();
        if ($snapshot->cutoffAt === null || $capturedAt->gte($snapshot->cutoffAt)) {
            throw new PredictionLifecycleException($label.' established model capture must precede kickoff.');
        }

        if ($snapshot->latestSourceAvailableAt?->gt($capturedAt)) {
            throw new PredictionLifecycleException($label.' full-model sources cannot be available after capture.');
        }

        return new EventInputSnapshotData(
            schemaVersion: $schema,
            inputs: [...$snapshot->inputs, 'established_model' => [
                'version' => $expectedSource,
                'model_version' => $preview['model_version'] ?? null,
                'feature_version' => $preview['feature_version'] ?? null,
                'blend_version' => $preview['blend_version'] ?? null,
                'outputs' => array_intersect_key($preview, array_flip(['predicted_spread', 'predicted_total', 'win_probability', 'confidence_score'])),
                'evidence' => (array) ($preview['_snapshot'] ?? []),
                'model_metadata' => (array) ($preview['model_metadata'] ?? []),
            ]],
            capturedAt: $capturedAt, cutoffAt: $snapshot->cutoffAt,
            latestSourceAvailableAt: $capturedAt,
            sourceTimestamps: [...$snapshot->sourceTimestamps, 'established_model_capture' => $capturedAt->toIso8601String()],
            pregameSafetyStatus: $snapshot->pregameSafetyStatus,
            metadata: [...$snapshot->metadata, 'established_model_configuration' => 'frozen_release'],
        );
    }

    public function calculate(EventInputSnapshotData $snapshot, CalculationReleaseData $release, string $sport, string $schema, string $expectedSource): PredictionOutput
    {
        $label = strtoupper($sport);
        $source = data_get($release->configuration, 'forecast.source');
        if ($source !== $expectedSource || $release->sport !== $sport
            || $release->phase !== 'pregame' || $release->inputSchemaVersion !== $schema
            || $snapshot->schemaVersion !== $schema
            || data_get($snapshot->inputs, 'established_model.version') !== $source) {
            throw new PredictionLifecycleException($label.' frozen model inputs are incompatible.');
        }
        $context = (array) data_get($snapshot->inputs, 'established_model', []);
        $outputs = (array) ($context['outputs'] ?? []);
        foreach (['predicted_spread', 'predicted_total', 'win_probability'] as $field) {
            if (! is_numeric($outputs[$field] ?? null) || ! is_finite((float) $outputs[$field])) {
                throw new PredictionLifecycleException("{$label} frozen model output {$field} is unavailable.");
            }
        }
        $margin = (float) $outputs['predicted_spread'];
        $total = (float) $outputs['predicted_total'];
        $probability = (float) $outputs['win_probability'];
        if ($total < abs($margin) || $probability < 0 || $probability > 1 || ! is_string($context['model_version'] ?? null) || trim($context['model_version']) === '') {
            throw new PredictionLifecycleException($label.' frozen model projections are invalid.');
        }
        $confidence = is_numeric($outputs['confidence_score'] ?? null) ? (float) $outputs['confidence_score'] : null;
        if ($confidence !== null && (! is_finite($confidence) || $confidence < 0 || $confidence > 100)) {
            $confidence = null;
        }

        return new PredictionOutput(
            markets: [
                new PredictionMarketOutput('moneyline', 'home', probability: $probability, confidenceScore: $confidence),
                new PredictionMarketOutput('moneyline', 'away', probability: 1 - $probability, confidenceScore: $confidence),
                new PredictionMarketOutput('spread', 'home', projectedLine: -$margin, confidenceScore: $confidence),
                new PredictionMarketOutput('total', 'combined', projectedLine: $total, confidenceScore: $confidence),
            ],
            metadata: ['forecast_source' => $source, 'forecast_model_version' => $context['model_version'],
                'home_margin' => $margin, 'model_metadata' => $context['model_metadata'] ?? [],
                'market_conventions' => ['spread' => 'sportsbook_home_line', 'total' => 'combined_points', 'moneyline_probability' => 'decimal_zero_to_one']],
            diagnostics: ['frozen_model_outputs_preserved' => true], generatedAt: $snapshot->capturedAt,
        );
    }
}
