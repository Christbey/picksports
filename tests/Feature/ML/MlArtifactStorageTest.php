<?php

use App\Services\ML\MlArtifactStorage;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('ml-object-test');
    Storage::fake('ml-cache-test');
    Storage::fake('ml-source-test');

    config()->set('ml.storage.disk', 'ml-object-test');
    config()->set('ml.storage.cache_disk', 'ml-cache-test');
    config()->set('ml.storage.prefix', 'ml');
    config()->set('filesystems.disks.ml-object-test.driver', 'local');
});

it('stores private immutable ml objects and materializes verified local cache paths', function () {
    $run = app(ModelRunRecorder::class)->create(
        sport: 'nfl',
        runType: 'training',
        modelVersion: 'xgboost-v1',
        featureVersion: 'trusted-v1',
        blendVersion: 'challenger-v1',
    );
    $artifactId = (string) Str::uuid();
    $source = Storage::disk('ml-source-test');
    $source->put('model.json', '{"probability":0.61}');
    $source->put('dataset.csv', "feature,target\n1,1\n");
    $source->put('evaluation.json', '{"brier":0.19}');

    $storage = app(MlArtifactStorage::class);
    $artifact = $storage->storeArtifact($run, $artifactId, $source->path('model.json'));
    $dataset = $storage->storeDataset($run, $artifactId, $source->path('dataset.csv'));
    $report = $storage->storeReport($run, $artifactId, $source->path('evaluation.json'));

    expect($artifact->objectKey)
        ->toBe("ml/nfl/runs/{$run->id}/artifacts/{$artifactId}/model.json")
        ->and($dataset->objectKey)
        ->toBe("ml/nfl/runs/{$run->id}/datasets/{$artifactId}/dataset.csv")
        ->and($report->objectKey)
        ->toBe("ml/nfl/runs/{$run->id}/reports/{$artifactId}/evaluation.json")
        ->and($artifact->sha256)->toBe(hash_file('sha256', $source->path('model.json')))
        ->and($artifact->size)->toBe(filesize($source->path('model.json')))
        ->and($artifact->contentType)->toBe('application/json')
        ->and($dataset->contentType)->toBe('text/csv')
        ->and($artifact->uri)->toStartWith('file://')
        ->and(File::exists($artifact->localPath))->toBeTrue()
        ->and(hash_file('sha256', $artifact->localPath))->toBe($artifact->sha256);

    Storage::disk('ml-object-test')->assertExists([
        $artifact->objectKey,
        $dataset->objectKey,
        $report->objectKey,
    ]);
    expect(Storage::disk('ml-object-test')->getVisibility($artifact->objectKey))->toBe('private');

    Storage::disk('ml-cache-test')->delete($artifact->objectKey);
    $materialized = $storage->materialize(
        $artifact->disk,
        $artifact->objectKey,
        $artifact->sha256,
        $artifact->contentType,
    );

    expect(File::exists($materialized))->toBeTrue()
        ->and(hash_file('sha256', $materialized))->toBe($artifact->sha256);
});

it('refuses to overwrite a run scoped object with different content', function () {
    $run = app(ModelRunRecorder::class)->create(
        sport: 'nfl',
        runType: 'training',
        modelVersion: 'xgboost-v1',
        featureVersion: 'trusted-v1',
        blendVersion: 'challenger-v1',
    );
    $artifactId = (string) Str::uuid();
    $source = Storage::disk('ml-source-test');
    $source->put('model.ubj', 'first model');

    $storage = app(MlArtifactStorage::class);
    $stored = $storage->storeArtifact($run, $artifactId, $source->path('model.ubj'));
    $source->put('model.ubj', 'different model');

    expect(fn () => $storage->storeArtifact($run, $artifactId, $source->path('model.ubj')))
        ->toThrow(RuntimeException::class, 'Refusing to overwrite immutable ML object');

    expect(Storage::disk('ml-object-test')->get($stored->objectKey))->toBe('first model');
});

it('registers complete storage provenance and preserves artifact path compatibility', function () {
    config()->set('filesystems.disks.ml-object-test.driver', 's3');
    config()->set('filesystems.disks.ml-object-test.bucket', 'private-ml-space');

    $run = app(ModelRunRecorder::class)->create(
        sport: 'nfl',
        runType: 'training',
        modelVersion: 'xgboost-v1',
        featureVersion: 'trusted-v1',
        blendVersion: 'challenger-v1',
    );
    $artifactId = (string) Str::uuid();
    $source = Storage::disk('ml-source-test');
    $source->put('model.json', '{"model":"xgboost"}');
    $source->put('dataset.csv', "feature,target\n1,1\n");
    $source->put('report.json', '{"windows":4}');
    $datasetHash = hash_file('sha256', $source->path('dataset.csv'));

    $registry = app(ModelArtifactRegistry::class);
    $artifact = $registry->register(
        id: $artifactId,
        trainingRun: $run,
        marketType: 'win_probability',
        modelType: 'xgboost_classifier',
        modelVersion: 'xgboost-v1',
        featureVersion: 'trusted-v1',
        datasetHash: $datasetHash,
        artifactPath: $source->path('model.json'),
        metrics: ['validation' => ['brier' => 0.19]],
    );
    $artifact = $registry->attachDataset($artifact, $source->path('dataset.csv'));
    $artifact = $registry->attachEvaluationReport($artifact, $source->path('report.json'));

    expect($artifact->artifact_disk)->toBe('ml-object-test')
        ->and($artifact->artifact_uri)->toBe("s3://private-ml-space/{$artifact->artifact_object_key}")
        ->and($artifact->artifact_hash)->toBe(hash_file('sha256', $source->path('model.json')))
        ->and($artifact->artifact_size)->toBe(filesize($source->path('model.json')))
        ->and($artifact->artifact_content_type)->toBe('application/json')
        ->and($artifact->dataset_hash)->toBe($datasetHash)
        ->and($artifact->dataset_disk)->toBe('ml-object-test')
        ->and($artifact->dataset_content_type)->toBe('text/csv')
        ->and($artifact->evaluation_report_disk)->toBe('ml-object-test')
        ->and($artifact->evaluation_report_hash)->toBe(hash_file('sha256', $source->path('report.json')))
        ->and(File::exists($artifact->artifact_path))->toBeTrue()
        ->and($registry->materializeArtifact($artifact))->toBe($artifact->artifact_path);

    $run->refresh();
    expect(data_get($run->metadata, 'artifact_storage.object_key'))->toBe($artifact->artifact_object_key)
        ->and(data_get($run->metadata, 'dataset_storage.sha256'))->toBe($datasetHash)
        ->and(data_get($run->metadata, 'evaluation_report_storage.uri'))->toBe($artifact->evaluation_report_uri);

    $artifactAliasPath = $source->path('model.json');
    Storage::disk('ml-source-test')->delete('model.json');
    Storage::disk('ml-cache-test')->delete($artifact->artifact_object_key);

    $resolvedByAlias = $registry->forPath($artifactAliasPath);
    expect($resolvedByAlias?->id)->toBe($artifact->id)
        ->and(File::exists((string) $resolvedByAlias?->artifact_path))->toBeTrue();

    $registeredReportHash = $artifact->evaluation_report_hash;
    $source->put('report.json', '{"windows":5}');

    expect(fn () => $registry->attachEvaluationReport($artifact, $source->path('report.json')))
        ->toThrow(RuntimeException::class, 'does not match the registered SHA-256')
        ->and($artifact->refresh()->evaluation_report_hash)->toBe($registeredReportHash);
});
