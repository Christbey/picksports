<?php

namespace App\Services\Database;

use Illuminate\Database\DatabaseManager;
use RuntimeException;

class IndexAuditService
{
    public function __construct(private readonly DatabaseManager $databases) {}

    /**
     * @param  list<string>  $tables
     * @return list<array<string, mixed>>
     */
    public function inspect(?string $connectionName = null, array $tables = []): array
    {
        $connection = $this->databases->connection($connectionName);
        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('Index audit requires a MySQL connection.');
        }

        $rows = $connection->select(<<<'SQL'
            SELECT
                TABLE_NAME AS table_name,
                INDEX_NAME AS index_name,
                NON_UNIQUE AS non_unique,
                SEQ_IN_INDEX AS sequence,
                COLUMN_NAME AS column_name,
                SUB_PART AS sub_part,
                CARDINALITY AS cardinality
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
            SQL, [$connection->getDatabaseName()]);

        $tableFilter = array_fill_keys(array_map('strtolower', $tables), true);
        $indexes = collect($rows)
            ->filter(fn (object $row): bool => $tableFilter === [] || isset($tableFilter[strtolower((string) $row->table_name)]))
            ->groupBy(fn (object $row): string => $row->table_name."\0".$row->index_name)
            ->map(function ($parts): array {
                $first = $parts->first();

                return [
                    'table' => (string) $first->table_name,
                    'name' => (string) $first->index_name,
                    'unique' => (int) $first->non_unique === 0,
                    'columns' => $parts->map(fn (object $row): array => [
                        'name' => (string) $row->column_name,
                        'prefix' => $row->sub_part === null ? null : (int) $row->sub_part,
                    ])->values()->all(),
                    'cardinality' => $parts->map(fn (object $row): ?int => $row->cardinality === null ? null : (int) $row->cardinality)->max(),
                ];
            })
            ->values()
            ->all();

        return $this->analyze($indexes);
    }

    /**
     * @param  list<array{table:string,name:string,unique:bool,columns:list<array{name:string,prefix:int|null}>,cardinality:int|null}>  $indexes
     * @return list<array<string, mixed>>
     */
    public function analyze(array $indexes): array
    {
        $findings = [];

        foreach (collect($indexes)->groupBy('table') as $table => $tableIndexes) {
            foreach ($tableIndexes as $candidate) {
                if ($candidate['unique'] || $candidate['name'] === 'PRIMARY') {
                    continue;
                }

                foreach ($tableIndexes as $covering) {
                    if ($candidate['name'] === $covering['name']) {
                        continue;
                    }

                    if (! $this->isLeftPrefix($candidate['columns'], $covering['columns'])) {
                        continue;
                    }

                    $exact = count($candidate['columns']) === count($covering['columns']);
                    $findings[] = [
                        'table' => $table,
                        'index' => $candidate['name'],
                        'kind' => $exact ? 'duplicate' : 'left_prefix',
                        'covered_by' => $covering['name'],
                        'columns' => array_column($candidate['columns'], 'name'),
                        'message' => $exact
                            ? 'Non-unique index has the same column definition as another index.'
                            : 'Non-unique index is a left-prefix of a wider index.',
                    ];

                    break;
                }

                if (count($candidate['columns']) === 1 && $candidate['cardinality'] !== null && $candidate['cardinality'] <= 2) {
                    $findings[] = [
                        'table' => $table,
                        'index' => $candidate['name'],
                        'kind' => 'low_cardinality',
                        'covered_by' => null,
                        'columns' => array_column($candidate['columns'], 'name'),
                        'message' => 'Single-column index has estimated cardinality <= 2; validate real EXPLAIN plans before retaining or removing it.',
                    ];
                }
            }
        }

        return collect($findings)
            ->unique(fn (array $finding): string => implode('|', [
                $finding['table'],
                $finding['index'],
                $finding['kind'],
                $finding['covered_by'] ?? '',
            ]))
            ->sortBy(fn (array $finding): string => $finding['table'].'|'.$finding['index'].'|'.$finding['kind'])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{name:string,prefix:int|null}>  $candidate
     * @param  list<array{name:string,prefix:int|null}>  $covering
     */
    private function isLeftPrefix(array $candidate, array $covering): bool
    {
        if (count($candidate) > count($covering)) {
            return false;
        }

        foreach ($candidate as $offset => $column) {
            if ($column !== ($covering[$offset] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
