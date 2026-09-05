<?php

namespace App\Services\Predictions;

use App\Models\CanonicalPrediction;

final class CanonicalPredictionLineageReadinessService
{
    private const LINKS = [
        'sport_event' => 'sport_event_id',
        'feature_schema' => 'feature_schema_id',
        'dataset_export_manifest' => 'dataset_export_manifest_id',
        'model_run' => 'model_run_id',
        'model_artifact' => 'model_artifact_id',
    ];

    /**
     * @param  list<string>  $sports
     * @return array<string, mixed>
     */
    public function report(array $sports = [], int $limit = 0): array
    {
        $query = CanonicalPrediction::query()
            ->with(['sportEvent', 'featureSchema', 'datasetExportManifest', 'modelRun', 'modelArtifact'])
            ->orderBy('id');

        if ($sports !== []) {
            $query->whereIn('sport', $sports);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $missing = array_fill_keys(array_keys(self::LINKS), 0);
        $mismatches = array_fill_keys([
            'sport_event',
            'feature_schema',
            'dataset_export_manifest',
            'model_run',
            'model_artifact',
            'artifact_dataset',
        ], 0);
        $total = 0;
        $ready = 0;
        $samples = [];

        $query->each(function (CanonicalPrediction $prediction) use (&$missing, &$mismatches, &$ready, &$samples, &$total): void {
            $total++;
            $rowMissing = [];
            foreach (self::LINKS as $name => $column) {
                if ($prediction->getAttribute($column) === null) {
                    $missing[$name]++;
                    $rowMissing[] = $name;
                }
            }

            $rowMismatches = $this->mismatches($prediction);
            foreach ($rowMismatches as $mismatch) {
                $mismatches[$mismatch]++;
            }

            if ($rowMissing === [] && $rowMismatches === []) {
                $ready++;

                return;
            }

            if (count($samples) < 25) {
                $samples[] = [
                    'public_id' => $prediction->public_id,
                    'sport' => $prediction->sport,
                    'detail_id' => $prediction->detail_id,
                    'missing' => $rowMissing,
                    'mismatches' => $rowMismatches,
                ];
            }
        });

        return [
            'total' => $total,
            'ready' => $ready,
            'incomplete' => $total - $ready,
            'ready_percentage' => $total === 0 ? 100.0 : round(($ready / $total) * 100, 2),
            'missing' => $missing,
            'mismatches' => $mismatches,
            'samples' => $samples,
        ];
    }

    /** @return list<string> */
    private function mismatches(CanonicalPrediction $prediction): array
    {
        $mismatches = [];

        if ($prediction->sportEvent && $prediction->sportEvent->sport !== $prediction->sport) {
            $mismatches[] = 'sport_event';
        }
        if ($prediction->featureSchema && (
            $prediction->featureSchema->sport !== $prediction->sport
            || ($prediction->feature_version !== null && $prediction->featureSchema->version !== $prediction->feature_version)
        )) {
            $mismatches[] = 'feature_schema';
        }
        if ($prediction->datasetExportManifest
            && $prediction->datasetExportManifest->sport !== $prediction->sport) {
            $mismatches[] = 'dataset_export_manifest';
        }
        if ($prediction->modelRun && ! $this->versionsMatch($prediction, $prediction->modelRun)) {
            $mismatches[] = 'model_run';
        }
        if ($prediction->modelArtifact && ! $this->versionsMatch($prediction, $prediction->modelArtifact, false)) {
            $mismatches[] = 'model_artifact';
        }
        if ($prediction->modelArtifact && $prediction->datasetExportManifest
            && strtolower($prediction->modelArtifact->dataset_hash) !== strtolower($prediction->datasetExportManifest->sha256)) {
            $mismatches[] = 'artifact_dataset';
        }

        return $mismatches;
    }

    private function versionsMatch(
        CanonicalPrediction $prediction,
        object $lineage,
        bool $compareBlend = true,
    ): bool {
        if ($lineage->sport !== $prediction->sport) {
            return false;
        }

        foreach (['model_version', 'feature_version'] as $attribute) {
            if ($prediction->getAttribute($attribute) !== null
                && $lineage->{$attribute} !== $prediction->getAttribute($attribute)) {
                return false;
            }
        }

        return ! $compareBlend
            || $prediction->blend_version === null
            || $lineage->blend_version === $prediction->blend_version;
    }
}
