<?php

namespace App\Services\MLB;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use RuntimeException;
use WeakMap;
use WeakReference;

/**
 * Serializes the complete observable MLB artifact predicate, not just existing IDs.
 * This collaborator grants no dataset/source admission or transaction ownership.
 */
class MlbArtifactCohortSerializer
{
    private static ?WeakMap $owned = null;

    public function assertSupported(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('MLB artifact cohort mutation requires verified MySQL range serialization.');
        }
    }

    /** The caller must already own its source-first transaction; callback must perform no I/O. */
    public function run(Connection $connection, Closure $callback): mixed
    {
        $this->assertSupported($connection);
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('MLB artifact cohort serialization requires an owned transaction.');
        }
        if (self::isOwned($connection)) {
            self::assertOwned($connection);

            return $callback();
        }
        $identity = $this->transactionIdentity($connection);
        $pdo = $connection->getPdo();
        $this->lockRange($connection);
        if ($connection->getPdo() !== $pdo || $this->transactionIdentity($connection) !== $identity) {
            throw new RuntimeException('MLB artifact cohort transaction changed while acquiring its range.');
        }
        self::$owned ??= new WeakMap;
        self::$owned[$connection] = ['serializer' => $this, 'pdo' => WeakReference::create($pdo), 'transaction' => $identity];
        try {
            $result = $callback();
            self::assertOwned($connection);

            return $result;
        } finally {
            unset(self::$owned[$connection]);
        }
    }

    public static function assertOwned(Connection $connection): void
    {
        if (! self::isOwned($connection) || $connection->transactionLevel() < 1) {
            throw new RuntimeException('MLB artifact cohort mutation requires the current serialized range.');
        }
        $owned = self::$owned[$connection];
        if ($owned['pdo']->get() !== $connection->getPdo()
            || $owned['serializer']->transactionIdentity($connection) !== $owned['transaction']) {
            throw new RuntimeException('MLB artifact cohort transaction or write PDO changed.');
        }
    }

    /** Read-only transaction witness; grants no source or range ownership. */
    public function transactionWitness(Connection $connection): array
    {
        $this->assertSupported($connection);
        if ($connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()) {
            throw new RuntimeException('MLB transaction evidence requires a current real transaction.');
        }

        return $this->transactionIdentity($connection);
    }

    /** Actual active transaction evidence; a session default cannot certify its isolation. */
    protected function transactionIdentity(Connection $connection): array
    {
        try {
            $rows = $connection->select(
                "SELECT t.THREAD_ID, t.EVENT_ID, t.ISOLATION_LEVEL, t.AUTOCOMMIT FROM performance_schema.events_transactions_current t JOIN performance_schema.threads c ON c.THREAD_ID = t.THREAD_ID WHERE c.PROCESSLIST_ID = CONNECTION_ID() AND t.STATE = 'ACTIVE' AND t.END_EVENT_ID IS NULL",
                [],
                false,
            );
        } catch (QueryException $exception) {
            throw new RuntimeException('MLB artifact cohort active transaction evidence is unavailable.', 0, $exception);
        }
        if (count($rows) !== 1 || $rows[0]->ISOLATION_LEVEL !== 'REPEATABLE READ' || $rows[0]->AUTOCOMMIT !== 'NO') {
            throw new RuntimeException('MLB artifact cohort mutation requires observable active REPEATABLE-READ transaction evidence.');
        }

        return [(string) $rows[0]->THREAD_ID, (string) $rows[0]->EVENT_ID];
    }

    protected function lockRange(Connection $connection): void
    {
        $table = $connection->getTablePrefix().'model_artifacts';
        $engine = $connection->scalar(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
            false,
        );
        $columns = $connection->select(
            'SELECT COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
            [$table, 'model_artifacts_sport_index'],
            false,
        );
        if ($engine !== 'InnoDB' || count($columns) !== 1
            || $columns[0]->COLUMN_NAME !== 'sport' || (int) $columns[0]->SEQ_IN_INDEX !== 1
            || (int) $columns[0]->NON_UNIQUE !== 1 || $columns[0]->SUB_PART !== null) {
            throw new RuntimeException('MLB artifact cohort mutation requires the complete InnoDB sport index.');
        }
        // The unfiltered sport range includes every status/model type, including an empty range.
        // LIMIT is only a refusal bound: an admitted range has been exhausted below the limit.
        $rows = $connection->select(
            'SELECT id FROM '.$connection->getQueryGrammar()->wrapTable('model_artifacts')
                .' FORCE INDEX (`model_artifacts_sport_index`) WHERE sport = ? ORDER BY sport, id LIMIT 1001 FOR UPDATE',
            ['mlb'],
            false,
        );
        if (count($rows) > 1000) {
            throw new RuntimeException('MLB artifact cohort exceeds the reviewed 1000-row serialization bound.');
        }
    }

    private static function isOwned(Connection $connection): bool
    {
        return self::$owned !== null && isset(self::$owned[$connection]);
    }
}
