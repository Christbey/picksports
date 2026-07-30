<?php

namespace App\Services\ML;

use App\Models\ModelArtifact;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Services\Predictions\ModelRunRecorder;

class ShadowModelOutputRecorder
{
    public function record(PredictionFeatureSnapshot $snapshot): ?ShadowModelOutput
    {
        $shadow = (array) data_get($snapshot->model_metadata, 'shadow_inference', []);
        $contexts = $this->shadowContexts($shadow);

        if ($contexts === []) {
            $calibration = (array) data_get($snapshot->model_metadata, 'win_probability_calibration', []);
            if (is_string($calibration['artifact_id'] ?? null)) {
                $contexts = [[
                    ...$calibration,
                    'baseline_output' => $calibration['baseline_win_probability'] ?? null,
                    'challenger_output' => $calibration['calibrated_win_probability'] ?? null,
                    'market_type' => 'win_probability',
                ]];
            }
        }

        $firstOutput = null;
        foreach ($contexts as $context) {
            $output = $this->recordContext($snapshot, $context);
            $firstOutput ??= $output;
        }

        return $firstOutput;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordContext(
        PredictionFeatureSnapshot $snapshot,
        array $context,
    ): ?ShadowModelOutput {
        $artifactId = $context['artifact_id'] ?? null;
        $observations = $this->structuredObservations($context);
        if (! is_string($artifactId) || $observations === []) {
            return null;
        }

        $artifact = ModelArtifact::query()->find($artifactId);
        if (! $artifact || ! in_array($artifact->status, ['challenger', 'promotion_eligible', 'promoted'], true)) {
            return null;
        }

        $inferenceRun = app(ModelRunRecorder::class)->forPrediction(
            sport: $snapshot->sport,
            modelVersion: $artifact->model_version,
            featureVersion: $artifact->feature_version,
            blendVersion: 'shadow-inference-v1',
            metadata: [
                'run_type' => 'shadow_inference',
                'parameters' => [
                    'model_artifact_id' => $artifact->id,
                    'artifact_hash' => $artifact->artifact_hash,
                ],
            ],
        );

        $firstOutput = null;
        foreach ($observations as $marketType => $outputs) {
            $marketPromoted = $this->marketPromotedAtInference(
                $artifact,
                $context,
                $marketType,
                $snapshot->generated_at,
            );
            $output = ShadowModelOutput::query()->firstOrCreate(
                [
                    'model_artifact_id' => $artifact->id,
                    'prediction_feature_snapshot_id' => $snapshot->id,
                    'market_type' => $marketType,
                ],
                [
                    'inference_run_id' => $inferenceRun->id,
                    'sport' => $snapshot->sport,
                    'game_table' => $snapshot->sport.'_games',
                    'game_id' => $snapshot->game_id,
                    'prediction_table' => $snapshot->prediction_table,
                    'prediction_id' => $snapshot->prediction_id,
                    'baseline_output' => $outputs['baseline'],
                    'challenger_output' => $outputs['challenger'],
                    'output_delta' => $outputs['challenger'] - $outputs['baseline'],
                    'status' => $marketPromoted ? 'promoted_shadow' : 'shadow',
                    'explanation' => [
                        'artifact_id' => $artifact->id,
                        'artifact_hash' => $context['artifact_hash'] ?? $artifact->artifact_hash,
                        'artifact_status' => $artifact->status,
                        'training_run_id' => $context['training_run_id'] ?? $artifact->training_run_id,
                        'model_run_id' => $context['model_run_id'] ?? $inferenceRun->id,
                        'config_hash' => $context['config_hash'] ?? $artifact->trainingRun?->config_hash,
                        'dataset_hash' => $context['dataset_hash'] ?? $artifact->dataset_hash,
                        'feature_hash' => $context['feature_hash'] ?? $snapshot->feature_hash,
                        'market_promoted' => $marketPromoted,
                        'market_promotion' => $context['market_promotion'] ?? null,
                        'multi_market_contract' => isset($context['baseline_outputs'])
                            && isset($context['challenger_outputs'])
                            && is_array($context['market_promotion'] ?? null),
                        'profile' => $context['profile'] ?? null,
                        'baseline_outputs' => $context['baseline_outputs'] ?? null,
                        'challenger_outputs' => $context['challenger_outputs'] ?? null,
                        'public_output_changed' => false,
                        'reason' => $context['reason'] ?? ($marketPromoted
                            ? 'promoted_artifact_shadow_audit'
                            : 'challenger_not_promoted'),
                    ],
                    'generated_at' => $snapshot->generated_at,
                ],
            );
            $firstOutput ??= $output;
        }

        return $firstOutput;
    }

    /**
     * @param  array<string, mixed>  $shadow
     * @return list<array<string, mixed>>
     */
    private function shadowContexts(array $shadow): array
    {
        $cohort = $shadow['cohort'] ?? null;
        if (is_array($cohort)) {
            return array_values(array_filter(
                $cohort,
                fn (mixed $context): bool => is_array($context)
                    && is_string($context['artifact_id'] ?? null),
            ));
        }

        return is_string($shadow['artifact_id'] ?? null) ? [$shadow] : [];
    }

    /**
     * @param  array<string, mixed>  $shadow
     * @return array<string, array{baseline: float, challenger: float}>
     */
    private function structuredObservations(array $shadow): array
    {
        $baselineOutputs = $shadow['baseline_outputs'] ?? null;
        $challengerOutputs = $shadow['challenger_outputs'] ?? null;
        if (! is_array($baselineOutputs)
            || ! is_array($challengerOutputs)
            || ! is_array($shadow['market_promotion'] ?? null)) {
            return $this->legacyObservations(
                $shadow['baseline_output'] ?? null,
                $shadow['challenger_output'] ?? null,
                (string) ($shadow['market_type'] ?? 'win_probability'),
            );
        }

        $observations = [];
        foreach ([
            'win_probability' => 'win_probability',
            'spread' => 'predicted_spread',
            'total' => 'predicted_total',
        ] as $marketType => $outputKey) {
            $baseline = $baselineOutputs[$outputKey]
                ?? ($marketType === 'win_probability' ? ($shadow['baseline_output'] ?? null) : null);
            $challenger = $challengerOutputs[$outputKey]
                ?? ($marketType === 'win_probability' ? ($shadow['challenger_output'] ?? null) : null);
            if (! is_numeric($baseline) || ! is_numeric($challenger)) {
                continue;
            }

            $observations[$marketType] = [
                'baseline' => (float) $baseline,
                'challenger' => (float) $challenger,
            ];
        }

        return $observations !== []
            ? $observations
            : $this->legacyObservations(
                $shadow['baseline_output'] ?? null,
                $shadow['challenger_output'] ?? null,
                (string) ($shadow['market_type'] ?? 'win_probability'),
            );
    }

    /**
     * @return array<string, array{baseline: float, challenger: float}>
     */
    private function legacyObservations(mixed $baseline, mixed $challenger, string $marketType): array
    {
        if (! is_numeric($baseline) || ! is_numeric($challenger)) {
            return [];
        }

        $marketType = match (ModelArtifact::normalizeMarketType($marketType)) {
            'run_line' => 'spread',
            default => ModelArtifact::normalizeMarketType($marketType),
        };

        return [
            $marketType => [
                'baseline' => (float) $baseline,
                'challenger' => (float) $challenger,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function marketPromotedAtInference(
        ModelArtifact $artifact,
        array $context,
        string $marketType,
        mixed $generatedAt,
    ): bool {
        $recordedPromotion = data_get($context, "market_promotion.{$marketType}");

        return $artifact->isPromotedForMarket($marketType)
            && ($recordedPromotion === null || (bool) $recordedPromotion)
            && $artifact->promoted_at !== null
            && $generatedAt !== null
            && $artifact->promoted_at->lessThanOrEqualTo($generatedAt);
    }
}
