<?php

use App\Models\AiGeneration;
use App\Services\AI\AiGenerationRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    $main = DB::connection();
    if ($main->getDriverName() !== 'mysql' || $main->getDatabaseName() !== 'picksports_test_readiness'
        || Env::get('PICKSPORTS_TEST_MYSQL_AUX_SCHEMA') !== 'picksports_test_readiness_ai_aux') {
        throw new RuntimeException('Explicit owned MySQL main and auxiliary fixture declaration required.');
    }
    Http::preventStrayRequests();
    Queue::fake();
    $mainIdentity = $main->selectOne('SELECT DATABASE() AS db, @@server_uuid AS server_uuid, @@session.sql_mode AS sql_mode');
    expect($mainIdentity->db)->toBe('picksports_test_readiness');
    expect(str_contains($mainIdentity->sql_mode, 'STRICT_TRANS_TABLES') || str_contains($mainIdentity->sql_mode, 'STRICT_ALL_TABLES'))->toBeTrue();
    expect($main->transactionLevel())->toBe(0);
    Artisan::call('migrate:fresh', ['--force' => true]);
    $this->travelTo(now()->startOfSecond());
    $configuration = $main->getConfig();
    if (! empty($configuration['url']) || ! empty($configuration['read']) || ! empty($configuration['write'])) {
        throw new RuntimeException('Auxiliary fixture refuses connection URL or read/write endpoint overrides.');
    }
    $configuration['database'] = 'picksports_test_readiness_ai_aux';
    $configuration['name'] = 'ai_native_aux';
    config(['database.connections.ai_native_aux' => $configuration]);
    DB::purge('ai_native_aux');
    $aux = DB::connection('ai_native_aux');
    expect($aux->getName())->toBe('ai_native_aux');
    $auxIdentity = $aux->selectOne('SELECT DATABASE() AS db, @@server_uuid AS server_uuid');
    expect($auxIdentity->db)->toBe('picksports_test_readiness_ai_aux')
        ->and($auxIdentity->server_uuid)->toBe($mainIdentity->server_uuid)
        ->and($aux->getDatabaseName())->not->toBe($main->getDatabaseName())
        ->and($aux->transactionLevel())->toBe(0);
    // The separate schema is created and permissioned only by the owned CI service setup.
    // LIKE copies real columns/indexes; this is not a foreign-key/cross-schema constraint certificate.
    $aux->statement('DROP TABLE IF EXISTS `ai_generations`');
    $aux->statement('CREATE TABLE `ai_generations` LIKE `picksports_test_readiness`.`ai_generations`');
    $this->aiNativeAuxDeclared = true;
});

afterEach(function () {
    if (isset($this->aiNativeAuxDeclared)) {
        $aux = DB::connection('ai_native_aux');
        if ($aux->getDatabaseName() !== 'picksports_test_readiness_ai_aux'
            || $aux->selectOne('SELECT DATABASE() AS db')->db !== 'picksports_test_readiness_ai_aux') {
            throw new RuntimeException('Auxiliary cleanup target changed; refusing cleanup.');
        }
        while ($aux->transactionLevel() > 0) {
            $aux->rollBack();
        }
        $aux->statement('DROP TABLE IF EXISTS `ai_generations`');
        DB::purge('ai_native_aux');
    }
});

