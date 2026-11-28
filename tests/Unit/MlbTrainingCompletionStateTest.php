<?php

use App\Services\ML\TrainingAttemptService;
use App\Services\MLB\MlbTrainingCompletionState;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Tests\Support\SingleActorSqliteMlbArtifactCohortSerializer;

function completionStateFixture(Closure $test): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite', 'name' => 'completion_state']);
    $connection->statement('CREATE TABLE model_runs (id TEXT PRIMARY KEY, sport TEXT, run_type TEXT, status TEXT, model_version TEXT, feature_version TEXT, blend_version TEXT, config_hash TEXT, code_version TEXT, metadata TEXT, parameters TEXT)');
    $connection->statement('CREATE TABLE model_training_attempts (id INTEGER PRIMARY KEY, cycle_run_id TEXT, attempt_number INTEGER, owner_token TEXT, python_run_id TEXT, status TEXT, started_at TEXT, lease_expires_at TEXT, output_hash TEXT, result TEXT, failure_message TEXT)');
    $connection->statement('CREATE TABLE model_training_workers (id TEXT PRIMARY KEY, token_hash TEXT, expires_at TEXT, revoked_at TEXT, sports TEXT, abilities TEXT)');
    $connection->statement('CREATE TABLE model_training_uploads (id INTEGER PRIMARY KEY, attempt_id INTEGER, path TEXT, disk TEXT, object_key TEXT, sha256 TEXT, bytes INTEGER)');
    $connection->table('model_training_workers')->insert(['id' => '00000000-0000-4000-8000-000000000004', 'token_hash' => hash('sha256', 'synthetic-only'),
        'expires_at' => '2026-07-01 12:00:00', 'revoked_at' => null, 'sports' => '["mlb"]', 'abilities' => '["training:complete"]']);
    $connection->table('model_training_uploads')->insert(['id' => 1, 'attempt_id' => 1, 'path' => 'manifest.json', 'disk' => 'synthetic-only',
        'object_key' => 'synthetic/immutable', 'sha256' => hash('sha256', 'synthetic-only'), 'bytes' => 1]);
    $connection->table('model_runs')->insert(['id' => '00000000-0000-4000-8000-000000000001', 'sport' => 'mlb', 'run_type' => 'weekly_training_cycle', 'status' => 'running', 'model_version' => 'synthetic', 'feature_version' => 'synthetic', 'blend_version' => 'synthetic', 'config_hash' => hash('sha256', 'synthetic'), 'code_version' => null, 'metadata' => '{"training_specification":{"threads":1}}', 'parameters' => str_repeat('x', 8388608)]);
    $connection->table('model_training_attempts')->insert(['id' => 1, 'cycle_run_id' => '00000000-0000-4000-8000-000000000001', 'attempt_number' => 1,
        'owner_token' => '00000000-0000-4000-8000-000000000002', 'python_run_id' => '00000000-0000-4000-8000-000000000003',
        'status' => 'running', 'started_at' => '2026-06-30 12:00:00', 'lease_expires_at' => '2026-06-30 13:00:00', 'output_hash' => null, 'result' => null, 'failure_message' => str_repeat('x', 8388608)]);
    $previous = Model::getConnectionResolver();
    $resolver = new ConnectionResolver(['completion_state' => $connection]);
    $resolver->setDefaultConnection('completion_state');
    Model::setConnectionResolver($resolver);
    $connection->enableQueryLog();
    try {
        $test($connection);
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
        $connection->disconnect();
        if ($previous === null) {
            Model::unsetConnectionResolver();
        } else {
            Model::setConnectionResolver($previous);
        }
    }
}

it('loads current completion metadata and replay headers without unrelated large bodies', function () {
    completionStateFixture(function ($connection) {
        $cycle = MlbTrainingCompletionState::cycle($connection, '00000000-0000-4000-8000-000000000001', false);
        $attempt = MlbTrainingCompletionState::latestAttempt($connection, $cycle->id);
        expect($cycle->metadata)->toBe(['training_specification' => ['threads' => 1]])
            ->and($attempt->getRawOriginal('started_at'))->toBe('2026-06-30 12:00:00')
            ->and($attempt->output_hash)->toBeNull();
        $sql = implode("\n", array_column($connection->getQueryLog(), 'query'));
        expect($sql)->not->toContain('select *')->not->toContain('parameters')->not->toContain('failure_message');
    });
});

