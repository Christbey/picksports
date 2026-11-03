<?php

namespace App\Services\NBA\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Services\Predictions\Basketball\CanonicalBasketballCalculator;

class NbaCalculator extends CanonicalBasketballCalculator
{
    public function calculate(EventInputSnapshotData $snapshot, CalculationReleaseData $release): PredictionOutput
    {
        $source = data_get($release->configuration, 'forecast.source');
        if ($source === null) {
            return parent::calculate($snapshot, $release);
        }
        if ($source !== NbaFullModelReleaseDefinition::FORECAST_SOURCE || $release->sport !== 'nba'
            || $release->phase !== 'pregame' || $release->inputSchemaVersion !== NbaFullModelReleaseDefinition::INPUT_SCHEMA_VERSION
            || $snapshot->schemaVersion !== NbaFullModelReleaseDefinition::INPUT_SCHEMA_VERSION
            || data_get($snapshot->inputs, 'established_model.version') !== $source) {
            throw new PredictionLifecycleException('NBA frozen model inputs are incompatible.');
        }
        $context = (array) data_get($snapshot->inputs, 'established_model', []);
        $outputs = (array) ($context['outputs'] ?? []);
        foreach (['predicted_spread', 'predicted_total', 'win_probability'] as $field) {
            if (! is_numeric($outputs[$field] ?? null) || ! is_finite((float) $outputs[$field])) {
                throw new PredictionLifecycleException("NBA frozen model output {$field} is unavailable.");
            }
        }
        $margin = (float) $outputs['predicted_spread'];
        $total = (float) $outputs['predicted_total'];
        $probability = (float) $outputs['win_probability'];
        if ($total < abs($margin) || $probability < 0 || $probability > 1 || ! is_string($context['model_version'] ?? null) || trim($context['model_version']) === '') {
            throw new PredictionLifecycleException('NBA frozen model projections are invalid.');
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

    protected function expectedSport(): string
    {
        return 'nba';
    }

    protected function expectedInputSchemaVersion(): string
    {
        return NbaCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }
}
