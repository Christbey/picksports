<?php

namespace App\Services\Relationships;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Counts integrity exceptions without changing records or exporting row contents. */
final class RelationshipIntegrityAudit
{
    /**
     * @param  array<string, array<string, mixed>>  $inventory
     * @return array<string, array<string, mixed>>
     */
    public function inspect(array $inventory, array $options = []): array
    {
        $this->configureBudget($options);
        $tables = [];
        $seen = 0;
        foreach ($inventory as $definition) {
            $table = $definition['table'];
            $connection = DB::connection($definition['connection']);
            $key = $connection->getName().'::'.$table;
            if (! $definition['table_exists']) {
                $tables[$key] = ['connection' => $connection->getName(), 'table' => $table, 'complete' => false,
                    'status' => 'table_missing', 'row_count' => null, 'foreign_keys' => [], 'unique_indexes' => []];

                continue;
            }
            if (isset($tables[$key])) {
                continue;
            }
            if (++$seen > $this->limits['max_tables']) {
                $tables[$key] = ['connection' => $connection->getName(), 'table' => $table, 'complete' => false,
                    'status' => 'table_limit', 'row_count' => null, 'foreign_keys' => [], 'unique_indexes' => []];

                continue;
            }
            try {
                $tables[$key] = $this->inspectTable($definition, $connection);
            } catch (\Throwable $exception) {
                $reason = $exception instanceof \LengthException
                    && in_array($exception->getMessage(), ['row_limit', 'query_limit', 'time_limit', 'unsupported_driver'], true)
                        ? $exception->getMessage() : 'query_failed';
                $tables[$key] = ['connection' => $connection->getName(), 'table' => $table, 'complete' => false,
                    'status' => $reason, 'row_count' => null, 'foreign_keys' => [], 'unique_indexes' => []];
            }
        }
        ksort($tables);

        return $tables;
    }

    private function inspectTable(array $definition, Connection $connection): array
    {
        $table = $definition['table'];
        $checks = ['connection' => $connection->getName(), 'table' => $table, 'complete' => true, 'status' => 'complete', 'snapshot_consistency' => 'not_guaranteed',
            'row_count' => $this->requireWithinRows($connection, $table), 'foreign_keys' => [], 'unique_indexes' => []];
        $columns = array_column($definition['columns'], 'name');
        foreach ($definition['foreign_keys'] as $foreign) {
            if (! empty($foreign['foreign_schema']) && $foreign['foreign_schema'] !== $connection->getDatabaseName()) {
                $checks['complete'] = false;
                $checks['status'] = 'incomplete';
                $checks['foreign_keys'][] = ['columns' => $foreign['columns'], 'parent_table' => $foreign['foreign_table'],
                    'status' => 'unsupported_cross_schema', 'orphan_count' => null, 'shared_identity_columns' => [], 'lineage_mismatch_count' => null];

                continue;
            }
            $this->requireWithinRows($connection, $foreign['foreign_table']);
            $query = $connection->table($table.' as child');
            foreach ($foreign['columns'] as $column) {
                $query->whereNotNull('child.'.$column);
            }
            $query->whereNotExists(function (Builder $parent) use ($foreign): void {
                $parent->selectRaw('1')->from($foreign['foreign_table'].' as parent');
                foreach ($foreign['columns'] as $index => $column) {
                    $parent->whereColumn('parent.'.$foreign['foreign_columns'][$index], 'child.'.$column);
                }
            });
            $this->spendQueryBudget();
            $parentColumns = Schema::connection($connection->getName())->getColumnListing($foreign['foreign_table']);
            $sharedIdentity = array_values(array_intersect(['sport_event_id', 'sport', 'phase', 'game_id'], $columns, $parentColumns));
            $mismatch = $connection->table($table.' as child')->join($foreign['foreign_table'].' as parent', function ($join) use ($foreign): void {
                foreach ($foreign['columns'] as $index => $column) {
                    $join->on('child.'.$column, '=', 'parent.'.$foreign['foreign_columns'][$index]);
                }
            });
            $mismatch->where(function (Builder $query) use ($sharedIdentity): void {
                foreach ($sharedIdentity as $column) {
                    $this->nullAwareMismatch($query, 'child.'.$column, 'parent.'.$column);
                }
            });
            $checks['foreign_keys'][] = [
                'columns' => $foreign['columns'],
                'parent_table' => $foreign['foreign_table'],
                'orphan_count' => $this->countQuery($query),
                'shared_identity_columns' => $sharedIdentity,
                'lineage_mismatch_count' => $sharedIdentity === [] ? 0 : $this->countQuery($mismatch),
            ];
        }
        foreach ($definition['indexes'] as $index) {
            if (! $index['unique']) {
                continue;
            }
            $groups = $connection->table($table)->select($index['columns'])->groupBy($index['columns'])->havingRaw('COUNT(*) > 1');
            foreach ($index['columns'] as $column) {
                $groups->whereNotNull($column);
            }
            $checks['unique_indexes'][] = [
                'name' => $index['name'],
                'columns' => $index['columns'],
                'duplicate_group_count' => $this->countQuery($connection->query()->fromSub($groups, 'duplicate_groups')),
            ];
        }
        if (in_array('sport_event_id', $columns, true) && $table !== 'sport_events') {
            $this->requireWithinRows($connection, 'sport_events');
            $query = $connection->table($table.' as child')->join('sport_events as event', 'event.id', '=', 'child.sport_event_id');
            if (in_array('sport', $columns, true)) {
                $this->nullAwareMismatch($query, 'child.sport', 'event.sport');
                $checks['event_sport_mismatch_count'] = $this->countQuery($query);
            } elseif (preg_match('/^(nfl|nba|wnba|cbb|wcbb|cfb|mlb)_/', $table, $match) === 1) {
                $checks['event_sport_mismatch_count'] = $this->countQuery($query->where('event.sport', '!=', $match[1]));
            }
            $checks['null_event_link_count'] = $this->countQuery($connection->table($table)->whereNull('sport_event_id'));
        }
        if (count(array_intersect(['sport', 'game_table', 'game_id'], $columns)) === 3) {
            $checks['dynamic_game_references'] = $this->dynamicReferences($definition['connection'], $table, 'game');
        }
        if (count(array_intersect(['sport', 'prediction_table', 'prediction_id'], $columns)) === 3) {
            $checks['dynamic_prediction_references'] = $this->dynamicReferences($definition['connection'], $table, 'prediction');
        }

        return $checks;
    }