it('refuses oversized current cycle metadata before hydration', function () {
    completionStateFixture(function ($connection) {
        $connection->table('model_runs')->update(['metadata' => '{"x":"'.str_repeat('x', 8388608).'"}']);
        expect(fn () => MlbTrainingCompletionState::cycle($connection, '00000000-0000-4000-8000-000000000001', false))
            ->toThrow(RuntimeException::class, 'byte budget');
    });
});

it('refuses oversized current receipt JSON before hydration', function () {
    completionStateFixture(function ($connection) {
        $connection->table('model_training_attempts')->update(['result' => '{"x":"'.str_repeat('x', 8388608).'"}']);
        expect(fn () => MlbTrainingCompletionState::latestAttempt($connection, '00000000-0000-4000-8000-000000000001'))
            ->toThrow(RuntimeException::class, 'byte budget');
    });
});

it('refuses oversized SQLite-affinity owner fields through scalar CASE projection', function () {
    completionStateFixture(function ($connection) {
        $connection->table('model_training_attempts')->update(['owner_token' => str_repeat('x', 8388608)]);
        expect(fn () => MlbTrainingCompletionState::latestAttempt($connection, '00000000-0000-4000-8000-000000000001'))
            ->toThrow(RuntimeException::class, 'scalar exceeds');
    });
});

it('checks lexical structure before decoding completion JSON', function () {
    completionStateFixture(function ($connection) {
        $connection->table('model_runs')->update(['metadata' => '{"x":['.implode(',', array_fill(0, 30000, '0')).']}']);
        expect(fn () => MlbTrainingCompletionState::cycle($connection, '00000000-0000-4000-8000-000000000001', false))
            ->toThrow(RuntimeException::class, 'token budget');
    });
});

it('loads bounded credential scopes and exact upload binding headers', function () {
    completionStateFixture(function ($connection) {
        $worker = MlbTrainingCompletionState::worker($connection, '00000000-0000-4000-8000-000000000004');
        $uploads = MlbTrainingCompletionState::uploads($connection, 1, false);
        expect($worker->sports)->toBe(['mlb'])->and($worker->abilities)->toBe(['training:complete'])
            ->and($uploads)->toHaveCount(1)->and($uploads[0]['bytes'])->toBe(1)
            ->and($uploads[0]['path'])->toBe('manifest.json');
    });
});

it('refuses oversized current credential scopes before decode', function () {
    completionStateFixture(function ($connection) {
        $connection->table('model_training_workers')->update(['sports' => '["'.str_repeat('x', 8388608).'"]']);
        expect(fn () => MlbTrainingCompletionState::worker($connection, '00000000-0000-4000-8000-000000000004'))
            ->toThrow(RuntimeException::class, 'scope exceeds its byte budget');
    });
});

it('refuses upload binding growth and oversized paths', function () {
    completionStateFixture(function ($connection) {
        $connection->table('model_training_uploads')->update(['object_key' => str_repeat('x', 8388608)]);
        expect(fn () => MlbTrainingCompletionState::uploads($connection, 1, false))
            ->toThrow(RuntimeException::class, 'scalar budget');
    });
});

it('does not let the functional cohort collaborator mint source authority for public completion', function () {
    completionStateFixture(function ($connection) {
        $attempt = MlbTrainingCompletionState::latestAttempt($connection, '00000000-0000-4000-8000-000000000001');
        $called = false;
        $connection->transaction(function () use ($connection, $attempt, &$called) {
            (new SingleActorSqliteMlbArtifactCohortSerializer)->run($connection, function () use ($attempt, &$called) {
                expect(fn () => (new TrainingAttemptService)->completeMlb($attempt, hash('sha256', 'synthetic'), [], function () use (&$called) {
                    $called = true;

                    return [];
                }))->toThrow(RuntimeException::class, 'matching active source-first fence');
            });
        });
        expect($called)->toBeFalse();
    });
});
