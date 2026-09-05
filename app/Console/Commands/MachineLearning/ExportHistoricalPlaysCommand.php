<?php

namespace App\Console\Commands\MachineLearning;

use App\Services\MachineLearning\HistoricalPlayArchiveExporter;
use Illuminate\Console\Command;
use Throwable;

class ExportHistoricalPlaysCommand extends Command
{
    protected $signature = 'ml:export-historical-plays
        {sport : Sport key (cbb, cfb, mlb, nba, nfl, wcbb, or wnba)}
        {season : Season to export}
        {--format=jsonl : jsonl, or parquet when PyArrow is installed}
        {--disk= : Private filesystem disk; defaults to ml.storage.disk}
        {--prefix= : Object-key prefix; defaults to ml.storage.prefix}
        {--chunk=1000 : Rows streamed from the database at a time}';

    protected $description = 'Export an immutable, private historical-play partition without deleting source rows';

    public function handle(HistoricalPlayArchiveExporter $exporter): int
    {
        try {
            $manifest = $exporter->export(
                sport: (string) $this->argument('sport'),
                season: (int) $this->argument('season'),
                format: (string) $this->option('format'),
                diskName: $this->nullableOption('disk'),
                prefix: $this->nullableOption('prefix'),
                chunkSize: (int) $this->option('chunk'),
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Historical play partition exported.');
        $this->table(
            ['Field', 'Value'],
            [
                ['URI', $manifest->uri],
                ['Manifest', $manifest->manifest_key],
                ['Format', $manifest->format],
                ['Rows', number_format($manifest->row_count)],
                ['SHA-256', $manifest->sha256],
                ['Schema SHA-256', $manifest->schema_hash],
            ],
        );

        return self::SUCCESS;
    }

    private function nullableOption(string $name): ?string
    {
        $value = trim((string) $this->option($name));

        return $value === '' ? null : $value;
    }
}
