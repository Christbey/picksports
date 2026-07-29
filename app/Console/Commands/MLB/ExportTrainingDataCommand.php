<?php

namespace App\Console\Commands\MLB;

use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\SnapshotEvaluationProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class ExportTrainingDataCommand extends Command
{
    protected $signature = 'mlb:export-training-data
        {--season=* : Filter rows by one or more seasons}
        {--path=storage/app/ml/mlb_training_data.csv : Output CSV path}
        {--limit=0 : Optional row limit}
        {--include-metadata : Include model metadata JSON column}';

    protected $description = 'Export MLB feature snapshot and evaluation rows as training data';

    public function handle(): int
    {
        $rows = $this->loadRows();

        if ($rows->isEmpty()) {
            $this->warn('No MLB snapshot/evaluation rows found for the selected scope.');

            return self::SUCCESS;
        }

        $path = (string) $this->option('path');
        $absolutePath = str_starts_with($path, '/')
            ? $path
            : base_path($path);

        File::ensureDirectoryExists(dirname($absolutePath));

        $handle = fopen($absolutePath, 'wb');
        if ($handle === false) {
            $this->error("Unable to open export path: {$absolutePath}");

            return self::FAILURE;
        }

        $headers = $rows
            ->flatMap(fn (array $row): array => array_keys($row))
            ->unique()
            ->values()
            ->all();
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (mixed $value): string => is_bool($value)
                    ? ($value ? '1' : '0')
                    : (is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) ?: '' : (string) $value),
                array_map(fn (string $header): mixed => $row[$header] ?? null, $headers)
            ));
        }

        fclose($handle);

        $this->info('MLB training data exported.');
        $this->line('Rows: '.$rows->count());
        $this->line('Path: '.$absolutePath);

        return self::SUCCESS;
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
            ->select(
                'prediction_feature_snapshots.*',
                'pe.actuals as evaluation_actuals',
                'g.season'
            )
            ->orderBy('prediction_feature_snapshots.generated_at');

        $seasons = collect($this->option('season'))
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->map(fn (mixed $value): int => (int) $value)
            ->values();

        if ($seasons->isNotEmpty()) {
            $query->whereIn('g.season', $seasons->all());
        }

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get()->map(function (PredictionFeatureSnapshot $snapshot): array {
            $features = $this->arrayValue($snapshot->features);
            $outputs = $this->arrayValue($snapshot->outputs);
            $actuals = $this->arrayValue($snapshot->evaluation_actuals);
            $projection = app(SnapshotEvaluationProjector::class)->project(
                $outputs,
                $actuals,
                $this->arrayValue($snapshot->market_context),
            );
            $errors = $projection['errors'];
            $marketComparison = $projection['market_comparison'];

            $row = [
                'snapshot_run_id' => $snapshot->snapshot_run_id,
                'model_run_id' => $snapshot->model_run_id,
                'game_id' => $snapshot->game_id,
                'season' => $snapshot->season,
                'prediction_id' => $snapshot->prediction_id,
                'model_version' => $snapshot->model_version,
                'feature_version' => $snapshot->feature_version,
                'blend_version' => $snapshot->blend_version,
                'generated_at' => $snapshot->generated_at?->toIso8601String() ?? '',
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
        });
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
