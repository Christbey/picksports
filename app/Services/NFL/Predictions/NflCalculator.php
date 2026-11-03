<?php

namespace App\Services\NFL\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Services\Predictions\Football\CanonicalFootballCalculator;

class NflCalculator extends CanonicalFootballCalculator
{
    public function calculate(EventInputSnapshotData $snapshot, CalculationReleaseData $release): PredictionOutput
    {
        $source = data_get($release->configuration, 'forecast.source');
        if ($source === null) {
            return parent::calculate($snapshot, $release);
        }
        if ($source !== NflFullModelReleaseDefinition::FORECAST_SOURCE
            || $release->sport !== 'nfl' || $release->phase !== 'pregame'
            || $snapshot->schemaVersion !== NflCalculationReleaseDefinition::INPUT_SCHEMA_VERSION
            || data_get($snapshot->inputs, 'recommendation_context.version') !== NflRecommendationInputBuilder::VERSION) {
            throw new PredictionLifecycleException('NFL full-model release requires matching frozen NFL model inputs.');
        }
        $context = (array) data_get($snapshot->inputs, 'recommendation_context', []);
        $outputs = (array) ($context['outputs'] ?? []);
        foreach (['predicted_spread', 'predicted_total', 'win_probability'] as $field) {
            if (! is_numeric($outputs[$field] ?? null) || ! is_finite((float) $outputs[$field])) {
                throw new PredictionLifecycleException("NFL frozen model output {$field} is unavailable.");
            }
        }
        $margin = (float) $outputs['predicted_spread'];
        $total = (float) $outputs['predicted_total'];
        $probability = (float) $outputs['win_probability'];
        if ($total < abs($margin) || $probability < 0 || $probability > 1 || ! is_string($context['model_version'] ?? null)
            || trim($context['model_version']) === '') {
            throw new PredictionLifecycleException('NFL frozen model projections or identity are invalid.');
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
            metadata: ['forecast_source' => $source, 'forecast_model_version' => $context['model_version'], 'home_margin' => $margin,
                'market_conventions' => ['spread' => 'sportsbook_home_line', 'total' => 'combined_points', 'moneyline_probability' => 'decimal_zero_to_one'],
                'custom_epa_quarantined' => data_get($context, 'model_metadata.true_epa.quarantined') === true],
            diagnostics: ['frozen_model_outputs_preserved' => true, 'projected_total' => $total],
            generatedAt: $snapshot->capturedAt,
        );
    }

    protected function expectedSport(): string
    {
        return 'nfl';
    }

    protected function expectedInputSchemaVersion(): string
    {
        return NflCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }
}
