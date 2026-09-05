<?php

namespace App\Services\Database;

class SchemaHealthInspector
{
    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $baseline
     * @return array{healthy: bool, checks: array<int, array{name: string, status: string, message: string}>}
     */
    public function inspect(
        array $current,
        ?array $baseline = null,
        ?string $expectedDatabase = null,
        ?string $minimumMysqlVersion = null,
        bool $checkRowCounts = false,
    ): array {
        $checks = [];

        $this->add($checks, 'connection', true, sprintf(
            'Connected to %s using %s.',
            $current['database'],
            $current['driver'],
        ));

        if ($expectedDatabase !== null) {
            $matches = hash_equals($expectedDatabase, (string) $current['database']);
            $this->add(
                $checks,
                'database_name',
                $matches,
                $matches
                    ? sprintf('Database name matches %s.', $expectedDatabase)
                    : sprintf('Expected database %s; connected to %s.', $expectedDatabase, $current['database']),
            );
        }

        $hasMigrationTable = collect($current['tables'])->contains(
            fn (array $table): bool => $table['name'] === 'migrations',
        );
        $this->add(
            $checks,
            'migration_repository',
            $hasMigrationTable,
            $hasMigrationTable ? 'Migration repository is present.' : 'Migration repository is missing.',
        );

        $pending = $current['migration_state']['pending'];
        $this->add(
            $checks,
            'pending_migrations',
            $pending === [],
            $pending === []
                ? 'No migrations on disk are pending.'
                : sprintf('%d migration(s) are pending: %s', count($pending), implode(', ', array_slice($pending, 0, 5))),
        );

        if ($minimumMysqlVersion !== null) {
            $isMysql = $current['driver'] === 'mysql';
            $meetsMinimum = $isMysql && version_compare($this->numericVersion((string) $current['server_version']), $minimumMysqlVersion, '>=');
            $this->add(
                $checks,
                'mysql_version',
                $meetsMinimum,
                $meetsMinimum
                    ? sprintf('Database server meets the minimum version %s.', $minimumMysqlVersion)
                    : sprintf('Expected MySQL server >= %s; found %s %s.', $minimumMysqlVersion, $current['driver'], $current['server_version']),
            );
        }

        if ($baseline !== null) {
            $this->compareBaseline($checks, $current, $baseline, $checkRowCounts);
        }

        return [
            'healthy' => collect($checks)->doesntContain(fn (array $check): bool => $check['status'] === 'fail'),
            'checks' => $checks,
        ];
    }

    /**
     * @param  array<int, array{name: string, status: string, message: string}>  $checks
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $baseline
     */
    private function compareBaseline(array &$checks, array $current, array $baseline, bool $checkRowCounts): void
    {
        $formatMatches = ($baseline['format_version'] ?? null) === DatabaseFingerprint::FORMAT_VERSION;
        $this->add(
            $checks,
            'baseline_format',
            $formatMatches,
            $formatMatches ? 'Baseline format is supported.' : 'Baseline format is missing or unsupported.',
        );

        if (! $formatMatches) {
            return;
        }

        $driverMatches = ($baseline['driver'] ?? null) === $current['driver'];
        $this->add(
            $checks,
            'driver',
            $driverMatches,
            $driverMatches
                ? sprintf('Driver matches baseline (%s).', $current['driver'])
                : sprintf('Driver mismatch: baseline %s, current %s.', $baseline['driver'] ?? 'unknown', $current['driver']),
        );

        $migrationMatches = ($baseline['migration_hash'] ?? null) === $current['migration_hash'];
        $this->add(
            $checks,
            'migrations',
            $migrationMatches,
            $migrationMatches
                ? sprintf('All %d applied migrations match the baseline.', count($current['migrations']))
                : $this->migrationMismatchMessage($current, $baseline),
        );

        $schemaMatches = ($baseline['schema_hash'] ?? null) === $current['schema_hash'];
        $this->add(
            $checks,
            'schema',
            $schemaMatches,
            $schemaMatches
                ? sprintf('All %d table definitions and constraints match the baseline.', count($current['tables']))
                : $this->schemaMismatchMessage($current, $baseline),
        );

        if ($checkRowCounts) {
            $this->compareRowCounts($checks, $current, $baseline);
        }
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $baseline
     */
    private function migrationMismatchMessage(array $current, array $baseline): string
    {
        $currentNames = collect($current['migrations'])->pluck('migration');
        $baselineNames = collect($baseline['migrations'] ?? [])->pluck('migration');
        $missing = $baselineNames->diff($currentNames)->values();
        $extra = $currentNames->diff($baselineNames)->values();

        return sprintf(
            'Migration mismatch. Missing: %s. Extra: %s.',
            $missing->isEmpty() ? 'none' : $missing->take(5)->implode(', '),
            $extra->isEmpty() ? 'none' : $extra->take(5)->implode(', '),
        );
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $baseline
     */
    private function schemaMismatchMessage(array $current, array $baseline): string
    {
        $currentTables = collect($current['tables'])->keyBy('name');
        $baselineTables = collect($baseline['tables'] ?? [])->keyBy('name');
        $missing = $baselineTables->keys()->diff($currentTables->keys())->values();
        $extra = $currentTables->keys()->diff($baselineTables->keys())->values();
        $changed = $baselineTables->keys()
            ->intersect($currentTables->keys())
            ->filter(fn (string $name): bool => ($baselineTables[$name]['schema_hash'] ?? null) !== ($currentTables[$name]['schema_hash'] ?? null))
            ->values();

        return sprintf(
            'Schema mismatch. Missing tables: %s. Extra tables: %s. Changed definitions/constraints: %s.',
            $missing->isEmpty() ? 'none' : $missing->take(5)->implode(', '),
            $extra->isEmpty() ? 'none' : $extra->take(5)->implode(', '),
            $changed->isEmpty() ? 'none' : $changed->take(5)->implode(', '),
        );
    }

    /**
     * @param  array<int, array{name: string, status: string, message: string}>  $checks
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $baseline
     */
    private function compareRowCounts(array &$checks, array $current, array $baseline): void
    {
        $bothExact = ($current['row_count_mode'] ?? null) === 'exact'
            && ($baseline['row_count_mode'] ?? null) === 'exact';

        if (! $bothExact) {
            $this->add(
                $checks,
                'row_counts',
                false,
                'Exact row-count comparison requires both fingerprints to use --exact-counts.',
            );

            return;
        }

        $currentCounts = collect($current['tables'])->pluck('row_count', 'name');
        $baselineCounts = collect($baseline['tables'] ?? [])->pluck('row_count', 'name');
        $differences = $baselineCounts->filter(
            fn (mixed $count, string $table): bool => ! $currentCounts->has($table) || $currentCounts[$table] !== $count,
        );

        $this->add(
            $checks,
            'row_counts',
            $differences->isEmpty(),
            $differences->isEmpty()
                ? sprintf('Exact row counts match for all %d baseline tables.', $baselineCounts->count())
                : sprintf('Row counts differ for %d table(s): %s', $differences->count(), $differences->keys()->take(5)->implode(', ')),
        );
    }

    /**
     * @param  array<int, array{name: string, status: string, message: string}>  $checks
     */
    private function add(array &$checks, string $name, bool $passes, string $message): void
    {
        $checks[] = [
            'name' => $name,
            'status' => $passes ? 'pass' : 'fail',
            'message' => $message,
        ];
    }

    private function numericVersion(string $version): string
    {
        return preg_match('/\d+(?:\.\d+){0,2}/', $version, $matches) === 1
            ? $matches[0]
            : '0.0.0';
    }
}
