<?php

use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\PredictionEvaluation;
use App\Services\MLB\MlbTabularDatasetAdmission;
use App\Services\MLB\MlbTabularSnapshotReader;
use App\Services\MLB\MlbTabularSourceFence;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use App\Services\Predictions\SnapshotMetadataStorage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\EloClockFixture;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->travelTo('2026-06-30T06:00:00Z');
    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);
    EloClockFixture::prepare();
    Http::preventStrayRequests();
    $this->metadataNativeDefault = DB::getDefaultConnection();
    $this->metadataNativePath = storage_path('framework/testing/native-compact-'.Str::uuid().'.csv');
    config(['database.connections.metadata_native_peer' => config('database.connections.'.$this->metadataNativeDefault)]);
    $peer = DB::connection('metadata_native_peer');
    $peer->statement("SET SESSION time_zone = '+00:00'");
    $peer->statement('SET SESSION innodb_lock_wait_timeout = 1');
    expect($peer->getPdo())->not->toBe(DB::connection()->getPdo());
});

afterEach(function () {
    DB::setDefaultConnection($this->metadataNativeDefault);
    foreach ([$this->metadataNativeDefault, 'metadata_native_peer'] as $name) {
        while (DB::connection($name)->transactionLevel() > 0) {
            DB::connection($name)->rollBack();
        }
    }
    DB::purge('metadata_native_peer');
    File::delete([$this->metadataNativePath, $this->metadataNativePath.'.manifest.json']);
    $this->travelBack();
});

function nativeCompactMetadataSource($test, int $diagnosticBytes = 8192): array
{
    $test->travelTo('2026-06-30T06:00:00Z');
    $game = Game::factory()->withSyntheticWallStart('America/Chicago')->create([
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'season' => 2026,
        'season_type' => '2', 'game_date' => '2026-07-02', 'game_time' => '19:10:00', 'status' => 'STATUS_SCHEDULED',
    ]);
    $prediction = Prediction::create(['game_id' => $game->id, 'predicted_spread' => 1.5, 'predicted_total' => 8.5, 'win_probability' => .57]);
    $snapshot = app(PredictionFeatureSnapshotRecorder::class)->recordCurrentMlb($prediction, $game, [], [
        'features' => ['signal' => 1], 'outputs' => ['predicted_spread' => 1.5, 'predicted_total' => 8.5, 'win_probability' => .57],
        'market_context' => [], 'model_metadata' => ['diagnostics' => str_repeat('x', $diagnosticBytes), 'real_zero' => 0, 'unknown' => null],
    ]);
    $test->travelTo('2026-07-04T06:00:00Z');
    $game->update(['status' => 'STATUS_FINAL', 'home_score' => 5, 'away_score' => 3]);
    PredictionEvaluation::create(['sport' => 'mlb', 'prediction_table' => 'mlb_predictions', 'prediction_id' => $prediction->id,
        'game_id' => $game->id, 'model_version' => $snapshot->model_version, 'feature_version' => $snapshot->feature_version,
        'blend_version' => $snapshot->blend_version, 'actuals' => ['actual_spread' => 2, 'actual_total' => 8],
        'errors' => [], 'market_comparison' => [], 'evaluated_at' => now()]);

    return [$game, $snapshot];
}

function compactNativeMetadata($snapshot): string
{
    expect(Artisan::call('sports:compact-snapshot-metadata', ['--after-id' => $snapshot->id - 1, '--through-id' => $snapshot->id, '--limit' => 1, '--apply' => true]))->toBe(0);
    $reference = DB::table('prediction_feature_snapshots')->useWritePdo()->where('id', $snapshot->id)->value('model_metadata');
    $value = json_decode($reference, true, flags: JSON_THROW_ON_ERROR);
    expect($value)->toStartWith(SnapshotMetadataStorage::REFERENCE_PREFIX);

    return substr($value, strlen(SnapshotMetadataStorage::REFERENCE_PREFIX));
}

it('preserves owned export and current source admission after compaction using only the admitted primary PDO', function () {
    [, $snapshot] = nativeCompactMetadataSource($this);
    $options = ['--path' => $this->metadataNativePath, '--include-metadata' => true];
    expect(Artisan::call('mlb:export-training-data', $options))->toBe(0);
    $csv = File::get($this->metadataNativePath);
    $proof = app(MlbTabularDatasetAdmission::class)->inspect($this->metadataNativePath);
    $hash = compactNativeMetadata($snapshot);
    // The pre-compaction CSV and manifest remain admissible against current compacted evidence.
    expect(app(MlbTabularDatasetAdmission::class)->inspect($this->metadataNativePath))->toBe($proof);
    $raw = DB::table('prediction_feature_snapshots')->useWritePdo()->where('id', $snapshot->id)->value('model_metadata');
    $connection = DB::connection();
    $originalRead = $connection->getReadPdo();
    $connection->setReadPdo(fn () => throw new RuntimeException('Unused replica must not resolve.'));
    try {
        expect(Artisan::call('mlb:export-training-data', $options))->toBe(0);
        $after = app(MlbTabularDatasetAdmission::class)->inspect($this->metadataNativePath);
        // Physical source bytes shrink; protected source identity and dataset bytes do not.
        expect($after['source_scope']['json_bytes'])->toBeLessThan($proof['source_scope']['json_bytes']);
        expect($after['references'])->toBe($proof['references'])
            ->and($after['dataset_sha256'])->toBe($proof['dataset_sha256'])
            ->and($after['dataset_schema_hash'])->toBe($proof['dataset_schema_hash'])
            ->and(File::get($this->metadataNativePath))->toBe($csv)
            ->and(app(MlbTabularSourceFence::class)->run($proof, fn () => DB::connection()->transactionLevel()))->toBe(1)
            ->and(DB::table('prediction_feature_snapshots')->useWritePdo()->where('id', $snapshot->id)->value('model_metadata'))->toBe($raw)
            ->and(DB::table('snapshot_metadata_payloads')->useWritePdo()->where('sha256', $hash)->exists())->toBeTrue();
    } finally {
        $connection->setReadPdo($originalRead);
    }
});

