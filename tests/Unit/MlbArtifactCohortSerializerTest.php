<?php

use App\Services\MLB\MlbArtifactCohortSerializer;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteConnection;

function cohortTestConnection(): SQLiteConnection
{
    return new class(new PDO('sqlite::memory:'), ':memory:') extends SQLiteConnection
    {
        public int $generation = 0;

        public function beginTransaction()
        {
            parent::beginTransaction();
            $this->generation++;
        }
    };
}

it('refuses unsupported cohort mutation before invoking its callback', function () {
    $connection = cohortTestConnection();
    $connection->beginTransaction();
    $called = false;
    try {
        (new MlbArtifactCohortSerializer)->run($connection, function () use (&$called) {
            $called = true;
        });
        test()->fail('Unsupported driver admitted a cohort mutation.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('verified MySQL');
        expect($called)->toBeFalse();
    } finally {
        $connection->rollBack();
    }
});

it('requires a transaction even with an explicit functional serialization collaborator', function () {
    $serializer = new class extends MlbArtifactCohortSerializer
    {
        public function assertSupported(Connection $connection): void {}

        protected function lockRange(Connection $connection): void
        {
            throw new LogicException('No range should be touched without a transaction.');
        }
    };
    expect(fn () => $serializer->run(cohortTestConnection(), fn () => null))
        ->toThrow(RuntimeException::class, 'owned transaction');
});

it('scopes functional ownership to one connection and clears it after failure', function () {
    $connection = cohortTestConnection();
    $other = cohortTestConnection();
    $connection->beginTransaction();
    $other->beginTransaction();
    $serializer = new class extends MlbArtifactCohortSerializer
    {
        public int $locks = 0;

        protected function transactionIdentity(Connection $connection): array
        {
            return [$connection->generation];
        }

        public function assertSupported(Connection $connection): void {}

        protected function lockRange(Connection $connection): void
        {
            $this->locks++;
        }
    };
    try {
        $serializer->run($connection, function () use ($serializer, $connection, $other) {
            MlbArtifactCohortSerializer::assertOwned($connection);
            expect(fn () => MlbArtifactCohortSerializer::assertOwned($other))
                ->toThrow(RuntimeException::class, 'current serialized range');
            $serializer->run($connection, fn () => MlbArtifactCohortSerializer::assertOwned($connection));
            throw new LogicException('Synthetic callback failure.');
        });
    } catch (LogicException $exception) {
        expect($exception->getMessage())->toBe('Synthetic callback failure.');
    } finally {
        expect($serializer->locks)->toBe(1);
        expect(fn () => MlbArtifactCohortSerializer::assertOwned($connection))
            ->toThrow(RuntimeException::class, 'current serialized range');
        $connection->rollBack();
        $other->rollBack();
    }
});

it('refuses replaced write PDO authority before nested admission', function () {
    $connection = cohortTestConnection();
    $connection->beginTransaction();
    $original = $connection->getPdo();
    $serializer = functionalCohortSerializer();
    try {
        expect(fn () => $serializer->run($connection, function () use ($connection) {
            $connection->setPdo(new PDO('sqlite::memory:'));
            $connection->beginTransaction();
            MlbArtifactCohortSerializer::assertOwned($connection);
        }))->toThrow(RuntimeException::class, 'write PDO changed');
        expect(fn () => MlbArtifactCohortSerializer::assertOwned($connection))
            ->toThrow(RuntimeException::class, 'current serialized range');
    } finally {
        $connection->rollBack();
        $connection->setPdo($original);
        $original->rollBack();
    }
});

it('refuses commit and reopen on the same PDO at the same transaction level', function () {
    $connection = cohortTestConnection();
    $connection->beginTransaction();
    $serializer = functionalCohortSerializer();
    try {
        expect(fn () => $serializer->run($connection, function () use ($connection) {
            $connection->commit();
            $connection->beginTransaction();
            MlbArtifactCohortSerializer::assertOwned($connection);
        }))->toThrow(RuntimeException::class, 'transaction or write PDO changed');
    } finally {
        $connection->rollBack();
    }
});

it('rechecks transaction ownership after a callback even when it does not assert', function () {
    $connection = cohortTestConnection();
    $connection->beginTransaction();
    try {
        expect(fn () => functionalCohortSerializer()->run($connection, function () use ($connection) {
            $connection->commit();
            $connection->beginTransaction();
        }))->toThrow(RuntimeException::class, 'transaction or write PDO changed');
    } finally {
        $connection->rollBack();
    }
});

/** Functional-only stand-in: it does not claim SQLite provides a MySQL predicate lock. */
function functionalCohortSerializer(): MlbArtifactCohortSerializer
{
    return new class extends MlbArtifactCohortSerializer
    {
        public function assertSupported(Connection $connection): void {}

        protected function transactionIdentity(Connection $connection): array
        {
            return [$connection->generation];
        }

        protected function lockRange(Connection $connection): void {}
    };
}

it('refuses unavailable transaction instrumentation without executing the mutation', function () {
    $connection = cohortTestConnection();
    $connection->beginTransaction();
    $serializer = new class extends MlbArtifactCohortSerializer
    {
        public function assertSupported(Connection $connection): void {}
    };
    try {
        $serializer->run($connection, fn () => test()->fail('Unavailable instrumentation admitted mutation.'));
        test()->fail('Unavailable instrumentation was not refused.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('MLB artifact cohort active transaction evidence is unavailable.');
        expect($exception->getPrevious())->toBeInstanceOf(QueryException::class);
    } finally {
        $connection->rollBack();
    }
});
