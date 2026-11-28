<?php

namespace App\Services\ML;

use App\Exceptions\ExistingArtifactOwnershipRefused;
use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\MLB\MlbArtifactCohortSerializer;
use App\Services\MLB\MlbArtifactCohortState;
use App\Services\MLB\MlbExistingArtifactStorage;
use App\Services\MLB\MlbModelRunAdmissionGuard;
use App\Services\MLB\MlbPeriodModelInferenceService;
use App\Services\MLB\MlbTabularDatasetAdmission;
use App\Services\MLB\MlbTabularDatasetProjector;
use App\Services\MLB\MlbTabularFiles;
use App\Services\MLB\MlbTabularJsonBudget;
use App\Services\MLB\MlbTabularSourceFence;
use App\Services\MLB\ValidatedMlbTabularBundle;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Support\Json\JsonAllocationBudget;
use App\Support\MLB\MlbGameStart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ModelArtifactRegistry
{
    private ?\WeakMap $preparedMlb = null;

    // Full PHP metadata decode/merge budget; unlike the scalar-only 64MiB SQL envelope.
    private const DATASET_METADATA_BYTES = 2097152;

    private const DATASET_METADATA_TOKENS = 20000;

    public function __construct(
        private readonly MlArtifactStorage $storage,
    ) {}

    public function newId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, array{bytes: int, sha256: string}>|null  $storageExpectations
     */
    public function register(
        string $id,
        ModelRun $trainingRun,
        string $marketType,
        string $modelType,
        string $modelVersion,
        string $featureVersion,
        string $datasetHash,
        string $artifactPath,
        array $metrics,
        ?string $datasetPath = null,
        ?string $evaluationReportPath = null,
        ?array $storageExpectations = null,
    ): ModelArtifact {
        if ($modelType === 'mlb_tabular_bundle' && strtolower(trim((string) $trainingRun->sport)) === 'mlb') {
            throw new \RuntimeException('MLB tabular artifacts require the typed staged registrar and exact dataset evidence; direct generic registration is unsupported.');
        }
        if ($storageExpectations !== null) {
            if (count($storageExpectations) > 3) {
                throw new \RuntimeException('Bounded registration accepts at most three object descriptors.');
            }
            $expectedKinds = ['artifact'];
            if ($datasetPath !== null) {
                $expectedKinds[] = 'dataset';
            }
            if ($evaluationReportPath !== null) {
                $expectedKinds[] = 'report';
            }
            $actualKinds = array_keys($storageExpectations);
            sort($expectedKinds);
            sort($actualKinds);
            if ($actualKinds !== $expectedKinds) {
                throw new \RuntimeException('Bounded artifact registration requires exact expectations for every supplied path.');
            }
            foreach ($storageExpectations as $expected) {
                if (! is_array($expected) || count($expected) !== 2 || ! is_int($expected['bytes'] ?? null) || $expected['bytes'] < 0 || $expected['bytes'] > 1073741824
                    || ! is_string($expected['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $expected['sha256']) !== 1) {
                    throw new \RuntimeException('Bounded registration descriptor must contain exact bytes and SHA-256.');
                }
            }
            if ($datasetPath !== null && ! hash_equals($datasetHash, $storageExpectations['dataset']['sha256'])) {
                throw new \RuntimeException('Bounded dataset descriptor differs from its registered lineage.');
            }
        } elseif ($datasetPath !== null) {
            $this->assertSourceHash($datasetPath, $datasetHash, 'dataset');
        }

        $storedArtifact = $this->storage->storeArtifact($trainingRun, $id, $artifactPath, expectedBytes: $storageExpectations['artifact']['bytes'] ?? null, expectedSha256: $storageExpectations['artifact']['sha256'] ?? null);
        $storedDataset = $datasetPath !== null
            ? $this->storage->storeDataset($trainingRun, $id, $datasetPath, expectedBytes: $storageExpectations['dataset']['bytes'] ?? null, expectedSha256: $storageExpectations['dataset']['sha256'] ?? null)
            : null;
        $storedReport = $evaluationReportPath !== null
            ? $this->storage->storeReport($trainingRun, $id, $evaluationReportPath, expectedBytes: $storageExpectations['report']['bytes'] ?? null, expectedSha256: $storageExpectations['report']['sha256'] ?? null)
            : null;

        $artifact = ModelArtifact::query()->create([
            'id' => $id,
            'training_run_id' => $trainingRun->id,
            'sport' => $trainingRun->sport,
            'market_type' => $marketType,
            'model_type' => $modelType,
            'model_version' => $modelVersion,
            'feature_version' => $featureVersion,
            'dataset_hash' => $datasetHash,
            ...$this->datasetAttributes($storedDataset),
            ...$this->artifactAttributes($storedArtifact),
            'status' => 'challenger',
            'metrics' => $metrics,
            ...$this->reportAttributes($storedReport),
        ]);

        $trainingRun->forceFill([
            'artifact_path' => $storedArtifact->localPath,
            'artifact_hash' => $storedArtifact->sha256,
            'status' => 'completed',
            'completed_at' => now(),
            'metadata' => [
                ...(array) $trainingRun->metadata,
                'model_artifact_id' => $artifact->id,
                'inference_alias_path' => $this->absolutePath($artifactPath),
                'artifact_storage' => $this->storageMetadata($storedArtifact),
                'dataset_storage' => $storedDataset
                    ? $this->storageMetadata($storedDataset)
                    : null,
                'evaluation_report_storage' => $storedReport
                    ? $this->storageMetadata($storedReport)
                    : null,
            ],
        ])->save();

        return $artifact;
    }

    /** MLB-only staged entry. No row is created and no object becomes active here. */
    public function prepareMlbRegistration(
        string $id,
        ModelRun $trainingRun,
        string $marketType,
        string $modelType,
        string $modelVersion,
        string $featureVersion,
        string $datasetHash,
        string $artifactPath,
        array $metrics,
        string $datasetPath,
        ?string $evaluationReportPath = null,
        ?ValidatedMlbTabularBundle $validatedBundle = null,
    ): MlPreparedArtifactRegistration {
        if ($validatedBundle === null) {
            throw new \RuntimeException('MLB staging requires an opaque inspected bundle context.');
        }
        $validatedBundle->assertOwned();
        app(MlbArtifactCohortSerializer::class)->assertSupported($trainingRun->getConnection());
        $rawMetadata = $trainingRun->getAttributes()['metadata'] ?? null;
        if (is_string($rawMetadata)) {
            MlbTabularJsonBudget::decode($rawMetadata, 2097152, 100000);
        }
        $manifest = $validatedBundle->bundle['manifest'];
        if ($id !== $manifest['artifact_id'] || $datasetHash !== $manifest['dataset_hash']
            || $modelVersion !== $manifest['model_version'] || $featureVersion !== $manifest['feature_schema_version']
            || $trainingRun->config_hash !== $manifest['config_hash'] || $trainingRun->model_version !== $modelVersion
            || $trainingRun->feature_version !== $featureVersion
            || data_get($trainingRun->metadata, 'python_model_run_id') !== $manifest['model_run_id']
            || realpath($artifactPath) === false || realpath($artifactPath) !== realpath($validatedBundle->bundle['path'])
            || $evaluationReportPath === null || realpath($evaluationReportPath) !== realpath($validatedBundle->bundle['report_source_path'])) {
            throw new \RuntimeException('MLB staging identity does not match its inspected bundle context.');
        }
        $connection = $trainingRun->getConnection();
        if ($trainingRun->sport !== 'mlb' || $modelType !== 'mlb_tabular_bundle' || ! Str::isUuid($id)
            || ! Str::isUuid((string) $trainingRun->getKey()) || $connection->transactionLevel() !== 0
            || (new ModelArtifact)->getConnection()->getName() !== $connection->getName()) {
            throw new \RuntimeException('MLB artifact preparation requires owned UUIDs, coherent connection and no inherited SQL transaction.');
        }
        $proof = app(MlbTabularDatasetAdmission::class)->inspect($this->absolutePath($datasetPath));
        if (! hash_equals($proof['dataset_sha256'], $datasetHash)
            || app(MlbTabularDatasetProjector::class)->stableHash($proof) !== app(MlbTabularDatasetProjector::class)->stableHash($validatedBundle->admission)) {
            throw new \RuntimeException('MLB artifact dataset hash does not match its admitted source evidence.');
        }
        if ($validatedBundle->bytes > 268435456) {
            throw new \RuntimeException('MLB prepared archive exceeds the bounded storage limit.');
        }
        $reportEntry = $validatedBundle->bundle['bundle']['files']['evaluation.json'];
        $storedArtifact = $this->storage->storeArtifact($trainingRun, $id, $artifactPath,
            expectedBytes: $validatedBundle->bytes, expectedSha256: $validatedBundle->sha256);
        $storedDataset = $this->storage->storeDataset($trainingRun, $id, $validatedBundle->bundle['dataset_path'],
            expectedBytes: $proof['dataset_bytes'], expectedSha256: $datasetHash);
        $storedReport = $evaluationReportPath === null ? null : $this->storage->storeReport($trainingRun, $id, $validatedBundle->bundle['report_path'],
            expectedBytes: $reportEntry['bytes'], expectedSha256: $reportEntry['sha256']);
        if (! hash_equals($storedDataset->sha256, $datasetHash) || $storedDataset->size !== $proof['dataset_bytes']
            || ! hash_equals($storedArtifact->sha256, $validatedBundle->sha256) || $storedArtifact->size !== $validatedBundle->bytes
            || $storedReport === null || $storedReport->sha256 !== $validatedBundle->bundle['bundle']['files']['evaluation.json']['sha256']) {
            throw new \RuntimeException('MLB staged dataset changed after admission; owned staged objects remain unregistered.');
        }
        $metadata = [
            ...(array) $trainingRun->metadata,
            'mlb_tabular_admission' => MlbModelRunAdmissionGuard::envelope($trainingRun, $id, $proof, ['artifact_hash' => $storedArtifact->sha256, 'artifact_bytes' => $storedArtifact->size, 'dataset_hash' => $datasetHash,
                'manifest_sha256' => app(MlbTabularDatasetProjector::class)->stableHash($manifest),
                'evaluation_sha256' => $storedReport->sha256, 'evaluation_bytes' => $storedReport->size,
                'python_model_run_id' => $manifest['model_run_id'], 'feature_schema_hash' => $manifest['feature_schema_hash'],
                'execution_format' => $manifest['execution_format'], 'market_type' => $marketType]),
            'model_artifact_id' => $id,
            'artifact_storage' => $this->storageMetadata($storedArtifact),
            'dataset_storage' => $this->storageMetadata($storedDataset),
            'evaluation_report_storage' => $storedReport ? $this->storageMetadata($storedReport) : null,
        ];

        MlbTabularJsonBudget::inspect(json_encode($metadata, JSON_THROW_ON_ERROR), 2097152, 100000);
        MlbTabularJsonBudget::inspect(json_encode($metrics, JSON_THROW_ON_ERROR), 2097152, 50000);
        $prepared = new MlPreparedArtifactRegistration(
            (string) $trainingRun->getKey(), $id, $this->mlbRunContext($trainingRun),
            ['id' => $id, 'training_run_id' => $trainingRun->getKey(), 'sport' => 'mlb', 'market_type' => $marketType,
                'model_type' => $modelType, 'model_version' => $modelVersion, 'feature_version' => $featureVersion,
                'dataset_hash' => $datasetHash, ...$this->datasetAttributes($storedDataset), ...$this->artifactAttributes($storedArtifact),
                'status' => 'challenger', 'metrics' => $metrics, ...$this->reportAttributes($storedReport)],
            ['artifact_path' => $storedArtifact->localPath, 'artifact_hash' => $storedArtifact->sha256,
                'status' => 'completed', 'completed_at' => now(), 'metadata' => $metadata], $proof,
        );
        $this->preparedMlb ??= new \WeakMap;
        $this->preparedMlb[$prepared] = true;

        return $prepared;
    }

    public function assertPreparedMlbOwned(MlPreparedArtifactRegistration $prepared): void
    {
        if (! ($this->preparedMlb[$prepared] ?? false)) {
            throw new \RuntimeException('MLB prepared artifact owner changed or its descriptor is not owned.');
        }
    }

    public function insertPreparedMlbRun(MlPreparedArtifactRegistration $prepared, ModelRun $run): void
    {
        MlbModelRunAdmissionGuard::insert($this, $prepared, $run);
    }

    /** Database-only; preparation and source admission must finish before entering SQL. */
    public function persistPreparedMlb(MlPreparedArtifactRegistration $prepared, ModelRun $trainingRun): ModelArtifact
    {
        $connection = $trainingRun->getConnection();
        MlbTabularSourceFence::assertOwned($connection, $prepared->admission);
        MlbArtifactCohortSerializer::assertOwned($connection);
        app(MlbArtifactCohortState::class)->assertAddition($connection, $prepared->artifactAttributes['metrics']);
        if (! ($this->preparedMlb[$prepared] ?? false) || ! $trainingRun->exists || (string) $trainingRun->getKey() !== $prepared->trainingRunId
            || $trainingRun->sport !== 'mlb' || ! hash_equals($prepared->runContextHash, $this->mlbRunContext($trainingRun))
            || (new ModelArtifact)->getConnection()->getName() !== $connection->getName()
            || ModelArtifact::query()->whereKey($prepared->artifactId)->lockForUpdate()->exists()) {
            throw new \RuntimeException('MLB prepared artifact owner changed or its target UUID is already registered.');
        }
        $artifact = ModelArtifact::query()->create($prepared->artifactAttributes);
        $trainingRun->forceFill($prepared->runUpdates)->save();

        return $artifact;
    }

    private function mlbRunContext(ModelRun $run): string
    {
        return app(MlbTabularDatasetProjector::class)->stableHash([
            'id' => $run->getKey(), 'sport' => $run->sport, 'run_type' => $run->run_type,
            'model_version' => $run->model_version, 'feature_version' => $run->feature_version, 'blend_version' => $run->blend_version,
            'config_hash' => $run->config_hash, 'code_version' => $run->code_version, 'parameters' => $run->parameters,
        ]);
    }

    public function attachDataset(ModelArtifact $artifact, string $datasetPath): ModelArtifact
    {
        $context = $this->existingStorageContext($artifact);
        if ($context['mlb']) {
            app(MlbExistingArtifactStorage::class)->attachTrainingDataset($context['artifact'], $datasetPath);

            return $this->existingStorageContext($context['artifact'])['artifact'];
        }
        $current = $context['artifact'];
        $run = $this->datasetTrainingRun($current);
        $context['run_identity'] = $this->storageRunIdentity($run);
        $path = $this->absoluteDatasetPath($datasetPath);
        $bytes = $this->datasetSourceBytes($path);
        if (! hash_equals($current->dataset_hash, MlbTabularFiles::hash($path, $bytes))) {
            throw new \RuntimeException('The ML dataset does not match the registered SHA-256.');
        }
        if ($this->hasCanonicalObject($current->dataset_disk, $current->dataset_object_key)) {
            $declared = $current->getRawOriginal('dataset_size');
            if ((! is_int($declared) && (! is_string($declared) || preg_match('/^(0|[1-9][0-9]{0,9})$/D', $declared) !== 1))
                || (int) $declared !== $bytes) {
                throw new ExistingArtifactOwnershipRefused('Existing dataset descriptor differs from its supplied content length.');
            }
            $materialized = $this->storage->materialize($current->dataset_disk, $current->dataset_object_key,
                $current->dataset_hash, $current->dataset_content_type, expectedBytes: $bytes);
            if (! hash_equals($current->dataset_hash, MlbTabularFiles::hash($materialized, $bytes))) {
                throw new ExistingArtifactOwnershipRefused('Existing dataset returned another content object.');
            }

            return $this->finishDatasetStorage($artifact, $context, ['dataset_path' => $materialized]);
        }
        $stored = $this->storage->storeDataset($run, $current->id, $path,
            expectedBytes: $bytes, expectedSha256: $current->dataset_hash);
        if ($stored->size !== $bytes || ! hash_equals($current->dataset_hash, $stored->sha256)) {
            throw new ExistingArtifactOwnershipRefused('Dataset storage returned another content descriptor.');
        }

        return $this->finishDatasetStorage($artifact, $context, $this->datasetAttributes($stored), $stored);
    }

    public function attachEvaluationReport(ModelArtifact $artifact, string $reportPath): ModelArtifact
    {
        $context = $this->existingStorageContext($artifact);
        if ($context['mlb']) {
            return app(MlbExistingArtifactStorage::class)->attachTrainingReport($context['artifact'], $reportPath);
        }
        $current = $context['artifact'];
        $trainingRun = $this->trainingRun($current);
        $context['run_identity'] = $this->storageRunIdentity($trainingRun);
        if (filled($current->evaluation_report_hash)) {
            $this->assertSourceHash($reportPath, (string) $current->evaluation_report_hash, 'evaluation report');
            if ($this->hasCanonicalObject($current->evaluation_report_disk, $current->evaluation_report_object_key)) {
                $path = $this->storage->materialize($current->evaluation_report_disk, $current->evaluation_report_object_key,
                    (string) $current->evaluation_report_hash, $current->evaluation_report_content_type);

                return $this->persistExistingStorage($artifact, $context, ['evaluation_report_path' => $path]);
            }
        }
        $stored = $this->storage->storeReport($trainingRun, $current->id, $reportPath);

        return $this->persistExistingStorage($artifact, $context, $this->reportAttributes($stored), $stored, 'evaluation_report_storage');
    }

    public function materializeArtifact(ModelArtifact $artifact, bool $persistCachePath = true, ?int $maximumBytes = null): string
    {
        if ($maximumBytes !== null && ($maximumBytes < 0 || $maximumBytes > 1073741824)) {
            throw new ExistingArtifactOwnershipRefused('ML artifact maximum byte policy is unsupported.');
        }
        // Only this content-read entry accepts a known partial projection. Omitted URI values
        // come from the current DB descriptor and remain bound through the final check.
        $context = $this->existingStorageContext($artifact, projection: true);
        $current = $context['artifact'];
        if ($context['mlb'] && $current->sport === 'mlb' && $current->model_type === 'mlb_tabular_bundle') {
            return app(MlbExistingArtifactStorage::class)->materializeArtifact($current, $persistCachePath, $maximumBytes ?? 268435456);
        }
        $period = $context['mlb'];
        if ($period && ($persistCachePath || $current->sport !== 'mlb' || $current->model_type !== 'mlb_period_bundle')) {
            throw new ExistingArtifactOwnershipRefused('Unsupported MLB cache mutation; period content requires the explicit read-only role.');
        }
        $run = $this->cacheTrainingRun($current, period: $period);
        $context['run_identity'] = $this->storageRunIdentity($run);
        if ($period) {
            $context['period_identity'] = $this->periodCacheIdentity($current, $run);
            $maximumBytes = min($maximumBytes ?? 33554432, 33554432);
        }
        $expectedBytes = $this->cacheExpectedBytes($current, $maximumBytes);
        if ($this->hasCanonicalObject($current->artifact_disk, $current->artifact_object_key)) {
            $path = $this->storage->materialize($current->artifact_disk, $current->artifact_object_key, $current->artifact_hash,
                $current->artifact_content_type, expectedBytes: $expectedBytes);
        } elseif ($expectedBytes !== null) {
            $path = $this->absolutePath((string) $current->artifact_path);
            if (! hash_equals($current->artifact_hash, MlbTabularFiles::hash($path, $expectedBytes))) {
                throw new \RuntimeException('Bounded legacy artifact hash does not match its registered content.');
            }
        } else {
            $path = $this->verifiedLegacyPath($current->artifact_path, $current->artifact_hash, 'artifact');
        }
        $this->finishArtifactCache($artifact, $context, $persistCachePath ? $path : null);

        return $path;
    }

    /** Explicit false reads content without persisting a cache hint. Existing true remains the default. */
    public function materializeDataset(ModelArtifact $artifact, bool $persistCachePath = true, ?int $maximumBytes = null): string
    {
        $context = $this->existingStorageContext($artifact);
        if ($context['mlb']) {
            return app(MlbExistingArtifactStorage::class)->materializeTrainingDataset($context['artifact'], $persistCachePath, $maximumBytes);
        }
        $current = $context['artifact'];
        $context['run_identity'] = $this->storageRunIdentity($this->datasetTrainingRun($current));
        $canonical = $this->hasCanonicalObject($current->dataset_disk, $current->dataset_object_key);
        $legacy = $canonical ? null : $this->absoluteDatasetPath((string) $current->dataset_path);
        $raw = $current->getRawOriginal('dataset_size');
        if ($raw === null && $legacy !== null) {
            $bytes = $this->datasetSourceBytes($legacy);
        } elseif ((is_int($raw) || (is_string($raw) && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $raw)))
            && (int) $raw >= 0 && (int) $raw <= 2147483647) {
            $bytes = (int) $raw;
        } else {
            throw new ExistingArtifactOwnershipRefused('Dataset content lacks its bounded recorded byte length.');
        }
        if ($maximumBytes !== null && ($maximumBytes < 0 || $maximumBytes > 2147483647 || $bytes > $maximumBytes)) {
            throw new ExistingArtifactOwnershipRefused('Dataset content exceeds its explicit byte policy.');
        }
        $path = $canonical
            ? $this->storage->materialize($current->dataset_disk, $current->dataset_object_key, $current->dataset_hash,
                $current->dataset_content_type, expectedBytes: $bytes)
            : $legacy;
        if (! hash_equals($current->dataset_hash, MlbTabularFiles::hash($path, $bytes))) {
            throw new ExistingArtifactOwnershipRefused('Dataset materialized content differs from its registered SHA-256.');
        }
        $this->finishDatasetStorage($artifact, $context, $persistCachePath ? ['dataset_path' => $path] : []);

        return $path;
    }

    public function materializeEvaluationReport(ModelArtifact $artifact): string
    {
        $context = $this->existingStorageContext($artifact);
        if ($context['mlb']) {
            return app(MlbExistingArtifactStorage::class)->materializeTrainingReport($context['artifact']);
        }
        $current = $context['artifact'];
        $context['run_identity'] = $this->storageRunIdentity($this->trainingRun($current));
        if ($this->hasCanonicalObject($current->evaluation_report_disk, $current->evaluation_report_object_key)) {
            $path = $this->storage->materialize($current->evaluation_report_disk, $current->evaluation_report_object_key,
                (string) $current->evaluation_report_hash, $current->evaluation_report_content_type);
            $this->persistExistingStorage($artifact, $context, ['evaluation_report_path' => $path]);

            return $path;
        }

        return $this->verifiedLegacyPath($current->evaluation_report_path, (string) $current->evaluation_report_hash, 'evaluation report');
    }

    public function archiveExisting(ModelArtifact $artifact): ModelArtifact
    {
        $context = $this->existingStorageContext($artifact);
        if ($context['mlb']) {
            return app(MlbExistingArtifactStorage::class)->archive($context['artifact']);
        }
        $current = $context['artifact'];
        $trainingRun = $this->trainingRun($current);
        $context['run_identity'] = $this->storageRunIdentity($trainingRun);
        if ($this->hasCanonicalObject($current->artifact_disk, $current->artifact_object_key)) {
            $path = $this->storage->materialize($current->artifact_disk, $current->artifact_object_key,
                $current->artifact_hash, $current->artifact_content_type);

            return $this->persistExistingStorage($artifact, $context, ['artifact_path' => $path]);
        }
        $legacyPath = $this->verifiedLegacyPath($current->artifact_path, $current->artifact_hash, 'artifact');
        $stored = $this->storage->storeArtifact($trainingRun, $current->id, $legacyPath);

        return $this->persistExistingStorage($artifact, $context, $this->artifactAttributes($stored), $stored, 'artifact_storage', true);
    }

    /** Preserve the legacy nonMLB missing-file policy, but only for its still-current owner. */
    public function invalidateArchiveFailure(ModelArtifact $artifact): ModelArtifact
    {
        $context = $this->existingStorageContext($artifact);
        if ($context['mlb']) {
            throw new ExistingArtifactOwnershipRefused('MLB storage failure cannot grant unfenced status invalidation.');
        }
        $context['run_identity'] = $this->storageRunIdentity($this->trainingRun($context['artifact']));

        return $this->persistExistingStorage($artifact, $context, ['status' => 'invalidated', 'promotion_decision' => [
            'eligible' => false, 'reason' => 'artifact_file_missing_or_hash_mismatch', 'invalidated_at' => now()->toIso8601String(),
        ]]);
    }

    public function forPath(string $path): ?ModelArtifact
    {
        $absolutePath = $this->absolutePath($path);
        $registeredPath = ModelArtifact::query()->where('artifact_path', $absolutePath)->first();

        if ($registeredPath instanceof ModelArtifact) {
            try {
                $this->materializeArtifact($registeredPath, persistCachePath: false);
            } catch (\RuntimeException) {
                return null;
            }

            return $registeredPath->unsetRelation('trainingRun');
        }

        if (! File::exists($absolutePath)) {
            $registeredAliasId = ModelArtifact::query()
                ->whereHas(
                    'trainingRun',
                    fn ($query) => $query->where('metadata->inference_alias_path', $absolutePath),
                )
                ->latest('created_at')
                ->value('id');

            $registeredAlias = $registeredAliasId === null
                ? null
                : ModelArtifact::query()->with('trainingRun')->find($registeredAliasId);

            if (! $registeredAlias instanceof ModelArtifact) {
                return null;
            }

            try {
                $this->materializeArtifact($registeredAlias, persistCachePath: false);
            } catch (\RuntimeException) {
                return null;
            }

            return $registeredAlias->unsetRelation('trainingRun');
        }

        return ModelArtifact::query()
            ->where('artifact_hash', hash_file('sha256', $absolutePath))
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function artifactAttributes(MlStoredObject $stored): array
    {
        return [
            'artifact_path' => $stored->localPath,
            'artifact_disk' => $stored->disk,
            'artifact_object_key' => $stored->objectKey,
            'artifact_uri' => $stored->uri,
            'artifact_hash' => $stored->sha256,
            'artifact_size' => $stored->size,
            'artifact_content_type' => $stored->contentType,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function datasetAttributes(?MlStoredObject $stored): array
    {
        return [
            'dataset_path' => $stored?->localPath,
            'dataset_disk' => $stored?->disk,
            'dataset_object_key' => $stored?->objectKey,
            'dataset_uri' => $stored?->uri,
            'dataset_size' => $stored?->size,
            'dataset_content_type' => $stored?->contentType,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportAttributes(?MlStoredObject $stored): array
    {
        return [
            'evaluation_report_path' => $stored?->localPath,
            'evaluation_report_disk' => $stored?->disk,
            'evaluation_report_object_key' => $stored?->objectKey,
            'evaluation_report_uri' => $stored?->uri,
            'evaluation_report_hash' => $stored?->sha256,
            'evaluation_report_size' => $stored?->size,
            'evaluation_report_content_type' => $stored?->contentType,
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function storageMetadata(MlStoredObject $stored): array
    {
        return [
            'disk' => $stored->disk,
            'object_key' => $stored->objectKey,
            'uri' => $stored->uri,
            'sha256' => $stored->sha256,
            'size' => $stored->size,
            'content_type' => $stored->contentType,
        ];
    }

    private function mergeRunStorageMetadata(
        ModelRun $trainingRun,
        string $key,
        MlStoredObject $stored,
    ): void {
        $trainingRun->forceFill([
            'metadata' => [
                ...(array) $trainingRun->metadata,
                $key => $this->storageMetadata($stored),
            ],
        ])->save();
    }

    /** Bounded current identity/storage headers; no caller sport/family or cached relation grants authority. */
    private function existingStorageContext(ModelArtifact $input, bool $lock = false, bool $projection = false): array
    {
        $connection = $input->getConnection();
        if ($input->getTable() !== (new ModelArtifact)->getTable() || $input->getKeyName() !== (new ModelArtifact)->getKeyName()
            || ! $input->exists || $input->isDirty() || ! Str::isUuid((string) $input->getRawOriginal($input->getKeyName()))
            || $input->getKey() !== $input->getRawOriginal($input->getKeyName())) {
            throw new ExistingArtifactOwnershipRefused('Existing artifact storage requires a clean persisted current identity.');
        }
        $pdo = $connection->getPdo();
        $name = $connection->getName();
        if (! is_string($name) || $name === '' || ! in_array($connection->getDriverName(), ['mysql', 'sqlite'], true)) {
            throw new ExistingArtifactOwnershipRefused('Existing artifact storage connection is unsupported.');
        }
        $limits = ['id' => 36, 'training_run_id' => 36, 'sport' => 32, 'model_type' => 96, 'status' => 32,
            'model_version' => 128, 'feature_version' => 128, 'market_type' => 64, 'dataset_hash' => 64,
            'dataset_path' => 4096, 'dataset_disk' => 64, 'dataset_object_key' => 4096, 'dataset_uri' => 4096,
            'dataset_size' => 32, 'dataset_content_type' => 128,
            'artifact_path' => 4096, 'artifact_disk' => 64, 'artifact_object_key' => 4096, 'artifact_uri' => 4096,
            'artifact_hash' => 64, 'artifact_size' => 32, 'artifact_content_type' => 128,
            'evaluation_report_path' => 4096, 'evaluation_report_disk' => 64, 'evaluation_report_object_key' => 4096,
            'evaluation_report_uri' => 4096, 'evaluation_report_hash' => 64, 'evaluation_report_size' => 32, 'evaluation_report_content_type' => 128];
        $grammar = $connection->getQueryGrammar();
        $mysql = $connection->getDriverName() === 'mysql';
        $length = $mysql ? 'OCTET_LENGTH' : 'length';
        $cast = $mysql ? 'BINARY' : 'BLOB';
        $select = $invalid = [];
        foreach ($limits as $field => $maximum) {
            $column = $grammar->wrap($field);
            $bytes = "COALESCE($length(CAST($column AS $cast)), 0)";
            $select[] = "CASE WHEN $bytes <= $maximum THEN $column ELSE NULL END AS $column";
            $invalid[] = "$bytes > $maximum";
        }
        $query = $input->newModelQuery()->useWritePdo()->whereKey($input->getRawOriginal($input->getKeyName()))
            ->selectRaw(implode(', ', $select).', CASE WHEN ('.implode(' OR ', $invalid).') THEN 1 ELSE 0 END AS _storage_oversized, CASE WHEN sport = ? THEN 1 ELSE 0 END AS _mlb_cohort', ['mlb']);
        if ($query->getQuery()->getConnection() !== $connection) {
            throw new ExistingArtifactOwnershipRefused('Existing artifact storage resolved another connection.');
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $current = $query->first();
        if ($current === null || (int) $current->_storage_oversized !== 0) {
            throw new ExistingArtifactOwnershipRefused('Existing artifact storage current header is missing or oversized.');
        }
        $loaded = $input->getRawOriginal();
        if ($projection && array_diff(['id', 'training_run_id', 'sport', 'model_type', 'status', 'model_version', 'feature_version',
            'market_type', 'dataset_hash', 'artifact_hash', 'artifact_path'], array_keys($loaded)) !== []) {
            throw new ExistingArtifactOwnershipRefused('Artifact content projection lacks required current owner/content bindings.');
        }
        foreach (array_keys($limits) as $field) {
            if ($projection && ! array_key_exists($field, $loaded)) {
                continue;
            }
            if ($current->getRawOriginal($field) !== $input->getRawOriginal($field)) {
                throw new ExistingArtifactOwnershipRefused('Existing artifact storage owner or descriptor changed; refresh is required.');
            }
        }
        foreach (['id', 'training_run_id', 'sport', 'model_type', 'status'] as $field) {
            if (! is_string($current->getRawOriginal($field)) || $current->getRawOriginal($field) === '') {
                throw new ExistingArtifactOwnershipRefused('Existing artifact storage current identity is unavailable.');
            }
        }
        $mlb = (int) $current->_mlb_cohort === 1 || strtolower(trim($current->sport)) === 'mlb';
        $raw = $current->getAttributes();
        unset($raw['_mlb_cohort'], $raw['_storage_oversized']);
        $current->setRawAttributes($raw, true);
        $context = ['artifact' => $current, 'input' => $input, 'connection' => $connection, 'pdo' => $pdo, 'name' => $name,
            'mlb' => $mlb, 'fingerprint' => app(CanonicalPayloadHasher::class)->hash($raw)];
        $this->assertStorageOwner($context);

        return $context;
    }

    private function cacheExpectedBytes(ModelArtifact $artifact, ?int $maximum): ?int
    {
        if ($maximum === null) {
            return null;
        }
        $raw = $artifact->getRawOriginal('artifact_size');
        if ($maximum < 0 || $maximum > 1073741824
            || (! is_int($raw) && (! is_string($raw) || preg_match('/^(0|[1-9][0-9]{0,9})$/D', $raw) !== 1))
            || (int) $raw < 0 || (int) $raw > $maximum) {
            throw new ExistingArtifactOwnershipRefused('ML artifact lacks a supported bounded recorded byte length.');
        }

        return (int) $raw;
    }

    /** Cache ownership needs bounded scalar identity, never whole training parameters/metadata. */
    private function cacheTrainingRun(ModelArtifact $artifact, bool $lock = false, bool $period = false): ModelRun
    {
        $connection = $artifact->getConnection();
        $mysql = $connection->getDriverName() === 'mysql';
        $length = $mysql ? 'OCTET_LENGTH' : 'length';
        $cast = $mysql ? 'BINARY' : 'BLOB';
        $grammar = $connection->getQueryGrammar();
        $select = $invalid = [];
        foreach (['id', 'sport', 'run_type', 'model_version', 'feature_version', 'blend_version', 'config_hash', 'code_version', 'artifact_hash', 'status'] as $field) {
            $column = $grammar->wrap($field);
            $bytes = "COALESCE($length(CAST($column AS $cast)),0)";
            $select[] = "CASE WHEN $bytes <= 4096 THEN $column ELSE NULL END AS $column";
            $invalid[] = "$bytes > 4096";
        }
        if ($period) {
            $column = $grammar->wrap('metadata');
            $safe = "CASE WHEN $length(CAST($column AS $cast)) <= 67108864 THEN $column ELSE NULL END";
            $json = "CASE WHEN JSON_VALID($safe) = 1 THEN $safe ELSE '{}' END";
            foreach (['_period_timing' => '$.python_manifest.timing_policy', '_period_format' => '$.python_manifest.execution_format',
                '_period_python_id' => '$.python_model_run_id'] as $alias => $path) {
                $extract = "JSON_EXTRACT($json, '$path')";
                $type = $mysql ? "JSON_TYPE($extract)" : "json_type($json, '$path')";
                $text = $mysql ? "JSON_UNQUOTE($extract)" : $extract;
                $stringType = $mysql ? 'STRING' : 'text';
                $select[] = "CASE WHEN $type = '$stringType' AND $length(CAST($text AS $cast)) <= 256 THEN $text ELSE NULL END AS $alias";
                $invalid[] = "CASE WHEN $type IS NULL OR ($type = '$stringType' AND $length(CAST($text AS $cast)) <= 256) THEN 0 ELSE 1 END = 1";
            }
            $flag = "JSON_EXTRACT($json, '$.python_manifest.source_availability_verified')";
            $truth = $mysql ? "JSON_TYPE($flag) = 'BOOLEAN' AND JSON_UNQUOTE($flag) = 'true'" : "json_type($json, '$.python_manifest.source_availability_verified') = 'true'";
            $select[] = "CASE WHEN $truth THEN 1 ELSE 0 END AS _period_available";
        }
        $query = (new ModelRun)->setConnection($connection->getName())->newQuery()->useWritePdo()->whereKey($artifact->training_run_id)
            ->selectRaw(implode(', ', $select).', CASE WHEN ('.implode(' OR ', $invalid).') THEN 1 ELSE 0 END AS _cache_run_invalid');
        if ($query->getQuery()->getConnection() !== $connection) {
            throw new ExistingArtifactOwnershipRefused('Cache run resolved outside its current owner connection.');
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $run = $query->first();
        if ($run === null || (int) $run->_cache_run_invalid !== 0 || $run->sport !== $artifact->sport) {
            throw new ExistingArtifactOwnershipRefused('Cache run current owner is missing or exceeds its scalar policy.');
        }

        return $run;
    }

    /** Existing declared period metadata admission only, not protected training-source proof. */
    private function periodCacheIdentity(ModelArtifact $artifact, ModelRun $run): array
    {
        if ($artifact->sport !== 'mlb' || $artifact->model_type !== 'mlb_period_bundle'
            || ! in_array($artifact->status, ['challenger', 'promotion_eligible', 'promoted'], true)
            || $run->_period_timing !== MlbGameStart::TIMING_POLICY
            || $run->_period_format !== MlbPeriodModelInferenceService::EXECUTION_FORMAT
            || (int) $run->_period_available !== 1) {
            throw new ExistingArtifactOwnershipRefused('Period content lacks its current numeric/observed-start metadata read policy.');
        }

        return [$run->_period_timing, $run->_period_format, (int) $run->_period_available, $run->_period_python_id];
    }

    private function finishArtifactCache(ModelArtifact $input, array $context, ?string $persistPath): void
    {
        $this->assertStorageOwner($context);
        $result = $context['connection']->transaction(function () use ($context, $persistPath): ModelArtifact {
            $this->assertStorageOwner($context);
            $period = isset($context['period_identity']);
            $run = $this->cacheTrainingRun($context['artifact'], true, $period);
            if ($this->storageRunIdentity($run) !== $context['run_identity']
                || ($period && $this->periodCacheIdentity($context['artifact'], $run) !== $context['period_identity'])) {
                throw new ExistingArtifactOwnershipRefused('Cache training owner or period read policy changed during IO.');
            }
            $locked = $this->existingStorageContext($context['artifact'], true);
            if (! hash_equals($locked['fingerprint'], $context['fingerprint'])) {
                throw new ExistingArtifactOwnershipRefused('Cache artifact owner or content changed during IO.');
            }
            $current = $locked['artifact'];
            if ($persistPath !== null && $current->artifact_path !== $persistPath && $current->forceFill(['artifact_path' => $persistPath])->save() !== true) {
                throw new ExistingArtifactOwnershipRefused('Artifact cache pointer persistence was cancelled.');
            }
            $this->assertStorageOwner($context);

            return $current;
        });
        if ($persistPath !== null) {
            $this->syncStorageResult($input, $result);
        }
    }

    private function assertStorageOwner(array $context): void
    {
        if (DB::connection($context['name']) !== $context['connection']
            || $context['input']->getConnection() !== $context['connection']
            || $context['artifact']->getConnection() !== $context['connection']
            || $context['connection']->getRawPdo() !== $context['pdo']) {
            throw new ExistingArtifactOwnershipRefused('Existing artifact storage connection ownership changed.');
        }
    }

    private function storageRunIdentity(ModelRun $run): array
    {
        return array_intersect_key($run->getAttributes(), array_flip(['id', 'sport', 'run_type', 'model_version', 'feature_version',
            'blend_version', 'config_hash', 'code_version', 'artifact_hash', 'status']));
    }

    /** Storage has already finished. Only fresh locked owner rows are merged inside SQL. */
    private function persistExistingStorage(ModelArtifact $input, array $context, array $attributes, ?MlStoredObject $stored = null,
        ?string $metadataKey = null, bool $archiveRun = false): ModelArtifact
    {
        $this->assertStorageOwner($context);
        $result = $context['connection']->transaction(function () use ($context, $attributes, $stored, $metadataKey, $archiveRun): ModelArtifact {
            $this->assertStorageOwner($context);
            $run = $this->trainingRun($context['artifact'], true);
            if ($this->storageRunIdentity($run) !== $context['run_identity']) {
                throw new ExistingArtifactOwnershipRefused('Existing storage training owner changed during IO.');
            }
            $locked = $this->existingStorageContext($context['artifact'], true);
            if ($locked['mlb'] || ! hash_equals($context['fingerprint'], $locked['fingerprint'])) {
                throw new ExistingArtifactOwnershipRefused('Existing storage target changed during IO.');
            }
            $artifact = $locked['artifact'];
            if ($artifact->forceFill($attributes)->save() !== true) {
                throw new ExistingArtifactOwnershipRefused('Existing artifact storage pointer persistence was cancelled.');
            }
            if ($stored !== null && $metadataKey !== null) {
                $updates = ['metadata' => [...(array) $run->metadata, $metadataKey => $this->storageMetadata($stored)]];
                if ($archiveRun) {
                    $updates += ['artifact_path' => $stored->localPath, 'artifact_hash' => $stored->sha256];
                }
                if ($run->forceFill($updates)->save() !== true) {
                    throw new ExistingArtifactOwnershipRefused('Existing artifact storage run persistence was cancelled.');
                }
            }
            $this->assertStorageOwner($context);

            return $artifact;
        });

        return $this->syncStorageResult($input, $result);
    }

    private function syncStorageResult(ModelArtifact $input, ModelArtifact $current): ModelArtifact
    {
        $raw = $current->getAttributes();
        unset($raw['_artifact_oversized']);
        $input->setRawAttributes([...$input->getAttributes(), ...$raw], true);
        $input->setConnection($current->getConnectionName());
        $input->unsetRelation('trainingRun');

        return $input;
    }

    private function trainingRun(ModelArtifact $artifact, bool $lock = false): ModelRun
    {
        $connection = $artifact->getConnection();
        $query = (new ModelRun)->setConnection($connection->getName())->newQuery()->useWritePdo()->whereKey($artifact->training_run_id);
        if ($query->getQuery()->getConnection() !== $connection) {
            throw new ExistingArtifactOwnershipRefused('Training owner resolved outside the artifact connection.');
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $run = $query->first();
        if (! $run instanceof ModelRun || $run->sport !== $artifact->sport) {
            throw new ExistingArtifactOwnershipRefused('Current coherent training owner not found for model artifact.');
        }

        return $run;
    }

    private function absoluteDatasetPath(string $path): string
    {
        if ($path === '' || strlen($path) > 4096) {
            throw new ExistingArtifactOwnershipRefused('Dataset source path is empty or oversized.');
        }

        return $this->absolutePath($path);
    }

    private function datasetSourceBytes(string $path): int
    {
        clearstatcache(true, $path);
        $stat = lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || is_link($path)
            || $stat['size'] < 0 || $stat['size'] > 2147483647) {
            throw new ExistingArtifactOwnershipRefused('Dataset source must be a bounded regular file.');
        }

        return $stat['size'];
    }

    /** Scalar owners use the accepted cache header, not cached trainingRun relations. */
    private function datasetTrainingRun(ModelArtifact $artifact, bool $lock = false, bool $metadata = false): ModelRun
    {
        $run = $this->cacheTrainingRun($artifact, $lock);
        if (! $metadata) {
            return $run;
        }
        $connection = $artifact->getConnection();
        $column = $connection->getQueryGrammar()->wrap('metadata');
        $length = $connection->getDriverName() === 'mysql' ? 'OCTET_LENGTH' : 'length';
        $cast = $connection->getDriverName() === 'mysql' ? 'BINARY' : 'BLOB';
        // Bound the WHOLE body before PDO hydration; the period reader's larger envelope
        // extracts only tiny scalar JSON fields and does not justify full-body decoding.
        $maximum = self::DATASET_METADATA_BYTES;
        $query = $connection->table((new ModelRun)->getTable())->useWritePdo()->where('id', $run->id)
            ->selectRaw("CASE WHEN COALESCE($length(CAST($column AS $cast)),0) <= $maximum THEN $column ELSE NULL END AS metadata, CASE WHEN COALESCE($length(CAST($column AS $cast)),0) > $maximum THEN 1 ELSE 0 END AS oversized");
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if ($row === null || (int) $row->oversized !== 0) {
            throw new ExistingArtifactOwnershipRefused('Dataset owner metadata exceeds its SQL envelope.');
        }
        if ($row->metadata !== null) {
            JsonAllocationBudget::inspect($row->metadata, self::DATASET_METADATA_BYTES, self::DATASET_METADATA_TOKENS, 32);
            $decoded = json_decode($row->metadata, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new ExistingArtifactOwnershipRefused('Dataset owner metadata is not an object.');
            }
        }
        $run->setRawAttributes([...$run->getAttributes(), 'metadata' => $row->metadata], true);

        return $run;
    }

    /** IO is complete. Only fresh same-primary owners and bounded descriptors may change. */
    private function finishDatasetStorage(ModelArtifact $input, array $context, array $attributes, ?MlStoredObject $stored = null): ModelArtifact
    {
        foreach ($attributes as $field => $value) {
            $limit = match ($field) {
                'dataset_path' => 255, 'dataset_disk' => 64, 'dataset_content_type' => 128,
                'dataset_object_key', 'dataset_uri' => 4096, 'dataset_size' => 32,
                default => throw new ExistingArtifactOwnershipRefused('Unsupported dataset descriptor field.'),
            };
            if ($value !== null && strlen((string) $value) > $limit) {
                throw new ExistingArtifactOwnershipRefused('Dataset descriptor exceeds its column/byte policy.');
            }
        }
        $this->assertStorageOwner($context);
        $result = $context['connection']->transaction(function () use ($context, $attributes, $stored): ModelArtifact {
            $this->assertStorageOwner($context);
            $run = $this->datasetTrainingRun($context['artifact'], true, $stored !== null);
            if ($this->storageRunIdentity($run) !== $context['run_identity']) {
                throw new ExistingArtifactOwnershipRefused('Dataset training owner changed during IO.');
            }
            $locked = $this->existingStorageContext($context['artifact'], true);
            if ($locked['mlb'] || ! hash_equals($context['fingerprint'], $locked['fingerprint'])) {
                throw new ExistingArtifactOwnershipRefused('Dataset target owner or descriptor changed during IO.');
            }
            $current = $locked['artifact'];
            if ($attributes !== [] && $current->forceFill($attributes)->isDirty() && $current->save() !== true) {
                throw new ExistingArtifactOwnershipRefused('Dataset pointer persistence was cancelled.');
            }
            $expectedMetadataHash = null;
            if ($stored !== null) {
                $metadata = [...(array) $run->metadata, 'dataset_storage' => $this->storageMetadata($stored)];
                $encoded = json_encode($metadata, JSON_THROW_ON_ERROR);
                JsonAllocationBudget::inspect($encoded, self::DATASET_METADATA_BYTES, self::DATASET_METADATA_TOKENS, 32);
                $expectedMetadataHash = app(CanonicalPayloadHasher::class)->hash($metadata);
                if ($run->forceFill(['metadata' => $metadata])->save() !== true) {
                    throw new ExistingArtifactOwnershipRefused('Dataset owner metadata persistence was cancelled.');
                }
            }
            // Saved model flags are insufficient if an observer deletes/reassigns a persisted row.
            $fresh = $this->existingStorageContext($current, true)['artifact'];
            foreach ($attributes as $field => $expected) {
                $actual = $fresh->getRawOriginal($field);
                if (($field === 'dataset_size' ? (string) $actual !== (string) $expected : $actual !== $expected)) {
                    throw new ExistingArtifactOwnershipRefused('Dataset persistence differs from its staged requested descriptor.');
                }
            }
            $freshRun = $this->datasetTrainingRun($fresh, true, $stored !== null);
            if ($this->storageRunIdentity($freshRun) !== $context['run_identity']
                || ($stored !== null && app(CanonicalPayloadHasher::class)->hash($freshRun->metadata) !== $expectedMetadataHash)) {
                throw new ExistingArtifactOwnershipRefused('Dataset post-write owner/header changed.');
            }
            $this->assertStorageOwner($context);

            return $fresh;
        });
        if ($attributes !== []) {
            return $this->syncStorageResult($input, $result);
        }

        return $result;
    }

    private function verifiedLegacyPath(?string $path, string $sha256, string $kind): string
    {
        $absolutePath = $path ? $this->absolutePath($path) : '';
        if ($absolutePath === '' || ! File::exists($absolutePath)) {
            throw new \RuntimeException("Registered ML {$kind} is missing from local storage.");
        }

        $actualHash = hash_file('sha256', $absolutePath);
        if (! is_string($actualHash) || ! hash_equals($sha256, $actualHash)) {
            throw new \RuntimeException("Registered ML {$kind} failed SHA-256 verification.");
        }

        return $absolutePath;
    }

    private function hasCanonicalObject(?string $disk, ?string $objectKey): bool
    {
        return filled($disk) && filled($objectKey);
    }

    private function assertSourceHash(string $path, string $expectedHash, string $kind): void
    {
        $absolutePath = $this->absolutePath($path);
        if (! File::exists($absolutePath)) {
            throw new \RuntimeException("ML {$kind} source file not found: {$absolutePath}");
        }

        $actualHash = hash_file('sha256', $absolutePath);
        if (! is_string($actualHash) || ! hash_equals($expectedHash, $actualHash)) {
            throw new \RuntimeException("The ML {$kind} does not match the registered SHA-256.");
        }
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