it('refuses oversized expanded or corrupted compacted metadata before trusted CSV output', function () {
    [, $large] = nativeCompactMetadataSource($this, 300000);
    expect(Artisan::call('mlb:export-training-data', ['--path' => $this->metadataNativePath]))->toBe(1);
    compactNativeMetadata($large);
    expect(Artisan::call('mlb:export-training-data', ['--path' => $this->metadataNativePath]))->toBe(1)
        ->and(File::exists($this->metadataNativePath))->toBeFalse();
    [, $small] = nativeCompactMetadataSource($this);
    $hash = compactNativeMetadata($small);
    DB::table('snapshot_metadata_payloads')->where('sha256', $hash)->update(['compressed_sha256' => str_repeat('0', 64)]);
    $reader = app(MlbTabularSnapshotReader::class);
    $base = $reader->query()->where('s.id', $small->id);
    $identities = iterator_to_array($reader->batches($base))[0];
    $bytes = $tokens = 0;
    expect(fn () => $reader->hydrate($base, $identities, $bytes, $tokens))->toThrow(RuntimeException::class, 'checksum');
});

it('admits no compressed bodies from an over-budget aggregate and rejects negative sizes in native unsigned storage', function () {
    $hash = null;
    for ($index = 0; $index < 10; $index++) {
        [, $snapshot] = nativeCompactMetadataSource($this, 235000);
        $hash = compactNativeMetadata($snapshot);
    }
    try {
        DB::table('snapshot_metadata_payloads')->where('sha256', $hash)->update(['json_bytes' => -1]);
        test()->fail('Native unsigned metadata size must reject a negative value.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1] ?? null)->toBe(1264);
    }
    $selectedBytes = null;
    DB::listen(function (QueryExecuted $event) use (&$selectedBytes) {
        if ($selectedBytes !== null || ! str_contains($event->sql, 'metadata_budget') || ! str_contains($event->sql, 'snapshot_metadata_payloads')) {
            return;
        }
        $selectedBytes = 0;
        foreach ($event->connection->select($event->sql, $event->bindings, false) as $payload) {
            $selectedBytes += strlen((string) $payload->payload);
        }
    });
    $reader = app(MlbTabularSnapshotReader::class);
    $base = $reader->query();
    $identities = iterator_to_array($reader->batches($base))[0];
    $bytes = $tokens = 0;
    expect(fn () => $reader->hydrate($base, $identities, $bytes, $tokens))->toThrow(RuntimeException::class)
        ->and($selectedBytes)->toBe(0);
});

it('uses a current locking payload read after an old repeatable-read view and blocks an independent PDO owner', function () {
    [, $snapshot] = nativeCompactMetadataSource($this);
    $hash = compactNativeMetadata($snapshot);
    $reader = app(MlbTabularSnapshotReader::class);
    $base = $reader->query();
    $identities = iterator_to_array($reader->batches($base))[0];
    $connection = DB::connection();
    $peer = DB::connection('metadata_native_peer');
    $connection->beginTransaction();
    $old = $connection->table('snapshot_metadata_payloads')->where('sha256', $hash)->value('compressed_sha256');
    $peer->table('snapshot_metadata_payloads')->where('sha256', $hash)->update(['compressed_sha256' => str_repeat('0', 64)]);
    expect($connection->table('snapshot_metadata_payloads')->where('sha256', $hash)->value('compressed_sha256'))->toBe($old);
    $bytes = $tokens = 0;
    expect(fn () => $reader->hydrate($base, $identities, $bytes, $tokens, true))->toThrow(RuntimeException::class, 'compressed metadata checksum mismatch');
    $peer->beginTransaction();
    try {
        $peer->table('snapshot_metadata_payloads')->where('sha256', $hash)->lockForUpdate()->first(['sha256']);
        test()->fail('Independent PDO must wait behind the current payload row owner.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1] ?? null)->toBe(1205)
            ->and($exception->getSql())->toContain('snapshot_metadata_payloads', 'for update');
    } finally {
        $peer->rollBack();
        $connection->rollBack();
    }
});
