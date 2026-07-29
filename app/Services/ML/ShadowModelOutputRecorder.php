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
        $artifactId = $shadow['artifact_id'] ?? null;
        $baseline = $shadow['baseline_output'] ?? null;
        $challenger = $shadow['challenger_output'] ?? null;
        $marketType = (string) ($shadow['market_type'] ?? 'win_probability');
        $context = $shadow;

        if (! is_string($artifactId) || ! is_numeric($baseline) || ! is_numeric($challenger)) {
            $calibration = (array) data_get($snapshot->model_metadata, 'win_probability_calibration', []);
            $artifactId = $calibration['artifact_id'] ?? null;
            $baseline = $calibration['baseline_win_probability'] ?? null;
            $challenger = $calibration['calibrated_win_probability'] ?? null;
            $marketType = 'win_probability';
            $context = $calibration;
        }

        if (! is_string($artifactId) || ! is_numeric($baseline) || ! is_numeric($challenger)) {
            return null;
        }

        $artifact = ModelArtifact::query()->find($artifactId);
        if (! $artifact || ! in_array($artifact->status, ['challenger', 'promotion_eligible', 'promoted'], true)) {
            return null;
        }
        $marketPromoted = $artifact->isPromotedForMarket($marketType);

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

        return ShadowModelOutput::query()->updateOrCreate(
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
                'baseline_output' => (float) $baseline,
                'challenger_output' => (float) $challenger,
                'output_delta' => (float) $challenger - (float) $baseline,
                'status' => $marketPromoted ? 'promoted_shadow' : 'shadow',
                'explanation' => [
                    'artifact_status' => $artifact->status,
                    'market_promoted' => $marketPromoted,
                    'profile' => $context['profile'] ?? null,
                    'baseline_outputs' => $context['baseline_outputs'] ?? null,
                    'challenger_outputs' => $context['challenger_outputs'] ?? null,
                    'public_output_changed' => (bool) ($context['public_output_changed']
                        ?? ((bool) ($context['apply_to_live_output'] ?? false) && $marketPromoted)),
                    'reason' => $context['reason'] ?? ($marketPromoted
                        ? 'promoted_artifact_shadow_audit'
                        : 'challenger_not_promoted'),
                ],
                'generated_at' => $snapshot->generated_at,
            ],
        );
    }
}
