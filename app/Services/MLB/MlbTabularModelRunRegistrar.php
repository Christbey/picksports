<?php

namespace App\Services\MLB;

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\ML\ModelArtifactRegistry;
use App\Support\Sports\ExplicitOffsetInstant;
use App\Support\Sports\LegacyTimestampCarrier;
use Illuminate\Support\Str;
use RuntimeException;

class MlbTabularModelRunRegistrar
{
    public function __construct(private readonly MlbTabularModelBundle $bundles, private readonly ModelArtifactRegistry $artifacts) {}

    public function register(string $runDirectory, ?string $datasetPath = null): ModelArtifact
    {
        if ($datasetPath === null || $datasetPath === '') {
            throw new RuntimeException('MLB registration requires its exact training dataset and owned capture proof.');
        }
        $prepared = $this->prepare($runDirectory, $datasetPath);

        return app(MlbTabularSourceFence::class)->run($prepared->registration->admission, fn () => app(MlbArtifactCohortSerializer::class)->run(
            (new ModelRun)->getConnection(), fn () => $this->persistPrepared($prepared),
        ));
    }

    /** No active objects, existing SQL transaction or persistence callback is accepted. */
    public function prepare(string $runDirectory, string $datasetPath): PreparedMlbTabularRun
    {
        if ((new ModelRun)->getConnection()->transactionLevel() !== 0) {
            throw new RuntimeException('MLB registration preparation requires a standalone source-first boundary.');
        }
        app(MlbArtifactCohortSerializer::class)->assertSupported((new ModelRun)->getConnection());
        $context = $this->bundles->prepareValidated($runDirectory, $datasetPath);
        $bundle = $context->bundle;
        try {
            $manifest = $bundle['manifest'];
            $reportedAt = ExplicitOffsetInstant::parse($manifest['generated_at'] ?? null);
            $startedAt = $reportedAt === null ? null : LegacyTimestampCarrier::forInstant($reportedAt);
            if ($startedAt === null) {
                throw new RuntimeException('MLB run generation requires an explicit real instant representable by its legacy timestamp carrier.');
            }
            $evaluation = $bundle['evaluation'];
            $pythonId = (string) $manifest['model_run_id'];
            $runId = Str::isUuid($pythonId) ? $pythonId : (string) Str::uuid();
            if (ModelArtifact::query()->whereKey($manifest['artifact_id'])->exists() || ModelRun::query()->whereKey($runId)->exists()) {
                throw new RuntimeException('This Python MLB tabular run is already registered.');
            }
            $run = new ModelRun([
                'id' => $runId, 'sport' => 'mlb', 'run_type' => 'training', 'model_version' => (string) $manifest['model_version'],
                'feature_version' => (string) $manifest['feature_schema_version'], 'blend_version' => 'mlb-tabular-v1',
                'config_hash' => (string) $manifest['config_hash'], 'code_version' => filled($manifest['code_version'] ?? null) ? substr((string) $manifest['code_version'], 0, 64) : null,
                'parameters' => ['seed' => $manifest['seed'] ?? null, 'source_hash' => $manifest['source_hash'] ?? null,
                    'feature_schema_hash' => $manifest['feature_schema_hash'], 'training_seasons' => $manifest['training_seasons'] ?? [],
                    'chronological_boundaries' => $manifest['chronological_boundaries'] ?? []],
                'status' => 'running', 'started_at' => $startedAt,
                'metadata' => ['python_model_run_id' => $pythonId, 'python_manifest' => $manifest, 'bundle_manifest' => $bundle['bundle']],
            ]);
            $registration = $this->artifacts->prepareMlbRegistration(
                id: (string) $manifest['artifact_id'], trainingRun: $run, marketType: 'multi_market', modelType: 'mlb_tabular_bundle',
                modelVersion: (string) $manifest['model_version'], featureVersion: (string) $manifest['feature_schema_version'],
                datasetHash: (string) $manifest['dataset_hash'], artifactPath: $bundle['path'],
                metrics: ['champion_classifier' => $manifest['champion_classifier'] ?? null, 'final_holdout' => $evaluation['final_holdout'] ?? [],
                    'rolling_weekly' => data_get($evaluation, 'rolling_weekly.summary', []), 'promotion_summary' => $evaluation['promotion_summary'] ?? []],
                datasetPath: $datasetPath, evaluationReportPath: $this->absolutePath($runDirectory).'/evaluation.json', validatedBundle: $context,
            );

            return new PreparedMlbTabularRun($run->getAttributes(), $registration, $manifest, $evaluation);
        } finally {
            $this->bundles->releaseValidated($context);
        }
    }

    /** DB-only. A current matching source fence must already be owned. */
    public function persistPrepared(PreparedMlbTabularRun $prepared): ModelArtifact
    {
        $connection = (new ModelRun)->getConnection();
        MlbTabularSourceFence::assertOwned($connection, $prepared->registration->admission);
        MlbArtifactCohortSerializer::assertOwned($connection);
        $id = $prepared->registration->trainingRunId;
        if (ModelRun::query()->whereKey($id)->lockForUpdate()->exists()) {
            throw new RuntimeException('Prepared MLB target run UUID already exists; no stale instance is reused.');
        }
        // A fresh instance per owned attempt avoids exists=true left over from a rolled-back insert.
        $run = new ModelRun;
        $run->setRawAttributes($prepared->runAttributes);
        $this->artifacts->insertPreparedMlbRun($prepared->registration, $run);

        return $this->artifacts->persistPreparedMlb($prepared->registration, $run);
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? rtrim($path, '/') : rtrim(base_path($path), '/');
    }
}
