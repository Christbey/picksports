<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'sports_futures_odds_snapshots';

    private const REQUIRED = [
        'sports_futures_odds_snapshots_snapshot_key_unique' => [['snapshot_key'], true],
        'sports_futures_odds_snapshots_row_key_index' => [['row_key'], false],
        'sports_futures_odds_snapshots_sport_index' => [['sport'], false],
        'sports_futures_odds_snapshots_season_index' => [['season'], false],
        'sports_futures_odds_snapshots_odds_api_sport_key_index' => [['odds_api_sport_key'], false],
        'sports_futures_odds_snapshots_event_id_index' => [['event_id'], false],
        'sports_futures_odds_snapshots_commence_time_index' => [['commence_time'], false],
        'sports_futures_odds_snapshots_bookmaker_index' => [['bookmaker'], false],
        'sports_futures_odds_snapshots_market_key_index' => [['market_key'], false],
        'sports_futures_odds_snapshots_captured_at_index' => [['captured_at'], false],
        'sfos_sport_season_captured_idx' => [['sport', 'season', 'captured_at'], false],
        'sfos_market_captured_idx' => [['sport', 'season', 'market_key', 'captured_at'], false],
    ];

    public function up(): void
    {
        $connection = DB::connection();
        $mysql = $connection->getDriverName() === 'mysql';
        $indexes = $mysql ? $this->mysqlIndexes() : Schema::getIndexes(self::TABLE);
        $missing = [];
        foreach (self::REQUIRED as $name => [$columns, $unique]) {
            $equivalent = array_any($indexes, fn (array $index): bool => ($index['usable'] ?? true)
                && $index['columns'] === $columns && (! $unique || $index['unique']));
            if ($equivalent) {
                continue;
            }
            if (array_any($indexes, fn (array $index): bool => $index['name'] === $name)) {
                throw new RuntimeException('Futures snapshot index name conflicts with its required definition: '.$name);
            }
            $missing[$name] = [$columns, $unique];
        }
        if ($missing === []) {
            return;
        }
        if (! $mysql) {
            throw new RuntimeException('Futures snapshot index drift requires a reviewed MySQL repair; no indexes changed.');
        }
        $engine = $connection->selectOne(
            'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$connection->getDatabaseName(), $connection->getTablePrefix().self::TABLE],
        );
        if (strtolower((string) ($engine->engine ?? '')) !== 'innodb') {
            throw new RuntimeException('Atomic futures snapshot index repair requires InnoDB; no indexes changed.');
        }

        $table = $connection->getQueryGrammar()->wrapTable(self::TABLE);
        if (array_any($missing, fn (array $definition): bool => $definition[1])) {
            // A timeout or any preflight exception propagates before ANY DDL.
            // Never delete or consolidate snapshots to make this check pass.
            $duplicate = $connection->selectOne(
                'SELECT /*+ MAX_EXECUTION_TIME(5000) */ snapshot_key FROM '.$table.' GROUP BY snapshot_key HAVING COUNT(*) > 1 LIMIT 1',
            );
            if ($duplicate !== null) {
                throw new RuntimeException('Duplicate futures snapshot keys require explicit reconciliation; no indexes changed.');
            }
        }
        $clauses = [];
        foreach ($missing as $name => [$columns, $unique]) {
            $clauses[] = 'ADD '.($unique ? 'UNIQUE ' : '').'INDEX `'.$name.'` ('.implode(', ', array_map(fn (string $column): string => '`'.$column.'`', $columns)).')';
        }
        $previousLockWait = (int) $connection->selectOne('SELECT @@SESSION.lock_wait_timeout AS previous_lock_wait_timeout')->previous_lock_wait_timeout;
        try {
            $connection->statement('SET SESSION lock_wait_timeout = 15');
            // One atomic InnoDB DDL statement: concurrent duplicate insertion
            // during the build fails the entire index addition, not a subset.
            $connection->statement('ALTER TABLE '.$table.' '.implode(', ', $clauses).', ALGORITHM=INPLACE, LOCK=NONE');
        } finally {
            $connection->statement('SET SESSION lock_wait_timeout = '.$previousLockWait);
        }
    }

    /** @return list<array{name:string,columns:list<string>,unique:bool,usable:bool}> */
    private function mysqlIndexes(): array
    {
        $connection = DB::connection();
        $rows = $connection->select(
            'SELECT INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, COLUMN_NAME AS column_name, SUB_PART AS sub_part, INDEX_TYPE AS index_type, EXPRESSION AS expression, IS_VISIBLE AS is_visible FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$connection->getDatabaseName(), $connection->getTablePrefix().self::TABLE],
        );
        $indexes = [];
        foreach ($rows as $row) {
            $indexes[$row->index_name] ??= ['name' => $row->index_name, 'columns' => [], 'unique' => (int) $row->non_unique === 0, 'usable' => true];
            $indexes[$row->index_name]['columns'][] = (string) $row->column_name;
            $indexes[$row->index_name]['usable'] = $indexes[$row->index_name]['usable']
                && $row->sub_part === null && $row->expression === null
                && strtoupper($row->index_type) === 'BTREE' && strtoupper($row->is_visible) === 'YES';
        }

        return array_values($indexes);
    }

    public function down(): void
    {
        throw new LogicException('Retain futures snapshot integrity and lookup indexes; do not roll back by removing constraints.');
    }
};
