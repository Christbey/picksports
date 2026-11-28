<?php

namespace Tests\Support;

use App\Models\ModelArtifact;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Synthetic retained database facts for feedback/settlement tests only.
 * Never a producer, capture marker, execution receipt, or scientific approval.
 */
final class HistoricalMlbShadowFeedbackFixture
{
    public static function seed(PredictionFeatureSnapshot $snapshot): void
    {
        if ($snapshot->sport !== 'mlb') {
            throw new \LogicException('This retained-history fixture is MLB-only.');
        }
        $shadow = (array) data_get($snapshot->model_metadata, 'shadow_inference', []);
        $contexts = is_array($shadow['cohort'] ?? null) ? $shadow['cohort'] : [$shadow];
        $run = null;
        foreach ($contexts as $context) {
            $artifact = ModelArtifact::findOrFail($context['artifact_id']);
            $observations = [];
            if (is_array($context['baseline_outputs'] ?? null) && is_array($context['challenger_outputs'] ?? null)) {
                foreach (['win_probability' => 'win_probability', 'spread' => 'predicted_spread', 'total' => 'predicted_total'] as $market => $key) {
                    if (is_numeric($context['baseline_outputs'][$key] ?? null) && is_numeric($context['challenger_outputs'][$key] ?? null)) {
                        $observations[$market] = [(float) $context['baseline_outputs'][$key], (float) $context['challenger_outputs'][$key]];
                    }
                }
            } elseif (is_numeric($context['baseline_output'] ?? null) && is_numeric($context['challenger_output'] ?? null)) {
                $observations[$context['market_type'] ?? 'win_probability'] = [(float) $context['baseline_output'], (float) $context['challenger_output']];
            }
            foreach ($observations as $market => [$baseline, $challenger]) {
                $identity = ['model_artifact_id' => $artifact->id, 'prediction_feature_snapshot_id' => $snapshot->id, 'market_type' => $market];
                if (DB::table('shadow_model_outputs')->where($identity)->exists()) {
                    continue;
                }
                $run ??= app(ModelRunRecorder::class)->create('mlb', 'historical_shadow_import_fixture',
                    'retained-history-only', 'retained-history-only', 'fixture-only',
                    metadata: ['fixture_scope' => 'synthetic_retained_history'], status: 'completed', completedAt: $snapshot->generated_at);
                // These are the historical fixture's declared facts; no artifact status is changed.
                $promoted = $artifact->isPromotedForMarket($market) && ($context['market_promotion'][$market] ?? true)
                    && $artifact->promoted_at !== null && $artifact->promoted_at->lessThanOrEqualTo($snapshot->generated_at);
                DB::table('shadow_model_outputs')->insert([...$identity, 'inference_run_id' => $run->id,
                    'sport' => 'mlb', 'game_table' => 'mlb_games', 'game_id' => $snapshot->game_id,
                    'prediction_table' => $snapshot->prediction_table, 'prediction_id' => $snapshot->prediction_id,
                    'baseline_output' => $baseline, 'challenger_output' => $challenger, 'output_delta' => $challenger - $baseline,
                    'status' => $promoted ? 'promoted_shadow' : 'shadow',
                    'explanation' => json_encode(['fixture_scope' => 'synthetic_retained_history',
                        'artifact_id' => $artifact->id, 'artifact_hash' => $artifact->artifact_hash,
                        'artifact_status' => $artifact->status, 'training_run_id' => $artifact->training_run_id,
                        'model_run_id' => $context['model_run_id'] ?? $run->id,
                        'config_hash' => $artifact->trainingRun?->config_hash, 'dataset_hash' => $artifact->dataset_hash,
                        'feature_hash' => $context['feature_hash'] ?? $snapshot->feature_hash,
                        'market_promoted' => (bool) $promoted, 'market_promotion' => $context['market_promotion'] ?? null,
                        'multi_market_contract' => isset($context['baseline_outputs'], $context['challenger_outputs']) && is_array($context['market_promotion'] ?? null),
                        'profile' => $context['profile'] ?? null, 'baseline_outputs' => $context['baseline_outputs'] ?? null,
                        'challenger_outputs' => $context['challenger_outputs'] ?? null, 'public_output_changed' => false,
                        'reason' => 'synthetic_retained_history'], JSON_THROW_ON_ERROR),
                    'generated_at' => $snapshot->generated_at, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