it('updates the supplied native generation in the separate owned schema without changing the same-key default row', function (string $operation) {
    $default = AiGeneration::factory()->create(['status' => 'running', 'purpose' => 'native_default_sentinel', 'public_id' => str_repeat('0', 26)]);
    $target = AiGeneration::factory()->make(['status' => 'running', 'purpose' => 'native_auxiliary', 'public_id' => str_repeat('0', 25).'1']);
    $target->setConnection('ai_native_aux')->forceFill(['id' => $default->getKey()])->saveOrFail();
    expect($target->getKey())->toBe($default->getKey());
    $beforeDefault = $default->fresh()->getRawOriginal();
    $beforeAux = $target->fresh()->getRawOriginal();
    $recorder = app(AiGenerationRecorder::class);
    $result = $operation === 'complete'
        ? $recorder->complete($target, ['synthetic' => true], 1)
        : $recorder->fail($target, 'synthetic_native_failure', 1);
    $afterDefault = $default->fresh()->getRawOriginal();
    $afterAux = $target->fresh()->getRawOriginal();
    fwrite(STDERR, json_encode(['native_case' => $operation, 'default_before' => $beforeDefault, 'default_after' => $afterDefault,
        'aux_before' => $beforeAux, 'aux_after' => $afterAux, 'returned_connection' => $result->getConnectionName()], JSON_THROW_ON_ERROR).PHP_EOL);
    expect($afterDefault)->toBe($beforeDefault)
        ->and($afterAux['status'])->toBe($operation === 'complete' ? 'completed' : 'failed')
        ->and($result->getConnectionName())->toBe('ai_native_aux')
        ->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(DB::connection('ai_native_aux')->transactionLevel())->toBe(0);
})->with(['complete', 'fail']);

it('rejects malformed JSON at the native storage boundary without changing the full prior row', function () {
    expect(DB::connection()->getSchemaBuilder()->getColumnType('ai_generations', 'metadata'))->toBe('json');
    $generation = AiGeneration::factory()->create(['cost_usd' => '0', 'metadata' => ['sentinel' => 'unchanged']]);
    $before = $generation->fresh()->getRawOriginal();
    $error = null;
    try {
        DB::table('ai_generations')->where('id', $generation->id)->update(['metadata' => '{']);
    } catch (QueryException $exception) {
        $error = $exception;
    }
    $after = $generation->fresh()->getRawOriginal();
    fwrite(STDERR, json_encode(['native_case' => 'malformed_json_storage', 'before' => $before, 'after' => $after,
        'SQLSTATE' => $error === null ? null : ($error->errorInfo[0] ?? null), 'driver_code' => $error === null ? null : ($error->errorInfo[1] ?? null)], JSON_THROW_ON_ERROR).PHP_EOL);
    expect($error)->toBeInstanceOf(QueryException::class)
        ->and($error?->errorInfo[0] ?? null)->toBe('22032')
        ->and((int) ($error?->errorInfo[1] ?? 0))->toBe(3140)
        ->and($after)->toBe($before)
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('rejects an invalid decimal scalar at the native storage boundary without changing the full prior row', function () {
    expect(DB::connection()->getSchemaBuilder()->getColumnType('ai_generations', 'cost_usd', true))->toStartWith('decimal(');
    $generation = AiGeneration::factory()->create(['cost_usd' => '0', 'metadata' => ['sentinel' => 'unchanged']]);
    $before = $generation->fresh()->getRawOriginal();
    $error = null;
    try {
        DB::table('ai_generations')->where('id', $generation->id)->update(['cost_usd' => 'not-a-decimal']);
    } catch (QueryException $exception) {
        $error = $exception;
    }
    $after = $generation->fresh()->getRawOriginal();
    fwrite(STDERR, json_encode(['native_case' => 'invalid_decimal_storage', 'input_bytes' => strlen('not-a-decimal'), 'before' => $before, 'after' => $after,
        'SQLSTATE' => $error === null ? null : ($error->errorInfo[0] ?? null), 'driver_code' => $error === null ? null : ($error->errorInfo[1] ?? null)], JSON_THROW_ON_ERROR).PHP_EOL);
    expect($error)->toBeInstanceOf(QueryException::class)
        ->and($error?->errorInfo[0] ?? null)->toBe('HY000')
        ->and((int) ($error?->errorInfo[1] ?? 0))->toBe(1366)
        ->and($after)->toBe($before)
        ->and(DB::connection()->transactionLevel())->toBe(0);
});
