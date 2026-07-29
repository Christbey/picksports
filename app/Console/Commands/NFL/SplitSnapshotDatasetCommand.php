<?php

namespace App\Console\Commands\NFL;

use App\Services\ML\CsvDataset;
use Illuminate\Console\Command;

class SplitSnapshotDatasetCommand extends Command
{
    protected $signature = 'nfl:split-snapshot-dataset
        {--input=storage/app/ml/nfl_training_data.csv : Source trusted dataset CSV}
        {--output-dir=storage/app/ml/nfl-splits : Output directory}
        {--train=70 : Train percentage}
        {--validation=15 : Validation percentage}
        {--test=15 : Test percentage}';

    protected $description = 'Split the trusted NFL dataset chronologically into train, validation, and test rows';

    public function handle(CsvDataset $csv): int
    {
        $input = $this->absolutePath((string) $this->option('input'));
        $outputDir = $this->absolutePath((string) $this->option('output-dir'));
        $train = (float) $this->option('train');
        $validation = (float) $this->option('validation');
        $test = (float) $this->option('test');

        if (abs($train + $validation + $test - 100.0) > 0.0001 || $train <= 0 || $validation < 0 || $test < 0) {
            $this->error('Train, validation, and test percentages must be non-negative and add up to 100.');

            return self::FAILURE;
        }

        $rows = $csv->read($input);
        if ($rows === []) {
            $this->error("Dataset not found or empty: {$input}");

            return self::FAILURE;
        }

        $splits = $csv->chronologicalSplit($rows, $train, $validation);
        $csv->write($outputDir.'/nfl_snapshot_train.csv', $splits['train']);
        $csv->write($outputDir.'/nfl_snapshot_validation.csv', $splits['validation']);
        $csv->write($outputDir.'/nfl_snapshot_test.csv', $splits['test']);

        $this->info('NFL chronological split completed.');
        $this->line('Train rows: '.count($splits['train']));
        $this->line('Validation rows: '.count($splits['validation']));
        $this->line('Test rows: '.count($splits['test']));

        return self::SUCCESS;
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
