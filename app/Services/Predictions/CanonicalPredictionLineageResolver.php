<?php

namespace App\Services\Predictions;

use App\Models\DatasetExportManifest;
use App\Models\FeatureSchema;
use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Models\PredictionFeatureSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class CanonicalPredictionLineageResolver
{
    /**
     * Resolve only provenance supported by an exact identifier or a single exact
     * version match. Ambiguous candidates intentionally remain unlinked.
     *
     * @return array{feature_schema_id:?int,dataset_export_manifest_id:?int,model_run_id:?string,model_artifact_id:?string}
     */
    public function resolve(string $sport, Model $legacyPrediction, bool $persist = false): array
    {
        $modelVersion = $this->stringAttribute($legacyPrediction, 'model_version');
        $featureVersion = $this->stringAttribute($legacyPrediction, 'feature_version');
        $blendVersion = $this->stringAttribute($legacyPrediction, 'blend_version');
        $snapshots = $this->matchingSnapshots($sport, $legacyPrediction, $modelVersion, $featureVersion, $blendVersion);
        $metadata = $snapshots
            ->flatMap(fn (PredictionFeatureSnapshot $snapshot): array => [
                (array) $snapshot->model_metadata,
                (array) $snapshot->lineage_metadata,
            ]);

        $modelRun = $this->resolveModelRun(
            $sport,
            $modelVersion,
            $featureVersion,
            $blendVersion,
            $snapshots,
            $metadata,
        );
        $artifact = $this->resolveArtifact(
            $sport,
            $modelVersion,
            $featureVersion,
            $snapshots,
            $metadata,
        );
        $dataset = $this->resolveDataset($sport, $artifact, $metadata, $modelRun);
        $featureSchema = $this->resolveFeatureSchema(
            $sport,
            $featureVersion,
            $metadata,
            $modelRun,
            $persist,
        );

        return [
            'feature_schema_id' => $featureSchema?->getKey(),
            'dataset_export_manifest_id' => $dataset?->getKey(),
            'model_run_id' => $modelRun?->getKey(),
            'model_artifact_id' => $artifact?->getKey(),
        ];
    }

    /** @return Collection<int, PredictionFeatureSnapshot> */
    private function matchingSnapshots(
        string $sport,
        Model $legacyPrediction,
        ?string $modelVersion,
        ?string $featureVersion,
        ?string $blendVersion,
    ): Collection {
        return PredictionFeatureSnapshot::query()
            ->where('sport', $sport)
            ->where('prediction_table', $legacyPrediction->getTable())
            ->where('prediction_id', $legacyPrediction->getKey())
            ->when($modelVersion !== null, fn (Builder $query) => $query->where('model_version', $modelVersion))
            ->when($featureVersion !== null, fn (Builder $query) => $query->where('feature_version', $featureVersion))
            ->when($blendVersion !== null, fn (Builder $query) => $query->where('blend_version', $blendVersion))
            ->get();
    }

    /** @param Collection<int, PredictionFeatureSnapshot> $snapshots
     * @param  Collection<int, array<string, mixed>>  $metadata
     */
    private function resolveModelRun(
        string $sport,
        ?string $modelVersion,
        ?string $featureVersion,
        ?string $blendVersion,
        Collection $snapshots,
        Collection $metadata,
    ): ?ModelRun {
        $ids = $snapshots->pluck('model_run_id')
            ->merge($this->metadataValues($metadata, ['model_run_id']))
            ->filter()
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values();

        if ($ids->count() === 1) {
            return ModelRun::query()->where('sport', $sport)->find($ids->first());
        }

        if ($ids->isNotEmpty() || $modelVersion === null || $featureVersion === null || $blendVersion === null) {
            return null;
        }

        return $this->soleOrNull(ModelRun::query()
            ->where('sport', $sport)
            ->where('model_version', $modelVersion)
            ->where('feature_version', $featureVersion)
            ->where('blend_version', $blendVersion));
    }

    /** @param Collection<int, PredictionFeatureSnapshot> $snapshots
     * @param  Collection<int, array<string, mixed>>  $metadata
     */
    private function resolveArtifact(
        string $sport,
        ?string $modelVersion,
        ?string $featureVersion,
        Collection $snapshots,
        Collection $metadata,
    ): ?ModelArtifact {
        $ids = $this->metadataValues($metadata, ['model_artifact_id', 'artifact_id'])
            ->merge($snapshots->flatMap(
                fn (PredictionFeatureSnapshot $snapshot): array => $snapshot->shadowOutputs()
                    ->pluck('model_artifact_id')
                    ->all(),
            ))
            ->filter()
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values();

        if ($ids->count() === 1) {
            return $this->matchingArtifactQuery($sport, $modelVersion, $featureVersion)
                ->find($ids->first());
        }

        if ($ids->isNotEmpty() || $modelVersion === null || $featureVersion === null) {
            return null;
        }

        return $this->soleOrNull($this->matchingArtifactQuery($sport, $modelVersion, $featureVersion));
    }

    /** @param Collection<int, array<string, mixed>> $metadata */
    private function resolveDataset(
        string $sport,
        ?ModelArtifact $artifact,
        Collection $metadata,
        ?ModelRun $modelRun,
    ): ?DatasetExportManifest {
        $allMetadata = $this->withModelRunMetadata($metadata, $modelRun);
        $ids = $this->metadataValues($allMetadata, ['dataset_export_manifest_id', 'dataset_manifest_id'])
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->count() === 1) {
            return DatasetExportManifest::query()->where('sport', $sport)->find($ids->first());
        }

        if ($ids->isNotEmpty()) {
            return null;
        }

        $publicIds = $this->metadataValues($allMetadata, ['dataset_export_manifest_public_id', 'dataset_manifest_public_id'])
            ->filter()
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values();
        if ($publicIds->count() === 1) {
            return DatasetExportManifest::query()
                ->where('sport', $sport)
                ->where('public_id', $publicIds->first())
                ->first();
        }
        if ($publicIds->isNotEmpty()) {
            return null;
        }

        $hashes = $this->metadataValues($allMetadata, ['dataset_hash', 'dataset_sha256'])
            ->push($artifact?->dataset_hash)
            ->filter(fn (mixed $hash): bool => $this->isSha256($hash))
            ->map(fn (mixed $hash): string => strtolower((string) $hash))
            ->unique()
            ->values();

        return $hashes->count() === 1
            ? $this->soleOrNull(DatasetExportManifest::query()
                ->where('sport', $sport)
                ->where('sha256', $hashes->first()))
            : null;
    }

    /** @param Collection<int, array<string, mixed>> $metadata */
    private function resolveFeatureSchema(
        string $sport,
        ?string $featureVersion,
        Collection $metadata,
        ?ModelRun $modelRun,
        bool $persist,
    ): ?FeatureSchema {
        $allMetadata = $this->withModelRunMetadata($metadata, $modelRun);
        $ids = $this->metadataValues($allMetadata, ['feature_schema_id'])
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->count() === 1) {
            return FeatureSchema::query()->where('sport', $sport)->find($ids->first());
        }

        if ($ids->isNotEmpty() || $featureVersion === null) {
            return null;
        }

        $hashes = $this->metadataValues($allMetadata, ['feature_schema_hash'])
            ->filter(fn (mixed $hash): bool => $this->isSha256($hash))
            ->map(fn (mixed $hash): string => strtolower((string) $hash))
            ->unique()
            ->values();
        $query = FeatureSchema::query()
            ->where('sport', $sport)
            ->where('version', $featureVersion);

        if ($hashes->count() === 1) {
            $schemaHash = $hashes->first();
            $schema = $query->where('schema_hash', $schemaHash)->first();

            if (! $schema && $persist) {
                $schema = FeatureSchema::query()->firstOrCreate([
                    'sport' => $sport,
                    'version' => $featureVersion,
                    'schema_hash' => $schemaHash,
                ], [
                    'source' => 'legacy_prediction_lineage',
                ]);
            }

            return $schema;
        }

        if ($hashes->isNotEmpty()) {
            return null;
        }

        return $this->soleOrNull($query);
    }

    private function matchingArtifactQuery(
        string $sport,
        ?string $modelVersion,
        ?string $featureVersion,
    ): Builder {
        return ModelArtifact::query()
            ->where('sport', $sport)
            ->when($modelVersion !== null, fn (Builder $query) => $query->where('model_version', $modelVersion))
            ->when($featureVersion !== null, fn (Builder $query) => $query->where('feature_version', $featureVersion));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $metadata
     * @param  list<string>  $keys
     * @return Collection<int, mixed>
     */
    private function metadataValues(Collection $metadata, array $keys): Collection
    {
        return $metadata->flatMap(function (array $item) use ($keys): array {
            $values = [];
            foreach ($keys as $key) {
                foreach ([$key, "lineage.{$key}", "provenance.{$key}"] as $path) {
                    $value = data_get($item, $path);
                    if ($value !== null && $value !== '') {
                        $values[] = $value;
                    }
                }
            }

            return $values;
        });
    }

    /** @param Collection<int, array<string, mixed>> $metadata
     * @return Collection<int, array<string, mixed>>
     */
    private function withModelRunMetadata(Collection $metadata, ?ModelRun $modelRun): Collection
    {
        if (! $modelRun) {
            return $metadata;
        }

        return $metadata->push((array) $modelRun->parameters)->push((array) $modelRun->metadata);
    }

    private function soleOrNull(Builder $query): ?Model
    {
        $models = $query->limit(2)->get();

        return $models->count() === 1 ? $models->first() : null;
    }

    private function stringAttribute(Model $model, string $attribute): ?string
    {
        $value = $model->getAttribute($attribute);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/i', $value) === 1;
    }
}
