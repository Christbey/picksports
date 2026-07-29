<?php

namespace App\Services\ML;

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ModelArtifactRegistry
{
    public function __construct(
        private readonly MlArtifactStorage $storage,
    ) {}

    public function newId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * @param  array<string, mixed>  $metrics
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
    ): ModelArtifact {
        if ($datasetPath !== null) {
            $this->assertSourceHash($datasetPath, $datasetHash, 'dataset');
        }

        $storedArtifact = $this->storage->storeArtifact($trainingRun, $id, $artifactPath);
        $storedDataset = $datasetPath !== null
            ? $this->storage->storeDataset($trainingRun, $id, $datasetPath)
            : null;
        $storedReport = $evaluationReportPath !== null
            ? $this->storage->storeReport($trainingRun, $id, $evaluationReportPath)
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

    public function attachDataset(ModelArtifact $artifact, string $datasetPath): ModelArtifact
    {
        $this->assertSourceHash($datasetPath, $artifact->dataset_hash, 'dataset');

        if ($this->hasCanonicalObject($artifact->dataset_disk, $artifact->dataset_object_key)) {
            $this->materializeDataset($artifact);

            return $artifact->refresh();
        }

        $trainingRun = $this->trainingRun($artifact);
        $stored = $this->storage->storeDataset($trainingRun, $artifact->id, $datasetPath);

        $artifact->forceFill($this->datasetAttributes($stored))->save();
        $this->mergeRunStorageMetadata($trainingRun, 'dataset_storage', $stored);

        return $artifact->refresh();
    }

    public function attachEvaluationReport(ModelArtifact $artifact, string $reportPath): ModelArtifact
    {
        if (filled($artifact->evaluation_report_hash)) {
            $this->assertSourceHash(
                $reportPath,
                (string) $artifact->evaluation_report_hash,
                'evaluation report',
            );

            if ($this->hasCanonicalObject($artifact->evaluation_report_disk, $artifact->evaluation_report_object_key)) {
                $this->materializeEvaluationReport($artifact);

                return $artifact->refresh();
            }
        }

        $trainingRun = $this->trainingRun($artifact);
        $stored = $this->storage->storeReport($trainingRun, $artifact->id, $reportPath);

        $artifact->forceFill($this->reportAttributes($stored))->save();
        $this->mergeRunStorageMetadata($trainingRun, 'evaluation_report_storage', $stored);

        return $artifact->refresh();
    }

    public function materializeArtifact(ModelArtifact $artifact): string
    {
        if ($this->hasCanonicalObject($artifact->artifact_disk, $artifact->artifact_object_key)) {
            $path = $this->storage->materialize(
                disk: $artifact->artifact_disk,
                objectKey: $artifact->artifact_object_key,
                sha256: $artifact->artifact_hash,
                contentType: $artifact->artifact_content_type,
            );

            if ($artifact->artifact_path !== $path) {
                $artifact->forceFill(['artifact_path' => $path])->save();
            }

            return $path;
        }

        return $this->verifiedLegacyPath($artifact->artifact_path, $artifact->artifact_hash, 'artifact');
    }

    public function materializeDataset(ModelArtifact $artifact): string
    {
        if ($this->hasCanonicalObject($artifact->dataset_disk, $artifact->dataset_object_key)) {
            $path = $this->storage->materialize(
                disk: $artifact->dataset_disk,
                objectKey: $artifact->dataset_object_key,
                sha256: $artifact->dataset_hash,
                contentType: $artifact->dataset_content_type,
            );

            if ($artifact->dataset_path !== $path) {
                $artifact->forceFill(['dataset_path' => $path])->save();
            }

            return $path;
        }

        return $this->verifiedLegacyPath($artifact->dataset_path, $artifact->dataset_hash, 'dataset');
    }

    public function materializeEvaluationReport(ModelArtifact $artifact): string
    {
        if ($this->hasCanonicalObject($artifact->evaluation_report_disk, $artifact->evaluation_report_object_key)) {
            $path = $this->storage->materialize(
                disk: $artifact->evaluation_report_disk,
                objectKey: $artifact->evaluation_report_object_key,
                sha256: (string) $artifact->evaluation_report_hash,
                contentType: $artifact->evaluation_report_content_type,
            );

            if ($artifact->evaluation_report_path !== $path) {
                $artifact->forceFill(['evaluation_report_path' => $path])->save();
            }

            return $path;
        }

        return $this->verifiedLegacyPath(
            $artifact->evaluation_report_path,
            (string) $artifact->evaluation_report_hash,
            'evaluation report',
        );
    }

    public function archiveExisting(ModelArtifact $artifact): ModelArtifact
    {
        if ($this->hasCanonicalObject($artifact->artifact_disk, $artifact->artifact_object_key)) {
            $this->materializeArtifact($artifact);

            return $artifact->refresh();
        }

        $legacyPath = $this->verifiedLegacyPath($artifact->artifact_path, $artifact->artifact_hash, 'artifact');
        $trainingRun = $this->trainingRun($artifact);
        $stored = $this->storage->storeArtifact($trainingRun, $artifact->id, $legacyPath);

        $artifact->forceFill($this->artifactAttributes($stored))->save();
        $this->mergeRunStorageMetadata($trainingRun, 'artifact_storage', $stored);
        $trainingRun->forceFill([
            'artifact_path' => $stored->localPath,
            'artifact_hash' => $stored->sha256,
        ])->save();

        return $artifact->refresh();
    }

    public function forPath(string $path): ?ModelArtifact
    {
        $absolutePath = $this->absolutePath($path);
        $registeredPath = ModelArtifact::query()->where('artifact_path', $absolutePath)->first();

        if ($registeredPath instanceof ModelArtifact) {
            try {
                $this->materializeArtifact($registeredPath);
            } catch (\RuntimeException) {
                return null;
            }

            return $registeredPath->refresh();
        }

        if (! File::exists($absolutePath)) {
            $registeredAlias = ModelArtifact::query()
                ->with('trainingRun')
                ->whereHas(
                    'trainingRun',
                    fn ($query) => $query->where('metadata->inference_alias_path', $absolutePath),
                )
                ->latest('created_at')
                ->first();

            if (! $registeredAlias instanceof ModelArtifact) {
                return null;
            }

            try {
                $this->materializeArtifact($registeredAlias);
            } catch (\RuntimeException) {
                return null;
            }

            return $registeredAlias->refresh();
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

    private function trainingRun(ModelArtifact $artifact): ModelRun
    {
        $trainingRun = $artifact->trainingRun;
        if (! $trainingRun instanceof ModelRun) {
            throw new \RuntimeException("Training run not found for model artifact [{$artifact->id}].");
        }

        return $trainingRun;
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