    private array $limits = [];

    private array $cardinality = [];

    private int $queries = 0;

    private float $deadline = 0;

    private function configureBudget(array $options): void
    {
        $defaults = ['max_tables' => 25, 'max_rows' => 10000, 'max_queries' => 200, 'total_ms' => 10000, 'query_ms' => 500];
        if (array_diff_key($options, $defaults) !== []) {
            throw new \InvalidArgumentException('Unknown relationship audit budget option.');
        }
        foreach ($options as $key => $value) {
            if (! is_int($value) || $value < 1 || $value > ['max_tables' => 500, 'max_rows' => 100000, 'max_queries' => 2000, 'total_ms' => 30000, 'query_ms' => 2000][$key]) {
                throw new \InvalidArgumentException('Invalid relationship audit budget.');
            }
        }
        $this->limits = [...$defaults, ...$options];
        $this->cardinality = [];
        $this->queries = 0;
        $this->deadline = microtime(true) + $this->limits['total_ms'] / 1000;
    }

    private function requireWithinRows(Connection $connection, string $table): int
    {
        $key = $connection->getName().'::'.$table;
        if (! array_key_exists($key, $this->cardinality)) {
            $this->cardinality[$key] = $this->countQuery($connection->table($table)->selectRaw('1 as marker')->limit($this->limits['max_rows'] + 1));
        }
        if ($this->cardinality[$key] > $this->limits['max_rows']) {
            throw new \LengthException('row_limit');
        }

        return $this->cardinality[$key];
    }

    private function spendQueryBudget(): void
    {
        if ($this->queries >= $this->limits['max_queries']) {
            throw new \LengthException('query_limit');
        }
        if (microtime(true) >= $this->deadline) {
            throw new \LengthException('time_limit');
        }
        $this->queries++;
    }

    private function countQuery(Builder $query): int
    {
        $this->spendQueryBudget();
        $query = clone $query;
        if ($query->columns === null) {
            $query->selectRaw('1 as marker');
        }
        $connection = $query->getConnection();
        if (! in_array($connection->getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new \LengthException('unsupported_driver');
        }
        $hint = $connection->getDriverName() === 'mysql' ? '/*+ MAX_EXECUTION_TIME('.$this->limits['query_ms'].') */ ' : '';
        $result = $connection->query()->fromSub($query, 'audit_rows')->selectRaw($hint.'COUNT(*) as aggregate')->first();
        if (microtime(true) >= $this->deadline) {
            throw new \LengthException('time_limit');
        }

        return (int) $result->aggregate;
    }

    private function nullAwareMismatch(Builder $query, string $child, string $parent): void
    {
        $query->orWhere(function (Builder $branch) use ($child, $parent): void {
            $branch->whereColumn($child, '!=', $parent)
                ->orWhere(fn (Builder $null) => $null->whereNull($child)->whereNotNull($parent))
                ->orWhere(fn (Builder $null) => $null->whereNotNull($child)->whereNull($parent));
        });
    }

    /** @return array{invalid_type_count:int,orphan_count:int} */
    private function dynamicReferences(?string $connectionName, string $table, string $kind): array
    {
        $connection = DB::connection($connectionName);
        $tableColumn = $kind.'_table';
        $idColumn = $kind.'_id';
        $valid = $connection->table($table)->whereNotNull($idColumn)->where(function (Builder $query) use ($kind, $tableColumn): void {
            foreach (['nfl', 'nba', 'wnba', 'mlb', 'cfb', 'cbb', 'wcbb'] as $sport) {
                $query->orWhere(fn (Builder $row) => $row->where('sport', $sport)->whereIn($tableColumn, $kind === 'game' ? [$sport.'_games'] : [$sport.'_predictions', 'predictions']));
            }
        });
        $invalid = $this->countQuery($connection->table($table)->whereNotNull($idColumn)) - $this->countQuery($valid);
        $orphans = 0;
        foreach (['nfl', 'nba', 'wnba', 'mlb', 'cfb', 'cbb', 'wcbb'] as $sport) {
            $targets = $kind === 'game' ? [$sport.'_games'] : [$sport.'_predictions', 'predictions'];
            foreach ($targets as $target) {
                $this->requireWithinRows($connection, $target);
                $orphans += $this->countQuery($connection->table($table.' as child')->where('child.sport', $sport)
                    ->where('child.'.$tableColumn, $target)->whereNotNull('child.'.$idColumn)
                    ->whereNotExists(function (Builder $query) use ($kind, $target, $sport, $idColumn): void {
                        $query->selectRaw('1')->from($target.' as parent')->whereColumn('parent.id', 'child.'.$idColumn);
                        if ($kind === 'prediction' && $target === 'predictions') {
                            $query->where('parent.sport', $sport);
                        }
                    }));
            }
        }

        return ['invalid_type_count' => $invalid, 'orphan_count' => $orphans];
    }
}
