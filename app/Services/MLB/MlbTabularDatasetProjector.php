<?php

namespace App\Services\MLB;

use App\DataTransferObjects\MLB\TrainingExportSnapshot;
use App\Services\Predictions\SnapshotEvaluationProjector;

/** Preserves the existing MLB target/feature/market projection and units. */
final class MlbTabularDatasetProjector
{
    public function project(TrainingExportSnapshot $export, bool $includeMetadata): ?array
    {
        $snapshot = $export->snapshot;
        $features = $this->arrayValue($snapshot->features);
        $outputs = $this->arrayValue($snapshot->outputs);
        $actuals = $this->arrayValue($snapshot->evaluation_actuals);
        $actualHomeMargin = data_get($actuals, 'actual_spread');
        $actualTotal = data_get($actuals, 'actual_total');

        if (! is_numeric($actualHomeMargin) || ! is_numeric($actualTotal)) {
            return null;
        }

        $targetHomeMargin = (float) $actualHomeMargin;
        $targetTotalPoints = (float) $actualTotal;
        $projection = app(SnapshotEvaluationProjector::class)->project(
            $outputs,
            $actuals,
            $this->arrayValue($snapshot->market_context),
        );
        $errors = $projection['errors'];
        $marketComparison = $projection['market_comparison'];
        $targetHash = $this->stableHash([
            'game_id' => (int) $snapshot->game_id,
            'actuals' => $actuals,
        ]);
        $featureSchemaHash = $this->stableHash([
            'feature_version' => $snapshot->feature_version,
            'feature_keys' => collect(array_keys($features))->sort()->values()->all(),
        ]);

        $row = [
            'canonical_snapshot_id' => $snapshot->id,
            'snapshot_run_id' => $snapshot->snapshot_run_id,
            'model_run_id' => $snapshot->model_run_id,
            'config_hash' => $snapshot->model_run_config_hash,
            'code_version' => $snapshot->model_run_code_version,
            'game_id' => $snapshot->game_id,
            'season' => $snapshot->season,
            'prediction_id' => $snapshot->prediction_id,
            'prediction_horizon' => $export->predictionHorizon,
            'model_version' => $snapshot->model_version,
            'feature_version' => $snapshot->feature_version,
            'blend_version' => $snapshot->blend_version,
            'generated_at' => $snapshot->generated_at?->toIso8601String() ?? '',
            'game_start_at' => $snapshot->game_start_at?->toIso8601String() ?? '',
            'features_available_at' => $snapshot->features_available_at?->toIso8601String() ?? '',
            'evaluated_at' => $snapshot->evaluation_evaluated_at,
            'pregame_safe' => (bool) $snapshot->pregame_safe,
            'availability_status' => $snapshot->availability_status,
            'target_home_win' => $targetHomeMargin > 0 ? 1 : 0,
            'target_home_margin' => $targetHomeMargin,
            'target_total_points' => $targetTotalPoints,
            'source_timestamps' => $this->arrayValue($snapshot->source_timestamps),
            'lineage_metadata' => $this->arrayValue($snapshot->lineage_metadata),
            'feature_hash' => $snapshot->feature_hash,
            'feature_schema_hash' => $featureSchemaHash,
            'target_hash' => $targetHash,
            'row_lineage_hash' => $this->stableHash([
                'snapshot_run_id' => $snapshot->snapshot_run_id,
                'model_run_id' => $snapshot->model_run_id,
                'game_id' => (int) $snapshot->game_id,
                'prediction_horizon' => $export->predictionHorizon,
                'model_version' => $snapshot->model_version,
                'feature_version' => $snapshot->feature_version,
                'blend_version' => $snapshot->blend_version,
                'feature_hash' => $snapshot->feature_hash,
                'target_hash' => $targetHash,
            ]),
        ];

        foreach ($features as $key => $value) {
            $row["feature_{$key}"] = $value;
        }

        foreach ($outputs as $key => $value) {
            $row["output_{$key}"] = $value;
        }

        foreach ($actuals as $key => $value) {
            $row["actual_{$key}"] = $value;
        }

        foreach ($errors as $key => $value) {
            $row["error_{$key}"] = $value;
        }

        foreach ($marketComparison as $key => $value) {
            $row["market_{$key}"] = $value;
        }

        if ($includeMetadata) {
            $row['model_metadata'] = $snapshot->model_metadata ?? [];
        }

        return $row;
    }

    public function stableHash(mixed $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
