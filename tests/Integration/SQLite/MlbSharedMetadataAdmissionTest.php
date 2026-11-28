<?php

use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\PredictionEvaluation;
use App\Services\MLB\MlbTabularDatasetAdmission;
use App\Services\MLB\MlbTabularDatasetExporter;
use App\Services\MLB\MlbTabularSnapshotReader;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use App\Services\Predictions\SnapshotMetadataStorage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->travelTo('2026-06-30T06:00:00Z');
    Http::preventStrayRequests();
    $this->metadataExportPath = storage_path('framework/testing/mlb-shared-metadata-'.Str::uuid().'.csv');
});

afterEach(function () {
    File::delete([$this->metadataExportPath, $this->metadataExportPath.'.manifest.json']);
    $this->travelBack();
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

function observedMlbMetadataSource($test, int $diagnosticBytes, array $extraMetadata = []): array
{
    $game = Game::factory()->withSyntheticWallStart('America/Chicago')->create([
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'season' => 2026,
        'season_type' => '2', 'game_date' => '2026-07-02', 'game_time' => '19:10:00', 'status' => 'STATUS_SCHEDULED',
    ]);
    $prediction = Prediction::create(['game_id' => $game->id, 'predicted_spread' => 1.5, 'predicted_total' => 8.5, 'win_probability' => .57]);
    $snapshot = app(PredictionFeatureSnapshotRecorder::class)->recordCurrentMlb($prediction, $game, [], [
        'features' => ['signal' => 1], 'outputs' => ['predicted_spread' => 1.5, 'predicted_total' => 8.5, 'win_probability' => .57],
        'market_context' => [], 'model_metadata' => ['diagnostics' => str_repeat('x', $diagnosticBytes), 'observed_zero' => 0, 'missing' => null, ...$extraMetadata],
    ]);
    $test->travelTo('2026-07-04T06:00:00Z');
    $game->update(['status' => 'STATUS_FINAL', 'home_score' => 5, 'away_score' => 3]);
    PredictionEvaluation::create(['sport' => 'mlb', 'prediction_table' => 'mlb_predictions', 'prediction_id' => $prediction->id,
        'game_id' => $game->id, 'model_version' => $snapshot->model_version, 'feature_version' => $snapshot->feature_version,
        'blend_version' => $snapshot->blend_version, 'actuals' => ['actual_spread' => 2, 'actual_total' => 8],
        'errors' => [], 'market_comparison' => [], 'evaluated_at' => now()]);

    return [$game, $snapshot];
}

it('preserves actual owned export bytes through lossless metadata compaction and restore', function () {
    [, $snapshot] = observedMlbMetadataSource($this, 8192);
    $options = ['--path' => $this->metadataExportPath, '--include-metadata' => true];
    expect(Artisan::call('mlb:export-training-data', $options))->toBe(0);
    $before = File::get($this->metadataExportPath);
    $beforeProof = app(MlbTabularDatasetAdmission::class)->inspect($this->metadataExportPath);
    $rawBefore = DB::table('prediction_feature_snapshots')->where('id', $snapshot->id)->value('model_metadata');
    $compact = ['--through-id' => $snapshot->id, '--limit' => 1, '--apply' => true];
    expect(Artisan::call('sports:compact-snapshot-metadata', $compact))->toBe(0);
    expect(app(MlbTabularDatasetAdmission::class)->inspect($this->metadataExportPath))->toBe($beforeProof);
    // SQLite proves the bounded current projection branch; not MySQL lock ownership.
    DB::transaction(function () use ($beforeProof) {
        $reader = app(MlbTabularSnapshotReader::class);
        $bytes = $tokens = 0;
        $rows = iterator_to_array(app(MlbTabularDatasetExporter::class)->rows($reader->query(), $beforeProof['references'], true, false, $bytes, $tokens, true));
        expect($rows)->toHaveCount(1)->and($rows[0][0])->not->toBeNull();
    });
    expect(Artisan::call('mlb:export-training-data', $options))->toBe(0)
        ->and(File::get($this->metadataExportPath))->toBe($before)
        ->and(Artisan::call('sports:compact-snapshot-metadata', [...$compact, '--restore' => true]))->toBe(0)
        ->and(Artisan::call('mlb:export-training-data', $options))->toBe(0)
        ->and(File::get($this->metadataExportPath))->toBe($before)
        ->and(DB::table('prediction_feature_snapshots')->where('id', $snapshot->id)->value('model_metadata'))->toBe($rawBefore);
});

it('does not let a compact metadata reference bypass the existing expanded row byte budget', function () {
    [, $snapshot] = observedMlbMetadataSource($this, 300000);
    $options = ['--path' => $this->metadataExportPath];
    expect(Artisan::call('mlb:export-training-data', $options))->toBe(1);
    expect(Artisan::call('sports:compact-snapshot-metadata', ['--through-id' => $snapshot->id, '--limit' => 1, '--apply' => true]))->toBe(0);
    expect(Artisan::call('mlb:export-training-data', $options))->toBe(1)
        ->and(File::exists($this->metadataExportPath))->toBeFalse();
});

it('refuses missing or corrupted compacted metadata without changing protected source bytes', function (string $variant) {
    [, $snapshot] = observedMlbMetadataSource($this, 8192);
    Artisan::call('sports:compact-snapshot-metadata', ['--through-id' => $snapshot->id, '--limit' => 1, '--apply' => true]);
    $reference = DB::table('prediction_feature_snapshots')->where('id', $snapshot->id)->value('model_metadata');
    $hash = substr(json_decode($reference, true, flags: JSON_THROW_ON_ERROR), strlen(SnapshotMetadataStorage::REFERENCE_PREFIX));
    if ($variant === 'missing') {
        DB::table('snapshot_metadata_payloads')->where('sha256', $hash)->delete();
    } else {
        DB::table('snapshot_metadata_payloads')->where('sha256', $hash)->update(match ($variant) {
            'checksum' => ['compressed_sha256' => str_repeat('0', 64)],
            'expanded-size' => ['json_bytes' => 1],
            'body-size' => ['payload' => str_repeat('x', 450000)],
        });
    }
    expect(Artisan::call('mlb:export-training-data', ['--path' => $this->metadataExportPath]))->toBe(1)
        ->and(File::exists($this->metadataExportPath))->toBeFalse()
        ->and(DB::table('prediction_feature_snapshots')->where('id', $snapshot->id)->value('model_metadata'))->toBe($reference);
})->with(['missing', 'checksum', 'expanded-size', 'body-size']);

it('resolves compacted evidence on the selected connection without consulting a colliding default payload', function () {
    $original = DB::getDefaultConnection();
    config(['database.connections.mlb_metadata_selected' => [...config('database.connections.sqlite'), 'database' => ':memory:']]);
    DB::setDefaultConnection('mlb_metadata_selected');
    try {
        $this->artisan('migrate:fresh', ['--database' => 'mlb_metadata_selected', '--force' => true])->assertSuccessful();
        [, $snapshot] = observedMlbMetadataSource($this, 8192);
        expect(Artisan::call('sports:compact-snapshot-metadata', ['--through-id' => $snapshot->id, '--limit' => 1, '--apply' => true]))->toBe(0);
        $selected = DB::connection('mlb_metadata_selected');
        $payload = $selected->table('snapshot_metadata_payloads')->first();
        DB::connection($original)->table('snapshot_metadata_payloads')->insert([
            'sha256' => $payload->sha256, 'compressed_sha256' => str_repeat('0', 64), 'json_bytes' => 1, 'compressed_bytes' => 1, 'payload' => 'bad',
        ]);
        DB::connection($original)->enableQueryLog();
        expect(Artisan::call('mlb:export-training-data', ['--path' => $this->metadataExportPath]))->toBe(0)
            ->and(DB::connection($original)->getQueryLog())->toBe([]);
    } finally {
        DB::connection($original)->disableQueryLog();
        DB::setDefaultConnection($original);
        DB::purge('mlb_metadata_selected');
    }
});

it('preserves the same JSON token and depth refusal after compaction', function (string $variant) {
    $extra = $variant === 'tokens' ? array_fill(0, 21000, 0) : 0;
    if ($variant === 'depth') {
        for ($depth = 0; $depth < 33; $depth++) {
            $extra = ['nested' => $extra];
        }
    }
    [, $snapshot] = observedMlbMetadataSource($this, 8192, ['extra' => $extra]);
    expect(Artisan::call('mlb:export-training-data', ['--path' => $this->metadataExportPath]))->toBe(1);
    expect(Artisan::call('sports:compact-snapshot-metadata', ['--through-id' => $snapshot->id, '--limit' => 1, '--apply' => true]))->toBe(0);
    expect(Artisan::call('mlb:export-training-data', ['--path' => $this->metadataExportPath]))->toBe(1)
        ->and(File::exists($this->metadataExportPath))->toBeFalse();
})->with(['tokens', 'depth']);

it('does not let an invalid negative metadata size subsidize other referenced bodies before allocation', function () {
    $snapshots = [];
    for ($index = 0; $index < 10; $index++) {
        $this->travelTo('2026-06-30T06:00:00Z');
        [, $snapshot] = observedMlbMetadataSource($this, 235000);
        $snapshots[] = $snapshot;
        expect(Artisan::call('sports:compact-snapshot-metadata', ['--after-id' => $snapshot->id - 1, '--through-id' => $snapshot->id, '--limit' => 1, '--apply' => true]))->toBe(0);
    }
    $reference = DB::table('prediction_feature_snapshots')->where('id', $snapshots[0]->id)->value('model_metadata');
    $hash = substr(json_decode($reference, true, flags: JSON_THROW_ON_ERROR), strlen(SnapshotMetadataStorage::REFERENCE_PREFIX));
    DB::table('snapshot_metadata_payloads')->where('sha256', $hash)->update(['json_bytes' => -5000000]);
    $selectedBytes = null;
    DB::listen(function (QueryExecuted $event) use (&$selectedBytes) {
        if ($selectedBytes !== null || ! str_contains($event->sql, 'metadata_budget') || ! str_contains($event->sql, 'snapshot_metadata_payloads')) {
            return;
        }
        // Re-read the exact emitted CASE query on the unchanged test connection.
        // This measures selected body bytes, rather than merely seeing a later exception.
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
