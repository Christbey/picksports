<?php

use App\Services\MLB\MlbArtifactCohortState;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\SQLiteConnection;
use Tests\Support\SingleActorSqliteMlbArtifactCohortSerializer;

function cohortStateConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
    $connection->statement('CREATE TABLE model_artifacts (id TEXT PRIMARY KEY, training_run_id TEXT, sport TEXT, model_type TEXT, status TEXT, metrics TEXT, created_at TEXT, updated_at TEXT, promoted_at TEXT)');
    $connection->enableQueryLog();

    return $connection;
}

function cohortStateRow(int $id, ?string $metrics = null): array
{
    return ['id' => sprintf('00000000-0000-4000-8000-%012d', $id), 'training_run_id' => '00000000-0000-4000-8000-000000000099',
        'sport' => 'mlb', 'model_type' => 'mlb_tabular_bundle', 'status' => 'challenger', 'metrics' => $metrics,
        'created_at' => '2026-06-30 12:00:00', 'updated_at' => '2026-06-30 12:00:00', 'promoted_at' => null];
}

it('reads only bounded cohort fields from the write PDO without eager run bodies', function () {
    $connection = cohortStateConnection();
    $connection->table('model_artifacts')->insert(cohortStateRow(1, '{"shadow_selection":{"active":true}}'));
    $connection->setReadPdo(fn () => throw new LogicException('Unused replica must not resolve.'));
    $rows = readFunctionalCohortState($connection);
    expect($rows)->toHaveCount(1)->and($rows[0]->metrics)->toBe(['shadow_selection' => ['active' => true]])
        ->and($rows[0]->relationLoaded('trainingRun'))->toBeFalse();
    $sql = implode("\n", array_column($connection->getQueryLog(), 'query'));
    expect($sql)->not->toContain('select *')->not->toContain('model_runs');
});

it('refuses oversized metrics through byte metadata before selecting their body', function () {
    $connection = cohortStateConnection();
    $connection->table('model_artifacts')->insert(cohortStateRow(1, '{"x":"'.str_repeat('x', 8388608).'"}'));
    $connection->flushQueryLog();
    expect(fn () => readFunctionalCohortState($connection))->toThrow(RuntimeException::class, 'byte budget');
    expect(implode("\n", array_column($connection->getQueryLog(), 'query')))->not->toContain('metrics_body');
});

it('refuses total metrics growth before any body read', function () {
    $connection = cohortStateConnection();
    for ($i = 1; $i <= 9; $i++) {
        $connection->table('model_artifacts')->insert(cohortStateRow($i, '{"x":"'.str_repeat('x', 250000).'"}'));
    }
    $connection->flushQueryLog();
    expect(fn () => readFunctionalCohortState($connection))->toThrow(RuntimeException::class, 'byte budget');
    expect(implode("\n", array_column($connection->getQueryLog(), 'query')))->not->toContain('metrics_body');
});

it('refuses current body growth between inventory and bounded hydration', function () {
    $connection = cohortStateConnection();
    $connection->table('model_artifacts')->insert(cohortStateRow(1, '{}'));
    $connection->beforeExecuting(function ($sql) use ($connection) {
        if (str_contains($sql, 'metrics_body')) {
            $connection->table('model_artifacts')->update(['metrics' => '{"changed":true}']);
        }
    });
    expect(fn () => readFunctionalCohortState($connection))->toThrow(RuntimeException::class, 'state changed');
});

it('admits lexical structure before decoding metrics', function () {
    $connection = cohortStateConnection();
    $connection->table('model_artifacts')->insert(cohortStateRow(1, '{"x":['.implode(',', array_fill(0, 30000, '0')).']}'));
    expect(fn () => readFunctionalCohortState($connection))->toThrow(RuntimeException::class, 'token budget');
});

it('does not hydrate oversized scalar identity fields on SQLite affinity', function () {
    $connection = cohortStateConnection();
    $connection->table('model_artifacts')->insert([...cohortStateRow(1), 'model_type' => str_repeat('x', 8388608)]);
    $connection->flushQueryLog();
    expect(fn () => readFunctionalCohortState($connection))->toThrow(RuntimeException::class, 'scalar bound');
    expect(implode("\n", array_column($connection->getQueryLog(), 'query')))->not->toContain('metrics_body');
});

it('refuses the 1001st cohort member before hydration', function () {
    $connection = cohortStateConnection();
    $rows = [];
    for ($i = 1; $i <= 1001; $i++) {
        $rows[] = cohortStateRow($i);
    }
    $connection->table('model_artifacts')->insert($rows);
    $connection->flushQueryLog();
    expect(fn () => readFunctionalCohortState($connection))->toThrow(RuntimeException::class, '1000-row');
    expect(implode("\n", array_column($connection->getQueryLog(), 'query')))->not->toContain('metrics_body');
});

function readFunctionalCohortState(SQLiteConnection $connection): Collection
{
    return $connection->transaction(fn () => (new SingleActorSqliteMlbArtifactCohortSerializer)->run(
        $connection, fn () => (new MlbArtifactCohortState)->read($connection),
    ));
}

it('does not certify an unlocked standalone bounded inventory as coherent state', function () {
    $connection = cohortStateConnection();
    expect(fn () => (new MlbArtifactCohortState)->read($connection))
        ->toThrow(RuntimeException::class, 'current serialized range');
});

it('admits the last reviewed cohort slot but refuses an insertion beyond it', function () {
    $connection = cohortStateConnection();
    $rows = [];
    for ($i = 1; $i <= 999; $i++) {
        $rows[] = cohortStateRow($i);
    }
    $connection->table('model_artifacts')->insert($rows);
    $admit = fn () => $connection->transaction(fn () => (new SingleActorSqliteMlbArtifactCohortSerializer)->run(
        $connection, fn () => (new MlbArtifactCohortState)->assertAddition($connection, ['shadow_selection' => ['active' => false]]),
    ));
    $admit();
    expect($connection->table('model_artifacts')->count())->toBe(999);
    $connection->table('model_artifacts')->insert(cohortStateRow(1000));
    expect($admit)->toThrow(RuntimeException::class, 'cohort count');
    expect($connection->table('model_artifacts')->count())->toBe(1000);
});

it('includes proposed metrics in the cohort aggregate byte admission', function () {
    $connection = cohortStateConnection();
    for ($i = 1; $i <= 8; $i++) {
        $connection->table('model_artifacts')->insert(cohortStateRow($i, '{"x":"'.str_repeat('x', 250000).'"}'));
    }
    expect(fn () => $connection->transaction(fn () => (new SingleActorSqliteMlbArtifactCohortSerializer)->run(
        $connection, fn () => (new MlbArtifactCohortState)->assertAddition($connection, ['x' => str_repeat('x', 150000)]),
    )))->toThrow(RuntimeException::class, 'cohort metrics budget');
    expect($connection->table('model_artifacts')->count())->toBe(8);
});
