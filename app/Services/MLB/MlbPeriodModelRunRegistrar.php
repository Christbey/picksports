<?php

namespace App\Services\MLB;

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\ML\ShadowArtifactSelector;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MlbPeriodModelRunRegistrar
{
    public function __construct(
        private readonly ModelArtifactRegistry $artifacts,
        private readonly ShadowArtifactSelector $selection,
    ) {}

    public function register(
        string $bundlePath,
        string $manifestPath,
        string $evaluationPath,
        string $datasetPath,
    ): ModelArtifact {
        $manifest = $this->json($manifestPath);
        $evaluation = $this->json($evaluationPath);
        $artifactId = (string) data_get($manifest, 'artifact_id');
        $pythonRunId = (string) data_get($manifest, 'model_run_id');
        if (! Str::isUuid($artifactId) || ! Str::isUuid($pythonRunId)) {
            throw new RuntimeException('MLB period manifest IDs must be UUIDs.');
        }
        if (! hash_equals((string) $manifest['dataset_hash'], hash_file('sha256', $datasetPath))) {
            throw new RuntimeException('MLB period dataset hash does not match its manifest.');
        }
        if (! hash_equals((string) $manifest['artifact_hash'], hash_file('sha256', $bundlePath))) {
            throw new RuntimeException('MLB period artifact hash does not match its manifest.');
        }
        if (ModelArtifact::query()->whereKey($artifactId)->exists()
            || ModelRun::query()->whereKey($pythonRunId)->exists()) {
            throw new RuntimeException('This MLB period model run is already registered.');
        }

        $artifact = DB::transaction(function () use (
            $artifactId,
            $bundlePath,
            $datasetPath,
            $evaluation,
            $evaluationPath,
            $manifest,
            $pythonRunId,
        ): ModelArtifact {
            $run = ModelRun::query()->create([
                'id' => $pythonRunId,
                'sport' => 'mlb',
                'run_type' => 'training',
                'model_version' => (string) $manifest['model_version'],
                'feature_version' => (string) $manifest['feature_schema_version'],
                'blend_version' => 'mlb-period-multiclass-v1',
                'config_hash' => (string) $manifest['config_hash'],
                'code_version' => app(ModelRunRecorder::class)->codeVersion(),
                'parameters' => [
                    'seed' => $manifest['seed'],
                    'training_seasons' => $manifest['training_seasons'],
                    'markets' => $manifest['markets'],
                ],
                'status' => 'running',
                'started_at' => now(),
                'metadata' => [
                    'python_model_run_id' => $pythonRunId,
                    'python_manifest' => $manifest,
                ],
            ]);

            $artifact = $this->artifacts->register(
                id: $artifactId,
                trainingRun: $run,
                marketType: 'multi_market',
                modelType: 'mlb_period_bundle',
                modelVersion: (string) $manifest['model_version'],
                featureVersion: (string) $manifest['feature_schema_version'],
                datasetHash: (string) $manifest['dataset_hash'],
                artifactPath: $bundlePath,
                metrics: [
                    'markets' => $evaluation['markets'] ?? [],
                    'promotion_summary' => $evaluation['promotion_summary'] ?? [],
                ],
                datasetPath: $datasetPath,
                evaluationReportPath: $evaluationPath,
            );
            $artifact->forceFill([
                'promotion_criteria' => [
                    'chronological_windows_required' => true,
                    'live_shadow_required' => true,
                    'positive_quote_roi_required' => true,
                ],
                'promotion_decision' => [
                    ...(array) ($evaluation['promotion_summary'] ?? []),
                    'promoted_markets' => [],
                ],
            ])->save();

            return $artifact->refresh();
        });

        $this->selection->activateChallenger($artifact, [
            'reason' => 'latest_registered_mlb_period_challenger',
        ]);

        return $artifact->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid JSON file: {$path}");
        }

        return $decoded;
    }
}
