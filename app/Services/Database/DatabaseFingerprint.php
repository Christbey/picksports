<?php

namespace App\Services\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\File;
use JsonSerializable;

class DatabaseFingerprint
{
    public const FORMAT_VERSION = 1;

    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * @return array<string, mixed>
     */
    public function capture(?string $connectionName = null, bool $exactCounts = false): array
    {
        /** @var Connection $connection */
        $connection = $this->database->connection($connectionName);
        $schema = $connection->getSchemaBuilder();
        $driver = $connection->getDriverName();
        $schemaName = in_array($driver, ['mysql', 'mariadb'], true)
            ? $connection->getDatabaseName()
            : null;
        $tables = collect($schema->getTables($schemaName))
            ->sortBy('name')
            ->values();
        $rowCountMode = $exactCounts || $driver === 'sqlite' ? 'exact' : 'estimated';
        $estimatedRowCounts = $rowCountMode === 'estimated'
            ? $this->estimatedRowCounts($connection)
            : [];

        $tableFingerprints = $tables->map(function (array $table) use ($connection, $schema, $rowCountMode, $estimatedRowCounts): array {
            $name = (string) $table['name'];
            $columns = $this->sortedRecords($schema->getColumns($name), ['name']);
            $indexes = $this->sortedRecords($schema->getIndexes($name), ['name', 'columns']);
            $foreignKeys = $this->sortedRecords($schema->getForeignKeys($name), ['name', 'columns', 'foreign_table']);
            $definition = [
                'name' => $name,
                'columns' => $columns,
                'indexes' => $indexes,
                'foreign_keys' => $foreignKeys,
            ];

            return [
                ...$definition,
                'row_count' => $rowCountMode === 'exact'
                    ? $connection->table($name)->count()
                    : ($estimatedRowCounts[$name] ?? null),
                'size_bytes' => isset($table['size']) ? (int) $table['size'] : null,
                'schema_hash' => $this->hash($definition),
            ];
        })->all();

        $migrations = $this->migrations($connection, $tableFingerprints);
        $migrationState = $this->migrationState($migrations);
        $schemaIdentity = array_map(
            fn (array $table): array => $this->only($table, ['name', 'columns', 'indexes', 'foreign_keys']),
            $tableFingerprints,
        );
        $dataIdentity = array_map(
            fn (array $table): array => $this->only($table, ['name', 'row_count']),
            $tableFingerprints,
        );

        $fingerprint = [
            'format_version' => self::FORMAT_VERSION,
            'captured_at' => now('UTC')->toIso8601String(),
            'connection' => $connection->getName(),
            'database' => $connection->getDatabaseName(),
            'driver' => $driver,
            'server_version' => $connection->getServerVersion(),
            'laravel_version' => app()->version(),
            'row_count_mode' => $rowCountMode,
            'migration_state' => $migrationState,
            'migrations' => $migrations,
            'tables' => $tableFingerprints,
        ];

        $fingerprint['schema_hash'] = $this->hash($schemaIdentity);
        $fingerprint['migration_hash'] = $this->hash($migrations);
        $fingerprint['data_hash'] = $this->hash($dataIdentity);
        $fingerprint['fingerprint'] = $this->hash([
            'format_version' => self::FORMAT_VERSION,
            'driver' => $driver,
            'schema_hash' => $fingerprint['schema_hash'],
            'migration_hash' => $fingerprint['migration_hash'],
        ]);

        return $fingerprint;
    }

    /**
     * @return array<string, int|null>
     */
    private function estimatedRowCounts(Connection $connection): array
    {
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return [];
        }

        return collect($connection->select(
            'select table_name, table_rows from information_schema.tables where table_schema = ? and table_type = ?',
            [$connection->getDatabaseName(), 'BASE TABLE'],
        ))
            ->mapWithKeys(function (object $row): array {
                $values = array_change_key_case((array) $row, CASE_LOWER);

                return [
                    (string) $values['table_name'] => $values['table_rows'] === null ? null : (int) $values['table_rows'],
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $tables
     * @return array<int, array{migration: string, batch: int}>
     */
    private function migrations(Connection $connection, array $tables): array
    {
        if (! collect($tables)->contains(fn (array $table): bool => $table['name'] === 'migrations')) {
            return [];
        }

        return $connection->table('migrations')
            ->orderBy('migration')
            ->get(['migration', 'batch'])
            ->map(fn (object $migration): array => [
                'migration' => (string) $migration->migration,
                'batch' => (int) $migration->batch,
            ])
            ->all();
    }

    /**
     * @param  array<int, array{migration: string, batch: int}>  $appliedMigrations
     * @return array{applied_count: int, disk_count: int, pending: array<int, string>, unknown: array<int, string>}
     */
    private function migrationState(array $appliedMigrations): array
    {
        $applied = collect($appliedMigrations)->pluck('migration')->values();
        $disk = collect(File::glob(database_path('migrations/*.php')))
            ->map(fn (string $path): string => pathinfo($path, PATHINFO_FILENAME))
            ->sort()
            ->values();

        return [
            'applied_count' => $applied->count(),
            'disk_count' => $disk->count(),
            'pending' => $disk->diff($applied)->values()->all(),
            'unknown' => $applied->diff($disk)->values()->all(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<int, string>  $sortKeys
     * @return array<int, array<string, mixed>>
     */
    private function sortedRecords(array $records, array $sortKeys): array
    {
        return collect($records)
            ->map(fn (array $record): array => $this->normalize($record))
            ->sortBy(fn (array $record): string => collect($sortKeys)
                ->map(fn (string $key): string => json_encode($record[$key] ?? null, JSON_UNESCAPED_SLASHES) ?: '')
                ->implode('|'))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    private function only(array $values, array $keys): array
    {
        return collect($keys)
            ->mapWithKeys(fn (string $key): array => [$key => $values[$key] ?? null])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(array $values): array
    {
        ksort($values);

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = array_is_list($value)
                    ? array_map(fn (mixed $item): mixed => is_array($item) ? $this->normalize($item) : $this->scalar($item), $value)
                    : $this->normalize($value);
            } else {
                $values[$key] = $this->scalar($value);
            }
        }

        return $values;
    }

    private function scalar(mixed $value): mixed
    {
        if ($value instanceof JsonSerializable) {
            return $value->jsonSerialize();
        }

        if (is_object($value)) {
            return (string) $value;
        }

        return $value;
    }

    /**
     * @param  array<int|string, mixed>  $value
     */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
