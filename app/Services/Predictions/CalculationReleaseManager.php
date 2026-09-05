<?php

namespace App\Services\Predictions;

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CalculationRelease;
use App\Models\DatasetExportManifest;
use App\Models\FeatureSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CalculationReleaseManager
{
    public function __construct(private readonly CanonicalPayloadHasher $hasher) {}

    public function approve(
        CalculationRelease $release,
        string $actor,
        string $reason,
        ?CarbonImmutable $effectiveAt = null,
    ): CalculationRelease {
        if (blank($actor) || blank($reason)) {
            throw new PredictionLifecycleException('Calculation release approval requires an actor and reason.');
        }

        return DB::transaction(function () use ($release, $actor, $reason, $effectiveAt): CalculationRelease {
            $release = CalculationRelease::query()->lockForUpdate()->findOrFail($release->getKey());

            if ($release->status !== 'draft') {
                throw new PredictionLifecycleException('Only draft calculation releases can be approved.');
            }

            if (! hash_equals($release->configuration_hash, $this->hasher->hash($release->configuration ?? []))) {
                throw new PredictionLifecycleException('Calculation release configuration hash does not match its frozen configuration.');
            }

            if (blank($release->code_revision) || blank($release->input_schema_version)) {
                throw new PredictionLifecycleException('Calculation releases require code and input-schema versions.');
            }

            if (in_array($release->release_type, ['ml', 'hybrid'], true)) {
                $this->assertMachineLearningEvidence($release);
            }

            $release->update([
                'status' => 'approved',
                'effective_at' => $effectiveAt ?? now(),
                'approved_at' => now(),
                'approved_by' => trim($actor),
                'approval_reason' => trim($reason),
            ]);

            return $release->fresh(['components.modelArtifact']);
        });
    }

    public function retire(CalculationRelease $release, ?CarbonImmutable $retiredAt = null): CalculationRelease
    {
        return DB::transaction(function () use ($release, $retiredAt): CalculationRelease {
            $release = CalculationRelease::query()->lockForUpdate()->findOrFail($release->getKey());

            if ($release->status !== 'approved') {
                throw new PredictionLifecycleException('Only approved calculation releases can be retired.');
            }

            $release->update([
                'status' => 'retired',
                'retired_at' => $retiredAt ?? now(),
            ]);

            return $release->fresh();
        });
    }

    public function invalidate(CalculationRelease $release, string $reason): CalculationRelease
    {
        if (blank($reason)) {
            throw new PredictionLifecycleException('Calculation release invalidation requires a reason.');
        }

        return DB::transaction(function () use ($release, $reason): CalculationRelease {
            $release = CalculationRelease::query()->lockForUpdate()->findOrFail($release->getKey());

            if (! in_array($release->status, ['approved', 'retired'], true)) {
                throw new PredictionLifecycleException('Only approved or retired calculation releases can be invalidated.');
            }

            $release->update([
                'status' => 'invalidated',
                'invalidated_at' => now(),
                'invalidation_reason' => trim($reason),
            ]);

            return $release->fresh();
        });
    }

    private function assertMachineLearningEvidence(CalculationRelease $release): void
    {
        $components = $release->components()->with('modelArtifact.trainingRun')->get();
        $modelComponents = $components->whereIn('component_type', ['ml', 'model']);

        if ($modelComponents->isEmpty()) {
            throw new PredictionLifecycleException('ML and hybrid releases require at least one model component.');
        }

        foreach ($modelComponents as $component) {
            $artifact = $component->modelArtifact;

            if ($artifact === null
                || $artifact->status !== 'promoted'
                || blank($artifact->artifact_hash)
                || blank($artifact->dataset_hash)
                || blank($artifact->evaluation_report_hash)
                || $artifact->trainingRun === null) {
                throw new PredictionLifecycleException('Every ML release component requires a promoted artifact with training, dataset, artifact, and evaluation evidence.');
            }

            $hasFeatureSchema = FeatureSchema::query()
                ->where('sport', $release->sport)
                ->where('version', $artifact->feature_version)
                ->exists();
            $hasDataset = DatasetExportManifest::query()
                ->where('sport', $release->sport)
                ->where('sha256', $artifact->dataset_hash)
                ->exists();

            if (! $hasFeatureSchema || ! $hasDataset) {
                throw new PredictionLifecycleException('ML release artifacts must resolve to registered feature schemas and dataset manifests.');
            }
        }
    }
}
