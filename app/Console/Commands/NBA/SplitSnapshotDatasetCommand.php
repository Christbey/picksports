<?php

namespace App\Console\Commands\NBA;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SplitSnapshotDatasetCommand extends Command
{
    protected $signature = 'nba:split-snapshot-dataset
        {--input=storage/app/ml/nba_snapshot_dataset.csv : Source dataset CSV}
        {--output-dir=storage/app/ml/splits : Output directory for split CSV files}
        {--train=70 : Train percentage}
        {--validation=15 : Validation percentage}
        {--test=15 : Test percentage}';

    protected $description = 'Split an NBA snapshot dataset into chronological train/validation/test CSV files';

    public function handle(): int
    {
        $inputPath = $this->absolutePath((string) $this->option('input'));
        $outputDir = $this->absolutePath((string) $this->option('output-dir'));

        if (! File::exists($inputPath)) {
            $this->error("Input dataset not found: {$inputPath}");

            return self::FAILURE;
        }

        $ratios = $this->validatedRatios();
        if ($ratios === null) {
            return self::FAILURE;
        }

        $rows = $this->readCsv($inputPath);
        if ($rows['header'] === [] || $rows['rows'] === []) {
            $this->warn('Input dataset is empty.');

            return self::SUCCESS;
        }

        usort($rows['rows'], fn (array $left, array $right): int => [
            $left['game_start_at'] ?? $left['game_date'] ?? '',
            $left['game_id'] ?? '',
        ] <=> [
            $right['game_start_at'] ?? $right['game_date'] ?? '',
            $right['game_id'] ?? '',
        ]);

        $splits = $this->splitRows($rows['rows'], $ratios['train'], $ratios['validation']);

        File::ensureDirectoryExists($outputDir);

        $trainPath = $outputDir.'/nba_snapshot_train.csv';
        $validationPath = $outputDir.'/nba_snapshot_validation.csv';
        $testPath = $outputDir.'/nba_snapshot_test.csv';

        $this->writeCsv($trainPath, $rows['header'], $splits['train']);
        $this->writeCsv($validationPath, $rows['header'], $splits['validation']);
        $this->writeCsv($testPath, $rows['header'], $splits['test']);

        $this->info('NBA snapshot dataset split completed.');
        $this->line('Train rows: '.count($splits['train']));
        $this->line('Validation rows: '.count($splits['validation']));
        $this->line('Test rows: '.count($splits['test']));
        $this->line('Output directory: '.$outputDir);

        return self::SUCCESS;
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/')
            ? $path
            : base_path($path);
    }

    /**
     * @return array{train: float, validation: float, test: float}|null
     */
    private function validatedRatios(): ?array
    {
        $train = (float) $this->option('train');
        $validation = (float) $this->option('validation');
        $test = (float) $this->option('test');
        $sum = $train + $validation + $test;

        if ($train <= 0 || $validation < 0 || $test < 0) {
            $this->error('Split percentages must be non-negative, and train must be greater than zero.');

            return null;
        }

        if (abs($sum - 100.0) > 0.0001) {
            $this->error('Train, validation, and test percentages must add up to 100.');

            return null;
        }

        return [
            'train' => $train,
            'validation' => $validation,
            'test' => $test,
        ];
    }

    /**
     * @return array{header: array<int, string>, rows: array<int, array<string, string>>}
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['header' => [], 'rows' => []];
        }

        $header = fgetcsv($handle);
        if (! is_array($header)) {
            fclose($handle);

            return ['header' => [], 'rows' => []];
        }

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = array_combine($header, array_pad($row, count($header), '')) ?: [];
        }

        fclose($handle);

        return [
            'header' => $header,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array{train: array<int, array<string, string>>, validation: array<int, array<string, string>>, test: array<int, array<string, string>>}
     */
    private function splitRows(array $rows, float $trainPercent, float $validationPercent): array
    {
        $count = count($rows);
        $trainCount = (int) floor($count * ($trainPercent / 100));
        $validationCount = (int) floor($count * ($validationPercent / 100));
        $testCount = $count - $trainCount - $validationCount;

        if ($count >= 3 && $validationCount === 0 && $validationPercent > 0) {
            $validationCount = 1;
            $trainCount = max(1, $trainCount - 1);
            $testCount = $count - $trainCount - $validationCount;
        }

        if ($count >= 3 && $testCount === 0) {
            $testCount = 1;
            $trainCount = max(1, $trainCount - 1);
        }

        return [
            'train' => array_slice($rows, 0, $trainCount),
            'validation' => array_slice($rows, $trainCount, $validationCount),
            'test' => array_slice($rows, $trainCount + $validationCount),
        ];
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, array<string, string>>  $rows
     */
    private function writeCsv(string $path, array $header, array $rows): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to write CSV file: {$path}");
        }

        fputcsv($handle, $header);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (string $column): string => $row[$column] ?? '',
                $header
            ));
        }

        fclose($handle);
    }
}
