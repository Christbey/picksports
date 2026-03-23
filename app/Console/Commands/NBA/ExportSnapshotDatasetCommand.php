<?php

namespace App\Console\Commands\NBA;

use App\Services\NBA\HistoricalSnapshotQueryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExportSnapshotDatasetCommand extends Command
{
    protected $signature = 'nba:export-snapshot-dataset
        {--season= : Filter rows by season}
        {--path=storage/app/ml/nba_snapshot_dataset.csv : Output CSV path}
        {--limit=0 : Optional row limit}
        {--include-identifiers : Include internal ids in the export}';

    protected $description = 'Export an ML-ready NBA dataset from the read-only nba_snapshot connection';

    public function handle(HistoricalSnapshotQueryService $snapshotService): int
    {
        $season = $this->option('season') !== null ? (int) $this->option('season') : null;
        $limit = max(0, (int) $this->option('limit'));

        $rows = $snapshotService->trainingRows($season, $limit)
            ->map(fn (array $row): array => $this->transformRow($row))
            ->values();

        if ($rows->isEmpty()) {
            $this->warn('No final NBA snapshot rows found for the selected scope.');

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

        $headers = array_keys($rows->first());
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (mixed $value): string => is_bool($value) ? ($value ? '1' : '0') : (string) ($value ?? ''),
                $row
            ));
        }

        fclose($handle);

        $summary = $snapshotService->datasetSummary($season);

        $this->info('NBA snapshot dataset exported.');
        $this->line('Rows: '.$rows->count());
        $this->line('Path: '.$absolutePath);
        $this->line('Date range: '.($summary['first_game_date'] ?? 'N/A').' -> '.($summary['last_game_date'] ?? 'N/A'));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, bool|float|int|string|null>
     */
    private function transformRow(array $row): array
    {
        $modelSpread = $this->floatValue($row['predicted_spread']);
        $marketSpread = $this->floatValue($row['vegas_spread']);
        $derivedMargin = $this->floatValue($row['derived_actual_spread']);
        $derivedTotal = $this->floatValue($row['derived_actual_total']);
        $winProbability = $this->floatValue($row['win_probability']);

        $datasetRow = [
            'season' => $row['season'],
            'game_date' => $row['game_date'],
            'home_team' => $row['home_team_abbreviation'],
            'away_team' => $row['away_team_abbreviation'],
            'model_version' => $row['model_version'],
            'feature_version' => $row['feature_version'],
            'blend_version' => $row['blend_version'],
            'feature_home_elo' => $this->floatValue($row['home_elo']),
            'feature_away_elo' => $this->floatValue($row['away_elo']),
            'feature_elo_diff' => $this->difference($row['home_elo'], $row['away_elo']),
            'feature_home_recent_form' => $this->floatValue($row['home_recent_form']),
            'feature_away_recent_form' => $this->floatValue($row['away_recent_form']),
            'feature_recent_form_diff' => $this->difference($row['home_recent_form'], $row['away_recent_form']),
            'feature_rest_days_home' => $row['rest_days_home'],
            'feature_rest_days_away' => $row['rest_days_away'],
            'feature_rest_day_diff' => $this->difference($row['rest_days_home'], $row['rest_days_away']),
            'feature_injury_spread_adj' => $this->floatValue($row['injury_spread_adj']),
            'feature_injury_total_adj' => $this->floatValue($row['injury_total_adj']),
            'feature_market_home_spread' => $marketSpread,
            'feature_model_predicted_spread' => $modelSpread,
            'feature_model_predicted_total' => $this->floatValue($row['predicted_total']),
            'feature_model_win_probability' => $winProbability,
            'feature_confidence_score' => $this->floatValue($row['confidence_score']),
            'target_home_margin' => $derivedMargin,
            'target_total_points' => $derivedTotal,
            'target_home_win' => $derivedMargin === null ? null : $derivedMargin > 0,
            'target_model_spread_error' => $this->resolvedSpreadError($row),
            'target_model_total_error' => $this->resolvedTotalError($row),
            'target_market_spread_error' => $this->absoluteDifference($derivedMargin, $marketSpread),
            'target_model_edge_vs_market' => $this->absoluteDifference($modelSpread, $marketSpread),
        ];

        if ($this->option('include-identifiers')) {
            $datasetRow = [
                'prediction_id' => $row['prediction_id'],
                'game_id' => $row['game_id'],
                'espn_event_id' => $row['espn_event_id'],
                'home_team_id' => $row['home_team_id'],
                'away_team_id' => $row['away_team_id'],
                ...$datasetRow,
            ];
        }

        return $datasetRow;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolvedSpreadError(array $row): ?float
    {
        if ($row['spread_error'] !== null) {
            return $this->floatValue($row['spread_error']);
        }

        return $this->absoluteDifference($row['derived_actual_spread'], $row['predicted_spread']);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolvedTotalError(array $row): ?float
    {
        if ($row['total_error'] !== null) {
            return $this->floatValue($row['total_error']);
        }

        return $this->absoluteDifference($row['derived_actual_total'], $row['predicted_total']);
    }

    private function difference(mixed $left, mixed $right): ?float
    {
        $leftValue = $this->floatValue($left);
        $rightValue = $this->floatValue($right);

        if ($leftValue === null || $rightValue === null) {
            return null;
        }

        return $leftValue - $rightValue;
    }

    private function absoluteDifference(mixed $left, mixed $right): ?float
    {
        $leftValue = $this->floatValue($left);
        $rightValue = $this->floatValue($right);

        if ($leftValue === null || $rightValue === null) {
            return null;
        }

        return abs($leftValue - $rightValue);
    }

    private function floatValue(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
