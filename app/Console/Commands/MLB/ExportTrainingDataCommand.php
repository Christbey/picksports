<?php

namespace App\Console\Commands\MLB;

use App\Services\MLB\MlbTabularDatasetExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
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

    public function handle(MlbTabularDatasetExporter $exporter): int
    {
        $path = (string) $this->option('path');
        $absolutePath = str_starts_with($path, '/') ? $path : base_path($path);
        try {
            $seasons = [];
            foreach ((array) $this->option('season') as $season) {
                if (! is_string($season) && ! is_int($season) || preg_match('/\A[0-9]{4}\z/D', (string) $season) !== 1) {
                    throw new \RuntimeException('Every explicit season must be a four-digit year.');
                }
                $seasons[] = (int) $season;
            }
            if (count($seasons) > 20 || preg_match('/\A[0-9]+\z/D', (string) $this->option('limit')) !== 1
                || strlen((string) $this->option('limit')) > 5 || (int) $this->option('limit') > 10000) {
                throw new \RuntimeException('MLB export scope exceeds its explicit season/row budget.');
            }
            $proof = $exporter->export($absolutePath, array_values(array_unique($seasons)), (int) $this->option('limit'),
                (bool) $this->option('include-metadata'), (bool) $this->option('include-unsafe'));
            if ($proof['rows'] === 0) {
                if (! $this->removeStaleDestination($absolutePath) || ! $this->removeStaleDestination($absolutePath.'.manifest.json')) {
                    return self::FAILURE;
                }
                $this->warn('No MLB rows met the requested provenance and target requirements.');

                return self::FAILURE;
            }
            $this->info('MLB training data exported.');
            $this->line('Rows: '.$proof['rows']);
            $this->line('Pregame proof required: '.((bool) $this->option('include-unsafe') ? 'no (research override)' : 'yes'));
            $this->line('Dataset schema SHA-256: '.$proof['dataset_schema_hash']);
            $this->line('Dataset SHA-256: '.$proof['dataset_sha256']);
            $this->line('Path: '.$absolutePath);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
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
}
