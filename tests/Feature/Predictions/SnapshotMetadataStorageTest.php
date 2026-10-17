<?php

use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\PredictionFeatureSnapshot;
use App\Models\SnapshotMetadataPayload;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use App\Services\Predictions\SnapshotMetadataStorage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function sharedMetadataFixture(array $overrides = []): PredictionFeatureSnapshot
{
    return PredictionFeatureSnapshot::query()->create(array_replace([
        'sport' => 'mlb', 'prediction_table' => 'mlb_predictions', 'prediction_id' => 1, 'game_id' => 1,
        'snapshot_run_id' => (string) Str::uuid(), 'model_version' => 'rules-v1',
        'feature_version' => 'core-v1', 'blend_version' => 'baseline-v1',
        'features' => ['home_elo' => 1500], 'outputs' => ['predicted_total' => 8.5],
        'market_context' => ['vegas_spread' => -1.5], 'feature_hash' => str_repeat('a', 64),
        'model_metadata' => ['detail' => str_repeat('history', 500), 'probability' => 0.625],
        'generated_at' => '2026-10-16 10:00:00', 'game_start_at' => '2026-10-16 20:00:00',
        'features_available_at' => '2026-10-16 09:00:00', 'pregame_safe' => true,
        'availability_status' => 'observed_pregame', 'source_timestamps' => ['observed_at' => '2026-10-16 09:00:00'],
        'lineage_metadata' => ['point_in_time_verified' => true],
    ], $overrides))->fresh();
}

it('stores identical metadata once and preserves exact types unicode and list order', function () {
    $storage = app(SnapshotMetadataStorage::class);
    $metadata = ['float' => 1.0, 'integer' => 1, 'unicode' => 'Hawai‘i', 'null' => null, 'items' => [3, 1, 2]];
    $reference = $storage->store($metadata);

    expect($storage->store($metadata))->toBe($reference)
        ->and(SnapshotMetadataPayload::query()->count())->toBe(1)
        ->and($storage->resolve(json_encode($reference)))->toBe($metadata)
        ->and($storage->resolve(json_encode(['ordinary' => 'inline'])))->toBe(['ordinary' => 'inline'])
        ->and($storage->resolve(null))->toBeNull();
});

it('reads and serializes shared metadata without rewriting the stored reference', function () {
    $storage = app(SnapshotMetadataStorage::class);
    $metadata = ['items' => range(1, 20)];
    $snapshot = sharedMetadataFixture(['model_metadata' => $storage->store($metadata)]);
    $raw = $snapshot->getRawOriginal('model_metadata');
    DB::enableQueryLog();
    DB::flushQueryLog();

    expect($snapshot->model_metadata)->toBe($metadata)
        ->and($snapshot->model_metadata)->toBe($metadata)
        ->and($snapshot->toArray()['model_metadata'])->toBe($metadata)
        ->and(count(DB::getQueryLog()))->toBe(0);
    DB::disableQueryLog();
    $snapshot->availability_status = 'verified_reconstruction';
    $snapshot->save();

    expect($snapshot->fresh()->getRawOriginal('model_metadata'))->toBe($raw);
    $snapshot->model_metadata = ['replacement' => true];
    expect($snapshot->model_metadata)->toBe(['replacement' => true]);
    $snapshot->save();
    expect($snapshot->fresh()->model_metadata)->toBe(['replacement' => true]);
});

it('keeps null and empty metadata distinct', function () {
    expect(sharedMetadataFixture(['model_metadata' => null])->fresh()->model_metadata)->toBeNull()
        ->and(sharedMetadataFixture(['model_metadata' => []])->fresh()->model_metadata)->toBe([]);
});

it('fails closed for missing malformed and corrupted shared metadata', function (string $damage) {
    $storage = app(SnapshotMetadataStorage::class);
    $reference = $storage->store(['diagnostic' => str_repeat('data', 100)]);
    $payload = SnapshotMetadataPayload::query()->firstOrFail();
    match ($damage) {
        'missing' => $payload->delete(),
        'compressed-checksum' => $payload->update(['compressed_sha256' => str_repeat('0', 64)]),
        'json-checksum' => $payload->update(['payload' => base64_encode(gzencode('{broken-json}')), 'compressed_bytes' => strlen(gzencode('{broken-json}')), 'compressed_sha256' => hash('sha256', gzencode('{broken-json}'))]),
        'size' => $payload->update(['json_bytes' => 1]),
        'payload' => $payload->update(['payload' => 'invalid-base64!']),
        'malformed-reference' => $reference .= ':invalid',
    };

    expect(fn () => $storage->resolve(json_encode($reference)))->toThrow(RuntimeException::class);
})->with(['missing', 'compressed-checksum', 'json-checksum', 'size', 'payload', 'malformed-reference']);

it('enforces the metadata size limit before persisting', function () {
    config(['snapshot-storage.maximum_json_bytes' => 20]);
    expect(fn () => app(SnapshotMetadataStorage::class)->store(['large' => str_repeat('x', 100)]))
        ->toThrow(RuntimeException::class)
        ->and(SnapshotMetadataPayload::query()->count())->toBe(0);
});

it('shares new large MLB metadata only when enabled', function () {
    $storage = app(SnapshotMetadataStorage::class);
    $metadata = ['large' => str_repeat('diagnostic', 500)];
    expect($storage->prepare($metadata, 'mlb'))->toBe($metadata);
    config(['snapshot-storage.share_metadata' => true]);
    expect($storage->prepare($metadata, 'nfl'))->toBe($metadata)
        ->and($storage->prepare(['small' => true], 'mlb'))->toBe(['small' => true])
        ->and($storage->prepare(null, 'mlb'))->toBeNull()
        ->and($storage->isReference($storage->prepare($metadata, 'mlb')))->toBeTrue();
    config(['snapshot-storage.maximum_json_bytes' => 20]);
    expect($storage->prepare($metadata, 'mlb'))->toBe($metadata);
});

