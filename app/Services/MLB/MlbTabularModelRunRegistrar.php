<?php

namespace App\Services\MLB;

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\ML\ModelArtifactRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class MlbTabularModelRunRegistrar
{
    public function __construct(
        private readonly MlbTabularModelBundle $bundles,
        private readonly ModelArtifactRegistry $artifacts,
    ) {}

    public function register(string $runDirectory, ?string $datasetPath = null): ModelArtifact
    {
        $prepared = $this->bundles->create($runDirectory, $datasetPath);
        $manifest = $prepared['manifest'];
        $evaluation = $prepared['evaluation'];
        $artifactId = (string) $manifest['artifact_id'];
        $pythonModelRunId = (string) $manifest['model_run_id'];
        $modelRunId = Str::isUuid($pythonModelRunId)
            ? $pythonModelRunId
            : (string) Str::uuid();

        try {
            if (ModelArtifact::query()->whereKey($artifactId)->exists()
                || ModelRun::query()->whereKey($modelRunId)->exists()) {
                throw new RuntimeException('This Python MLB tabular run is already registered.');
            }

            return DB::transaction(function () use (
                $artifactId,
                $datasetPath,
                $evaluation,
                $manifest,
                $modelRunId,
                $prepared,
                $pythonModelRunId,
                $runDirectory,
            ): ModelArtifact {
                $trainingRun = ModelRun::query()->create([
                    'id' => $modelRunId,
                    'sport' => 'mlb',
                    'run_type' => 'training',
                    'model_version' => (string) $manifest['model_version'],
                    'feature_version' => (string) $manifest['feature_schema_version'],
                    'blend_version' => 'mlb-tabular-v1',
                    'config_hash' => (string) $manifest['config_hash'],
                    'code_version' => filled($manifest['code_version'] ?? null)
                        ? substr((string) $manifest['code_version'], 0, 64)
                        : null,
                    'parameters' => [
                        'seed' => $manifest['seed'] ?? null,
                        'source_hash' => $manifest['source_hash'] ?? null,
                        'feature_schema_hash' => $manifest['feature_schema_hash'],
                        'training_seasons' => $manifest['training_seasons'] ?? [],
                        'chronological_boundaries' => $manifest['chronological_boundaries'] ?? [],
                    ],
                    'status' => 'running',
                    'started_at' => CarbonImmutable::parse((string) ($manifest['generated_at'] ?? 'now')),
                    'metadata' => [
                        'python_model_run_id' => $pythonModelRunId,
                        'python_manifest' => $manifest,
                        'bundle_manifest' => $prepared['bundle'],
                    ],
                ]);

                $artifact = $this->artifacts->register(
                    id: $artifactId,
                    trainingRun: $trainingRun,
                    marketType: 'multi_market',
                    modelType: 'mlb_tabular_bundle',
                    modelVersion: (string) $manifest['model_version'],
                    featureVersion: (string) $manifest['feature_schema_version'],
                    datasetHash: (string) $manifest['dataset_hash'],
                    artifactPath: $prepared['path'],
                    metrics: [
                        'champion_classifier' => $manifest['champion_classifier'] ?? null,
                        'final_holdout' => $evaluation['final_holdout'] ?? [],
                        'rolling_weekly' => data_get($evaluation, 'rolling_weekly.summary', []),
                        'promotion_summary' => $evaluation['promotion_summary'] ?? [],
                    ],
                    datasetPath: $datasetPath,
                    evaluationReportPath: $this->absolutePath($runDirectory).'/evaluation.json',
                );

                $metadata = (array) $trainingRun->refresh()->metadata;
                unset($metadata['inference_alias_path']);
                $trainingRun->forceFill(['metadata' => $metadata])->save();

                return $artifact->refresh();
            });
        } finally {
            File::deleteDirectory($prepared['temporary_directory']);
        }
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? rtrim($path, '/') : rtrim(base_path($path), '/');
    }
}
