<?php

namespace App\Console\Commands\MLB;

use App\Models\PredictionFeatureSnapshot;
use App\Services\ML\CsvDataset;
use App\Services\Predictions\SnapshotEvaluationProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class ExportTrainingDataCommand extends Command
{
    protected $signature = 'mlb:export-training-data
        {--season=* : Filter rows by one or more seasons}
        {--path=storage/app/ml/mlb_training_data.csv : Output CSV path}
        {--limit=0 : Optional row limit}
        {--include-metadata : Include model metadata JSON column}
        {--include-unsafe : Research only: include snapshots without verified point-in-time trust}';

    protected $description = 'Export one canonical, target-stable MLB snapshot per game as training data';

    public function handle(CsvDataset $csv): int
    {
        $path = (string) $this->option('path');
        $absolutePath = str_starts_with($path, '/')
            ? $path
            : base_path($path);
        $rows = $this->loadRows();

        if ($rows->isEmpty()) {
            if (! $this->removeStaleDestination($absolutePath)) {
                return self::FAILURE;
            }

            $this->warn('No MLB rows met the requested provenance and target requirements.');

            return self::FAILURE;
        }

        $datasetSchemaHash = $this->datasetSchemaHash($rows);
        $rows = $rows->map(fn (array $row): array => [
            ...$row,
            'dataset_schema_hash' => $datasetSchemaHash,
        ]);

        try {
            $csv->write($absolutePath, $rows);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('MLB training data exported.');
        $this->line('Rows: '.$rows->count());
        $this->line('Pregame proof required: '.((bool) $this->option('include-unsafe') ? 'no (research override)' : 'yes'));
        $this->line('Dataset schema SHA-256: '.$datasetSchemaHash);
        $this->line('Dataset SHA-256: '.hash_file('sha256', $absolutePath));
        $this->line('Path: '.$absolutePath);

        return self::SUCCESS;
    }

    private function removeStaleDestination(string $path): bool
    {
        try {
            if (! File::exists($path) || File::delete($path)) {
                return true;
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return false;
        }

        $this->error("Unable to remove stale export path: {$path}");

        return false;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function loadRows(): Collection
    {
        $query = PredictionFeatureSnapshot::query()
            ->where('prediction_feature_snapshots.sport', 'mlb')
            ->where('prediction_feature_snapshots.prediction_table', 'mlb_predictions')
            ->join('prediction_evaluations as pe', function ($join): void {
                $join->on('prediction_feature_snapshots.prediction_table', '=', 'pe.prediction_table')
                    ->on('prediction_feature_snapshots.prediction_id', '=', 'pe.prediction_id')
                    ->on('prediction_feature_snapshots.model_version', '=', 'pe.model_version')
                    ->on('prediction_feature_snapshots.feature_version', '=', 'pe.feature_version')
                    ->on('prediction_feature_snapshots.blend_version', '=', 'pe.blend_version');
            })
            ->join('mlb_games as g', 'prediction_feature_snapshots.game_id', '=', 'g.id')
            ->leftJoin('model_runs as mr', 'prediction_feature_snapshots.model_run_id', '=', 'mr.id')
            ->select(
                'prediction_feature_snapshots.*',
                'pe.actuals as evaluation_actuals',
                'pe.evaluated_at as evaluation_evaluated_at',
                'g.season',
                'mr.config_hash as model_run_config_hash',
                'mr.code_version as model_run_code_version'
            );

        $seasons = collect($this->option('season'))
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->map(fn (mixed $value): int => (int) $value)
            ->values();

        if ($seasons->isNotEmpty()) {
            $query->whereIn('g.season', $seasons->all());
        }

        if (! (bool) $this->option('include-unsafe')) {
            $query
                ->where('prediction_feature_snapshots.pregame_safe', true)
                ->whereNotNull('prediction_feature_snapshots.game_start_at')
                ->whereNotNull('prediction_feature_snapshots.features_available_at')
                ->whereColumn(
                    'prediction_feature_snapshots.features_available_at',
                    '<=',
                    'prediction_feature_snapshots.game_start_at'
                )
                ->where(function ($trustQuery): void {
                    $trustQuery
                        ->where(function ($observedQuery): void {
                            $observedQuery
                                ->where('prediction_feature_snapshots.availability_status', 'observed_pregame')
                                ->whereColumn(
                                    'prediction_feature_snapshots.generated_at',
                                    '<=',
                                    'prediction_feature_snapshots.game_start_at'
                                );
                        })
                        ->orWhere(function ($reconstructionQuery): void {
                            $reconstructionQuery
                                ->where('prediction_feature_snapshots.availability_status', 'verified_reconstruction')
                                ->where(
                                    'prediction_feature_snapshots.lineage_metadata->point_in_time_verified',
                                    true
                                );
                        });
                });
        }

        $canonicalSnapshots = collect();

        foreach ($query
            ->orderBy('prediction_feature_snapshots.generated_at')
            ->orderBy('prediction_feature_snapshots.id')
            ->cursor() as $snapshot) {
            $horizon = $this->predictionHorizon($snapshot);
            $canonicalKey = (string) $snapshot->game_id;
            $current = $canonicalSnapshots->get($canonicalKey);

            if (
                ! $current instanceof PredictionFeatureSnapshot
                || $this->isLaterSnapshot($snapshot, $current)
            ) {
                $snapshot->setAttribute('prediction_horizon', $horizon);
                $canonicalSnapshots->put($canonicalKey, $snapshot);
            }
        }

        $limit = max(0, (int) $this->option('limit'));
        $canonicalSnapshots = $canonicalSnapshots
            ->sortBy(fn (PredictionFeatureSnapshot $snapshot): array => [
                $snapshot->game_start_at?->toIso8601String()
                    ?? $snapshot->generated_at?->toIso8601String()
                    ?? '',
                (int) $snapshot->game_id,
                (string) $snapshot->prediction_horizon,
            ])
            ->values();

        if ($limit > 0) {
            $canonicalSnapshots = $canonicalSnapshots->take($limit)->values();
        }

        return $canonicalSnapshots->map(function (PredictionFeatureSnapshot $snapshot): ?array {
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
                'prediction_horizon' => $snapshot->prediction_horizon,
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
                    'prediction_horizon' => $snapshot->prediction_horizon,
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

            if ($this->option('include-metadata')) {
                $row['model_metadata'] = $snapshot->model_metadata ?? [];
            }

            return $row;
        })->filter()->values();
    }

    private function predictionHorizon(PredictionFeatureSnapshot $snapshot): string
    {
        $lineage = $this->arrayValue($snapshot->lineage_metadata);
        $metadata = $this->arrayValue($snapshot->model_metadata);
        $horizon = data_get($lineage, 'prediction_horizon')
            ?? data_get($metadata, 'prediction_horizon')
            ?? data_get($lineage, 'horizon')
            ?? data_get($metadata, 'horizon');

        if (is_string($horizon) && trim($horizon) !== '') {
            return (string) Str::of($horizon)
                ->lower()
                ->replaceMatches('/[^a-z0-9]+/', '_')
                ->trim('_');
        }

        return $snapshot->availability_status === 'verified_reconstruction'
            ? 'historical_reconstruction'
            : 'pregame';
    }

    private function isLaterSnapshot(
        PredictionFeatureSnapshot $candidate,
        PredictionFeatureSnapshot $current,
    ): bool {
        return [
            $candidate->features_available_at?->getTimestamp() ?? PHP_INT_MIN,
            $candidate->generated_at?->getTimestamp() ?? PHP_INT_MIN,
            (int) $candidate->id,
        ] > [
            $current->features_available_at?->getTimestamp() ?? PHP_INT_MIN,
            $current->generated_at?->getTimestamp() ?? PHP_INT_MIN,
            (int) $current->id,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function datasetSchemaHash(Collection $rows): string
    {
        return $this->stableHash(
            $rows
                ->flatMap(fn (array $row): array => array_keys($row))
                ->unique()
                ->sort()
                ->values()
                ->all()
        );
    }

    private function stableHash(mixed $value): string
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
