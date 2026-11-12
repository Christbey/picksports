<?php

use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\PredictionFeatureSnapshotArchive;
use App\Services\ProviderData\CompressedJsonPayloadStorage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['provider-data.storage.disk' => 'provider-local']);
    Storage::fake('provider-local');
});

function featureArchiveFixture(): PredictionFeatureSnapshot
{
    return PredictionFeatureSnapshot::query()->create([
        'sport' => 'nfl', 'prediction_table' => 'nfl_predictions', 'prediction_id' => 1, 'game_id' => 1,
        'snapshot_run_id' => (string) Str::uuid(), 'model_version' => 'rules-v1',
        'feature_version' => 'core-v1', 'blend_version' => 'nfl-multi-signal-v1',
        'features' => ['home_elo' => 1500, 'details' => [3, 1, 2]],
        'outputs' => ['predicted_spread' => 3.5],
        'model_metadata' => ['legacy' => ['spread' => 3.5, 'detail' => 'frozen'],
            'analysis_layer' => ['calculated_edge' => ['market_spread' => -2.5, 'market_total' => null]]],
        'generated_at' => '2026-09-10 09:00:00',
    ])->fresh();
}

it('resolves archived fields from one object and preserves SQL projections', function () {
    $inline = featureArchiveFixture();
    $raw = $inline->getRawOriginal();
    $archive = app(PredictionFeatureSnapshotArchive::class);
    $manifest = $archive->archive($raw['features'], $raw['model_metadata']);
    DB::table('prediction_feature_snapshots')->where('id', $inline->id)
        ->update($archive->projections($manifest, $raw['model_metadata']));

    $expected = app(CompressedJsonPayloadStorage::class)->read($manifest);
    $storage = Mockery::mock(CompressedJsonPayloadStorage::class);
    $storage->shouldReceive('read')->once()->with($manifest)->andReturn($expected);
    app()->instance(CompressedJsonPayloadStorage::class, $storage);

    $snapshot = PredictionFeatureSnapshot::query()->select([
        'id', 'features', 'model_metadata', 'model_metadata->legacy->spread as legacy_spread',
        'model_metadata->analysis_layer->calculated_edge->market_spread as market_spread',
        'model_metadata->analysis_layer->calculated_edge->market_total as market_total',
    ])->findOrFail($inline->id);

    expect((float) $snapshot->legacy_spread)->toBe(3.5)
        ->and((float) $snapshot->market_spread)->toBe(-2.5)
        ->and($snapshot->market_total)->toBeNull()
        ->and($snapshot->features)->toBe($inline->features)
        ->and($snapshot->model_metadata)->toBe($inline->model_metadata)
        ->and($snapshot->toArray()['features'])->toBe($inline->features);
});

it('fails closed when an archived snapshot object is missing', function () {
    $raw = featureArchiveFixture()->getRawOriginal();
    $archive = app(PredictionFeatureSnapshotArchive::class);
    $manifest = $archive->archive($raw['features'], $raw['model_metadata']);
    $snapshot = new PredictionFeatureSnapshot;
    $snapshot->setRawAttributes($archive->projections($manifest, $raw['model_metadata']));
    Storage::disk('provider-local')->delete($manifest['object_key']);

    expect(fn () => $snapshot->features)->toThrow(RuntimeException::class)
        ->and(fn () => $snapshot->model_metadata)->toThrow(RuntimeException::class);
});

it('uploads the bounded pilot and leaves its inline snapshot intact', function () {
    $snapshot = featureArchiveFixture();
    $original = $snapshot->getRawOriginal();
    $arguments = ['snapshot' => [(string) $snapshot->id]];

    expect(Artisan::call('nfl:pilot-feature-snapshot-archive', $arguments))->toBe(0);
    $summary = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($summary['verified_snapshots'])->toBe(1)
        ->and($summary['archive_bytes'])->toBeGreaterThan(0)
        ->and(Storage::disk('provider-local')->allFiles())->toHaveCount(1)
        ->and($snapshot->fresh()->getRawOriginal())->toBe($original);

    expect(Artisan::call('nfl:pilot-feature-snapshot-archive', $arguments))->toBe(0)
        ->and(Storage::disk('provider-local')->allFiles())->toHaveCount(1)
        ->and($snapshot->fresh()->getRawOriginal())->toBe($original);
});

it('rejects a sample containing an ineligible ID before uploading', function () {
    $snapshot = featureArchiveFixture();

    expect(Artisan::call('nfl:pilot-feature-snapshot-archive', ['snapshot' => [(string) $snapshot->id, '999999']]))->toBe(2)
        ->and(Storage::disk('provider-local')->allFiles())->toBeEmpty();
});

it('uses prefetched bytes until the replay batch releases them', function () {
    $inline = featureArchiveFixture();
    $raw = $inline->getRawOriginal();
    $archive = app(PredictionFeatureSnapshotArchive::class);
    $manifest = $archive->archive($raw['features'], $raw['model_metadata']);
    $body = app(CompressedJsonPayloadStorage::class)->read($manifest);
    $projected = new PredictionFeatureSnapshot;
    $projected->setRawAttributes($archive->projections($manifest, $raw['model_metadata']), true);

    $storage = Mockery::mock(CompressedJsonPayloadStorage::class);
    $storage->shouldReceive('prefetch')->once()->with([7 => $manifest])->andReturn([$manifest['object_key'] => $body]);
    $storage->shouldReceive('read')->once()->with($manifest)->andReturn($body);
    app()->instance(CompressedJsonPayloadStorage::class, $storage);

    app(PredictionFeatureSnapshotArchive::class)->prefetch(collect([7 => $projected]));
    expect($projected->features)->toBe($inline->features)
        ->and($projected->model_metadata)->toBe($inline->model_metadata);
    $projected->releaseArchivePayload();
    expect($projected->features)->toBe($inline->features);
});