it('preserves separate recorder observations and provenance while sharing their metadata', function () {
    config(['snapshot-storage.share_metadata' => true]);
    $recorder = app(PredictionFeatureSnapshotRecorder::class);
    $game = new Game(['game_date' => '2026-10-17', 'game_time' => '20:00:00']);
    $game->id = 1;
    $prediction = new Prediction(['game_id' => 1]);
    $prediction->id = 1;
    $metadata = ['diagnostic' => str_repeat('source', 1000)];
    $first = $recorder->record($prediction, $game, 'mlb', ['model_metadata' => $metadata],
        ['generated_at' => '2026-10-16 10:00:00', 'source_timestamps' => ['source' => 'first']]);
    $game->id = 2;
    $prediction->id = 2;
    $prediction->game_id = 2;
    $second = $recorder->record($prediction, $game, 'mlb', ['model_metadata' => $metadata],
        ['generated_at' => '2026-10-16 11:00:00', 'source_timestamps' => ['source' => 'second']]);

    expect($first->snapshot_run_id)->not->toBe($second->snapshot_run_id)
        ->and($first->game_id)->toBe(1)->and($second->game_id)->toBe(2)
        ->and($first->generated_at->toDateTimeString())->toBe('2026-10-16 10:00:00')
        ->and($second->source_timestamps)->toBe(['source' => 'second'])
        ->and($first->model_metadata)->toBe($metadata)->and($second->fresh()->model_metadata)->toBe($metadata)
        ->and($first->feature_hash)->toBe($second->feature_hash)
        ->and(PredictionFeatureSnapshot::query()->count())->toBe(2)
        ->and(SnapshotMetadataPayload::query()->count())->toBe(1);
});

it('previews compacts idempotently and restores a bounded batch without changing other columns', function () {
    $first = sharedMetadataFixture();
    $second = sharedMetadataFixture(['prediction_id' => 2, 'game_id' => 2, 'generated_at' => '2026-10-16 11:00:00']);
    $outside = sharedMetadataFixture(['sport' => 'nfl']);
    $before = $first->getRawOriginal();
    $options = ['--through-id' => $second->id, '--limit' => 1];
    expect(Artisan::call('sports:compact-snapshot-metadata', $options))->toBe(0)
        ->and($first->fresh()->getRawOriginal())->toBe($before)
        ->and(SnapshotMetadataPayload::query()->count())->toBe(0);

    expect(Artisan::call('sports:compact-snapshot-metadata', [...$options, '--apply' => true]))->toBe(0);
    $after = $first->fresh()->getRawOriginal();
    unset($before['model_metadata'], $after['model_metadata']);
    expect($after)->toBe($before)
        ->and($first->fresh()->model_metadata)->toBe($second->model_metadata)
        ->and($second->fresh()->getRawOriginal('model_metadata'))->toBe($second->getRawOriginal('model_metadata'))
        ->and($outside->fresh()->getRawOriginal())->toBe($outside->getRawOriginal());
    expect(Artisan::call('sports:compact-snapshot-metadata', [...$options, '--limit' => 2, '--apply' => true]))->toBe(0)
        ->and(SnapshotMetadataPayload::query()->count())->toBe(1);
    $summary = json_decode(trim(Artisan::output()), true);
    expect($summary['changed'])->toBe(1);
    expect(Artisan::call('sports:compact-snapshot-metadata', [...$options, '--limit' => 2, '--restore' => true, '--apply' => true]))->toBe(0)
        ->and($first->fresh()->model_metadata)->toBe($second->fresh()->model_metadata)
        ->and(json_decode($first->fresh()->getRawOriginal('model_metadata'), true))->toBe($first->model_metadata);
});

it('rolls back the observation change when stored payload verification fails', function () {
    $first = sharedMetadataFixture();
    $before = $first->getRawOriginal();
    $storage = app(SnapshotMetadataStorage::class);
    $storage->store($first->model_metadata);
    SnapshotMetadataPayload::query()->update(['payload' => 'damaged']);

    expect(Artisan::call('sports:compact-snapshot-metadata', ['--through-id' => $first->id, '--apply' => true]))->toBe(1)
        ->and($first->fresh()->getRawOriginal())->toBe($before);
});

it('rejects unbounded or invalid compaction requests', function (array $options) {
    expect(Artisan::call('sports:compact-snapshot-metadata', $options))->toBe(2);
})->with([[[]], [['--through-id' => 0]], [['--through-id' => 1, '--limit' => 1001]], [['--through-id' => 1, '--after-id' => 2]], [['--through-id' => 1, '--sport' => 'unknown']]]);

it('batch loads shared metadata and hides its storage representation from serialization', function () {
    $metadata = ['details' => str_repeat('source', 1000)];
    $reference = app(SnapshotMetadataStorage::class)->store($metadata);
    foreach (range(1, 10) as $id) {
        sharedMetadataFixture(['prediction_id' => $id, 'game_id' => $id, 'model_metadata' => $reference]);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $snapshots = PredictionFeatureSnapshot::query()->get();
    foreach ($snapshots as $snapshot) {
        expect($snapshot->model_metadata)->toBe($metadata)
            ->and($snapshot->toArray())->not->toHaveKey('metadata_payload')
            ->and($snapshot->getRawOriginal('model_metadata'))->toBe(json_encode($reference));
    }
    expect(count(DB::getQueryLog()))->toBe(2);
    DB::disableQueryLog();
});
