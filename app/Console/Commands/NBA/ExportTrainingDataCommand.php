<?php

namespace App\Console\Commands\NBA;

use App\Services\ML\CsvDataset;
use App\Services\ML\TrustedSnapshotDataset;
use Illuminate\Console\Command;

class ExportTrainingDataCommand extends Command
{
    protected $signature = 'nba:export-training-data
        {--season= : Filter rows by season}
        {--path=storage/app/ml/nba_training_data.csv : Output CSV path}
        {--limit=0 : Optional row limit}
        {--profile= : Restrict to a historical reconstruction profile}
        {--include-unverified : Include rows that cannot prove pregame availability}';

    protected $description = 'Export one target-stable NBA training row per frozen point-in-time snapshot';

    public function handle(TrustedSnapshotDataset $dataset, CsvDataset $csv): int
    {
        $rows = $dataset->rows(
            sport: 'nba',
            season: $this->option('season') !== null ? (int) $this->option('season') : null,
            strictPregame: ! (bool) $this->option('include-unverified'),
            historicalProfile: $this->option('profile') ? (string) $this->option('profile') : null,
            limit: max(0, (int) $this->option('limit')),
        );

        if ($rows->isEmpty()) {
            $this->warn('No NBA rows met the requested provenance and target requirements.');

            return self::SUCCESS;
        }

        $path = $this->absolutePath((string) $this->option('path'));
        $count = $csv->write($path, $rows);

        $this->info('NBA trusted training data exported.');
        $this->line("Rows: {$count}");
        $this->line('Pregame proof required: '.((bool) $this->option('include-unverified') ? 'no' : 'yes'));
        $this->line('Dataset SHA-256: '.hash_file('sha256', $path));
        $this->line("Path: {$path}");

        return self::SUCCESS;
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
