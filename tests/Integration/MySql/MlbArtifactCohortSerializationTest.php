<?php

use App\Models\ModelRun;
use App\Services\MLB\MlbArtifactCohortSerializer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    if (DB::connection()->getDriverName() !== 'mysql') {
        throw new RuntimeException('Artifact cohort controls require disposable MySQL.');
    }
    Artisan::call('migrate:fresh', ['--force' => true]);
    Http::preventStrayRequests();
    config(['database.connections.cohort_peer' => config('database.connections.mysql')]);
    $this->cohortRun = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'mlb', 'run_type' => 'training',
        'model_version' => 'synthetic-only', 'feature_version' => 'synthetic-only', 'blend_version' => 'synthetic-only',
        'config_hash' => hash('sha256', 'synthetic-cohort'), 'status' => 'completed', 'started_at' => now()]);
    DB::connection('cohort_peer')->statement('SET SESSION innodb_lock_wait_timeout = 1');
});

afterEach(function () {
    foreach ([DB::connection(), DB::connection('cohort_peer')] as $connection) {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
    DB::purge('cohort_peer');
});

function syntheticCohortArtifact(string $run): array
{
    $id = (string) Str::uuid();

    return ['id' => $id, 'training_run_id' => $run, 'sport' => 'mlb', 'market_type' => 'multi_market',
        'model_type' => 'synthetic-only', 'model_version' => 'synthetic-only', 'feature_version' => 'synthetic-only',
        'dataset_hash' => hash('sha256', 'synthetic'), 'artifact_path' => 'synthetic-no-file',
        'artifact_hash' => hash('sha256', $id), 'status' => 'challenger'];
}

it('uses actual transaction isolation rather than its unchanged session default', function () {
    $connection = DB::connection();
    $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connection->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $connection->beginTransaction();
    expect($connection->scalar('SELECT @@SESSION.transaction_isolation', [], false))->toBe('REPEATABLE-READ');
    expect(fn () => (new MlbArtifactCohortSerializer)->run($connection, fn () => null))
        ->toThrow(RuntimeException::class, 'active REPEATABLE-READ');
    $connection->rollBack();
    $connection->beginTransaction();
    (new MlbArtifactCohortSerializer)->run($connection, fn () => MlbArtifactCohortSerializer::assertOwned($connection));
});

it('locks an empty MLB range against a competing insert', function () {
    $connection = DB::connection();
    $connection->beginTransaction();
    $row = syntheticCohortArtifact($this->cohortRun->id);
    (new MlbArtifactCohortSerializer)->run($connection, function () use ($connection, $row) {
        MlbArtifactCohortSerializer::assertOwned($connection);
        try {
            DB::connection('cohort_peer')->table('model_artifacts')->insert($row);
            test()->fail('Competing insert bypassed the empty cohort range.');
        } catch (QueryException $exception) {
            expect((int) ($exception->errorInfo[1] ?? 0))->toBe(1205);
        }
    });
    $connection->rollBack();
    DB::connection('cohort_peer')->table('model_artifacts')->insert($row);
    expect($connection->table('model_artifacts')->where('id', $row['id'])->exists())->toBeTrue();
});

it('locks all MLB statuses and model families against membership changes and a competing serializer', function () {
    $connection = DB::connection();
    $row = syntheticCohortArtifact($this->cohortRun->id);
    $row['model_type'] = 'mlb_period_bundle';
    $row['status'] = 'retired';
    $connection->table('model_artifacts')->insert($row);
    $connection->beginTransaction();
    (new MlbArtifactCohortSerializer)->run($connection, function () use ($row) {
        $peer = DB::connection('cohort_peer');
        try {
            $peer->table('model_artifacts')->where('id', $row['id'])->update(['status' => 'promoted']);
            test()->fail('Competing membership mutation bypassed the cohort range.');
        } catch (QueryException $exception) {
            expect((int) ($exception->errorInfo[1] ?? 0))->toBe(1205);
        }
        $peer->beginTransaction();
        try {
            (new MlbArtifactCohortSerializer)->run($peer, fn () => test()->fail('Competing serializer acquired the held range.'));
        } catch (QueryException $exception) {
            expect((int) ($exception->errorInfo[1] ?? 0))->toBe(1205);
        } finally {
            $peer->rollBack();
        }
    });
});

it('declines same-PDO commit and reopen authority using native transaction event identity', function () {
    $connection = DB::connection();
    $connection->beginTransaction();
    expect(fn () => (new MlbArtifactCohortSerializer)->run($connection, function () use ($connection) {
        $connection->commit();
        $connection->beginTransaction();
        MlbArtifactCohortSerializer::assertOwned($connection);
    }))->toThrow(RuntimeException::class, 'transaction or write PDO changed');
});
