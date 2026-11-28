<?php

namespace Tests\Support;

use App\Services\MLB\MlbArtifactCohortSerializer;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Functional-only collaborator, explicitly installed by a test.
 * It simulates one actor; it supplies no SQLite predicate/concurrency guarantee.
 * Production source fences, connection ownership and transaction requirements remain real.
 */
final class SingleActorSqliteMlbArtifactCohortSerializer extends MlbArtifactCohortSerializer
{
    public function assertSupported(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException('The single-actor functional collaborator is SQLite-only.');
        }
    }

    protected function transactionIdentity(Connection $connection): array
    {
        return [$connection->getPdo()->inTransaction()];
    }

    protected function lockRange(Connection $connection): void {}
}
