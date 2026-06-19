<?php

namespace App\Services\Predictions;

use App\Models\PredictionFeatureSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PredictionFeatureSnapshotRecorder
{
    /**
     * @param  array<string, mixed>  $predictionData
     * @param  array<string, mixed>  $snapshot
     */
    public function record(Model $prediction, Model $game, string $sport, array $predictionData, array $snapshot = []): void
    {
        $modelVersion = (string) ($snapshot['model_version'] ?? $predictionData['model_version'] ?? 'rules-v1');
        $featureVersion = (string) ($snapshot['feature_version'] ?? $predictionData['feature_version'] ?? 'core-v1');
        $blendVersion = (string) ($snapshot['blend_version'] ?? $predictionData['blend_version'] ?? 'baseline-v1');

        $features = $snapshot['features'] ?? $this->extractFeatures($predictionData);
        $outputs = $snapshot['outputs'] ?? $this->extractOutputs($predictionData);
        $marketContext = $snapshot['market_context'] ?? $this->extractMarketContext($predictionData);
        $modelMetadata = $snapshot['model_metadata']
            ?? (is_array($predictionData['model_metadata'] ?? null) ? $predictionData['model_metadata'] : null);

        PredictionFeatureSnapshot::query()->create([
            'sport' => $sport,
            'prediction_table' => $prediction->getTable(),
            'prediction_id' => (int) $prediction->getKey(),
            'game_id' => (int) ($prediction->game_id ?? $game->getKey()),
            'snapshot_run_id' => (string) Str::uuid(),
            'model_version' => $modelVersion,
            'feature_version' => $featureVersion,
            'blend_version' => $blendVersion,
            'features' => $features,
            'outputs' => $outputs,
            'market_context' => $marketContext,
            'model_metadata' => $modelMetadata,
            'feature_hash' => hash('sha256', json_encode([
                'features' => $features,
                'outputs' => $outputs,
                'market_context' => $marketContext,
            ])),
            'generated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $predictionData
     * @return array<string, mixed>
     */
    private function extractFeatures(array $predictionData): array
    {
        $excludedKeys = [
            'game_id',
            'predicted_spread',
            'predicted_total',
            'win_probability',
            'confidence_score',
            'actual_spread',
            'actual_total',
            'spread_error',
            'total_error',
            'winner_correct',
            'graded_at',
            'model_version',
            'feature_version',
            'blend_version',
            'model_metadata',
        ];

        return collect($predictionData)
            ->except($excludedKeys)
            ->filter(fn (mixed $value): bool => $value !== null)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $predictionData
     * @return array<string, mixed>
     */
    private function extractOutputs(array $predictionData): array
    {
        $predictedSpread = $this->nullableFloat($predictionData['predicted_spread'] ?? null);
        $predictedTotal = $this->nullableFloat($predictionData['predicted_total'] ?? null);

        return [
            'baseline_predicted_spread' => $this->nullableFloat($predictionData['baseline_predicted_spread'] ?? $predictedSpread),
            'baseline_predicted_total' => $this->nullableFloat($predictionData['baseline_predicted_total'] ?? $predictedTotal),
            'market_spread' => $this->nullableFloat($predictionData['market_spread'] ?? $predictionData['vegas_spread'] ?? null),
            'market_total' => $this->nullableFloat($predictionData['market_total'] ?? null),
            'blended_predicted_spread' => $this->nullableFloat($predictionData['blended_predicted_spread'] ?? $predictedSpread),
            'blended_predicted_total' => $this->nullableFloat($predictionData['blended_predicted_total'] ?? $predictedTotal),
            'predicted_spread' => $predictedSpread,
            'predicted_total' => $predictedTotal,
            'win_probability' => $this->nullableFloat($predictionData['win_probability'] ?? null),
            'confidence_score' => $this->nullableFloat($predictionData['confidence_score'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $predictionData
     * @return array<string, mixed>|null
     */
    private function extractMarketContext(array $predictionData): ?array
    {
        $marketContext = array_filter([
            'vegas_spread' => $this->nullableFloat($predictionData['vegas_spread'] ?? null),
            'market_spread' => $this->nullableFloat($predictionData['market_spread'] ?? null),
            'market_total' => $this->nullableFloat($predictionData['market_total'] ?? null),
        ], fn (mixed $value): bool => $value !== null);

        return $marketContext === [] ? null : $marketContext;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
