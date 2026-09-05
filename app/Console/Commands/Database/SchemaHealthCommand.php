<?php

namespace App\Console\Commands\Database;

use App\Services\Database\DatabaseFingerprint;
use App\Services\Database\SchemaHealthInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;
use Throwable;

class SchemaHealthCommand extends Command
{
    protected $signature = 'db:schema-health
        {--connection= : Database connection to inspect}
        {--against= : Fingerprint JSON file to use as the required baseline}
        {--expected-database= : Fail unless the connected database has this exact name}
        {--minimum-mysql= : Fail unless using MySQL at or above this version}
        {--check-row-counts : Compare exact row counts; requires an exact-count baseline}
        {--json : Output machine-readable JSON}';

    protected $description = 'Validate database identity, migration state, schema constraints, and optional row counts.';

    public function handle(DatabaseFingerprint $fingerprint, SchemaHealthInspector $inspector): int
    {
        if ((bool) $this->option('check-row-counts') && $this->stringOption('against') === null) {
            $this->error('The --check-row-counts option requires an --against baseline fingerprint.');

            return self::FAILURE;
        }

        try {
            $baseline = $this->baseline();
            $current = $fingerprint->capture(
                $this->option('connection') ?: null,
                (bool) $this->option('check-row-counts'),
            );
            $result = $inspector->inspect(
                current: $current,
                baseline: $baseline,
                expectedDatabase: $this->stringOption('expected-database'),
                minimumMysqlVersion: $this->stringOption('minimum-mysql'),
                checkRowCounts: (bool) $this->option('check-row-counts'),
            );

            if ((bool) $this->option('json')) {
                $this->output->writeln(json_encode([
                    'healthy' => $result['healthy'],
                    'database' => $current['database'],
                    'driver' => $current['driver'],
                    'fingerprint' => $current['fingerprint'],
                    'checks' => $result['checks'],
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->table(
                    ['Check', 'Status', 'Message'],
                    collect($result['checks'])->map(fn (array $check): array => [
                        $check['name'],
                        strtoupper($check['status']),
                        $check['message'],
                    ])->all(),
                );
            }

            return $result['healthy'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error(sprintf('Unable to validate schema health: %s', $exception->getMessage()));

            return self::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws JsonException
     */
    private function baseline(): ?array
    {
        $path = $this->stringOption('against');
        if ($path === null) {
            return null;
        }

        $resolvedPath = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        if (! File::isFile($resolvedPath)) {
            throw new JsonException(sprintf('Baseline fingerprint not found at %s.', $resolvedPath));
        }

        $decoded = json_decode(File::get($resolvedPath), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new JsonException('Baseline fingerprint must contain a JSON object.');
        }

        return $decoded;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
