<?php

namespace App\Services\NFL;

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\ModelArtifact;
use App\Models\NFL\Game;
use App\Services\ML\ModelArtifactRegistry;
use Illuminate\Support\Facades\Log;

class NflFullHistoricalShadowInferenceService
{
    public function __construct(
        private readonly ModelArtifactRegistry $artifacts,
        private readonly NflPredictionProfileConfigurator $profiles,
        private readonly NflTabularModelInferenceService $tabularInference,
    ) {}

    /**
     * @param  array<string, float|int|null>  $baselineOutputs
     * @param  array<string, float|int|null>  $tabularFeatures
     * @return array<string, mixed>|null
     */
    public function evaluate(Game $game, array $baselineOutputs, array $tabularFeatures = []): ?array
    {
        if ((bool) config('nfl_ml.shadow.enabled', false)) {
            $tabular = $this->evaluateTabular($baselineOutputs, $tabularFeatures);
            if ($tabular !== null) {
                return $tabular;
            }
        }

        $config = (array) config('nfl.predictions.full_historical_shadow', []);
        $artifactPath = (string) ($config['artifact_path'] ?? '');

        if (! (bool) ($config['enabled'] ?? true) || $artifactPath === '') {
            return null;
        }

        $artifact = $this->artifacts->forPath($artifactPath);
        if (! $artifact
            || $artifact->model_type !== 'nfl_full_historical_profile'
            || ! in_array($artifact->status, ['challenger', 'promotion_eligible', 'promoted'], true)) {
            return null;
        }

        try {
            $this->artifacts->materializeArtifact($artifact);
        } catch (\RuntimeException) {
            return null;
        }

        $profile = (string) ($config['profile'] ?? 'full-historical');

        try {
            $preview = $this->profiles->withProfile(
                $profile,
                fn (): ?array => app(GeneratePredictionFromHistoricalElo::class)->preview($game),
            );
        } catch (\Throwable $exception) {
            Log::warning('NFL full-historical shadow inference failed.', [
                'game_id' => $game->getKey(),
                'artifact_id' => $artifact->id,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        $challengerOutputs = (array) ($preview['outputs'] ?? []);
        $baselineProbability = $baselineOutputs['win_probability'] ?? null;
        $challengerProbability = $challengerOutputs['win_probability'] ?? null;

        if (! is_numeric($baselineProbability) || ! is_numeric($challengerProbability)) {
            return null;
        }

        return [
            'active' => true,
            'market_type' => 'win_probability',
            'artifact_id' => $artifact->id,
            'artifact_hash' => $artifact->artifact_hash,
            'training_run_id' => $artifact->training_run_id,
            'config_hash' => $artifact->trainingRun?->config_hash,
            'artifact_status' => $artifact->status,
            'model_type' => $artifact->model_type,
            'model_version' => $artifact->model_version,
            'feature_version' => $artifact->feature_version,
            'profile' => $profile,
            'baseline_output' => round((float) $baselineProbability, 6),
            'challenger_output' => round((float) $challengerProbability, 6),
            'output_delta' => round((float) $challengerProbability - (float) $baselineProbability, 6),
            'baseline_outputs' => $baselineOutputs,
            'challenger_outputs' => $challengerOutputs,
            'challenger_model_metadata' => $preview['model_metadata'] ?? null,
            'apply_to_live_output' => false,
            'public_output_changed' => false,
            'active_source' => 'baseline',
            'reason' => $artifact->status === 'promoted'
                ? 'promoted_artifact_tracking_shadow'
                : 'challenger_not_promoted',
        ];
    }

    /**
     * @param  array<string,float|int|null>  $baselineOutputs
     * @param  array<string,float|int|null>  $features
     * @return array<string,mixed>|null
     */
    private function evaluateTabular(array $baselineOutputs, array $features): ?array
    {
        $baselineProbability = $baselineOutputs['win_probability'] ?? null;
        if (! is_numeric($baselineProbability) || $features === []) {
            return null;
        }

        $artifactId = trim((string) config('nfl_ml.shadow.artifact_id', ''));
        $artifact = ModelArtifact::query()
            ->with('trainingRun')
            ->where('sport', 'nfl')
            ->where('model_type', 'nfl_tabular_bundle')
            ->whereIn('status', ['challenger', 'promotion_eligible', 'promoted'])
            ->when($artifactId !== '', fn ($query) => $query->whereKey($artifactId))
            ->latest('promoted_at')
            ->latest('created_at')
            ->get()
            ->first(fn (ModelArtifact $candidate): bool => $candidate->status !== 'promoted'
                || $candidate->isPromotedForMarket('win_probability'));

        if (! $artifact) {
            return null;
        }

        try {
            $output = $this->tabularInference->predict($artifact, $features);
        } catch (\Throwable $exception) {
            Log::warning('NFL tabular shadow inference failed.', [
                'artifact_id' => $artifact->id,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        $winProbabilityPromoted = $artifact->isPromotedForMarket('win_probability');

        return [
            'active' => true,
            'market_type' => 'win_probability',
            'artifact_id' => $artifact->id,
            'artifact_hash' => $artifact->artifact_hash,
            'training_run_id' => $artifact->training_run_id,
            'model_run_id' => $output['model_run_id'],
            'config_hash' => $artifact->trainingRun?->config_hash,
            'dataset_hash' => $output['dataset_hash'],
            'feature_hash' => $output['feature_hash'],
            'artifact_status' => $artifact->status,
            'model_type' => $artifact->model_type,
            'model_version' => $artifact->model_version,
            'feature_version' => $artifact->feature_version,
            'profile' => 'python-tabular',
            'baseline_output' => round((float) $baselineProbability, 6),
            'challenger_output' => $output['home_win_probability'],
            'output_delta' => round($output['home_win_probability'] - (float) $baselineProbability, 6),
            'baseline_outputs' => $baselineOutputs,
            'challenger_outputs' => [
                'win_probability' => $output['home_win_probability'],
                'predicted_spread' => $output['expected_home_margin'],
                'predicted_total' => $output['expected_total'],
                'home_cover_probability' => $output['home_cover_probability'],
                'over_probability' => $output['over_probability'],
                'uncertainty' => $output['uncertainty'],
            ],
            'market_promotion' => [
                'win_probability' => $winProbabilityPromoted,
                'spread' => $artifact->isPromotedForMarket('spread'),
                'total' => $artifact->isPromotedForMarket('total'),
            ],
            'apply_to_live_output' => false,
            'public_output_changed' => false,
            'active_source' => 'baseline',
            'reason' => $winProbabilityPromoted
                ? 'promoted_tabular_model_tracking_shadow'
                : 'tabular_win_probability_not_promoted',
        ];
    }
}
