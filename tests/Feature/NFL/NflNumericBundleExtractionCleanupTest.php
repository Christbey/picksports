<?php

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\NFL\NflTabularModelBundle;
use App\Services\NFL\NflTabularModelRunRegistrar;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->cleanupRoot = storage_path('framework/testing/nfl-extraction-cleanup-'.Str::uuid());
    File::ensureDirectoryExists($this->cleanupRoot, 0700, true);
    config(['nfl_ml.bundle.staging_directory' => $this->cleanupRoot.'/staging',
        'nfl_ml.bundle.extraction_directory' => $this->cleanupRoot.'/extract',
        'ml.storage.disk' => 'nfl-cleanup-artifacts', 'ml.storage.cache_disk' => 'nfl-cleanup-cache']);
    Storage::fake('nfl-cleanup-artifacts');
    Storage::fake('nfl-cleanup-cache');
    Http::preventStrayRequests();
    Queue::fake();
});
afterEach(function () {
    File::deleteDirectory($this->cleanupRoot);
});

it('extracts a real registered synthetic numeric container without invoking a model', function () {
    $fixture = nflBundleCleanupContainerFixture($this->cleanupRoot.'/run');
    $artifact = app(NflTabularModelRunRegistrar::class)->register($fixture['run_directory'], $fixture['dataset_path']);
    expect($artifact->exists)->toBeTrue()->and($artifact->trainingRun->exists)->toBeTrue();
    $path = app(ModelArtifactRegistry::class)->materializeArtifact($artifact, persistCachePath: false, maximumBytes: 205598720);
    $hash = hash_file('sha256', $path);
    $run = app(NflTabularModelBundle::class)->extractAndVerify($artifact->fresh(), $path);
    expect(File::allFiles($run))->toHaveCount(17)
        ->and(hash_file('sha256', $run.'/preprocessor.json'))->toBe(hash_file('sha256', $fixture['run_directory'].'/preprocessor.json'))
        ->and(hash_file('sha256', $path))->toBe($hash)
        ->and(File::glob(dirname($run).'.tmp-*'))->toBe([])
        ->and(ModelArtifact::query()->count())->toBe(1)->and(ModelRun::query()->count())->toBe(1);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('removes only the owned temporary extraction after a reached filesystem refusal', function () {
    $fixture = nflBundleCleanupContainerFixture($this->cleanupRoot.'/run');
    $artifact = app(NflTabularModelRunRegistrar::class)->register($fixture['run_directory'], $fixture['dataset_path']);
    $path = app(ModelArtifactRegistry::class)->materializeArtifact($artifact, persistCachePath: false, maximumBytes: 205598720);
    $artifactBefore = $artifact->fresh()->getAttributes();
    $runBefore = $artifact->trainingRun->fresh()->getAttributes();
    $zipBefore = hash_file('sha256', $path);
    $destination = $this->cleanupRoot.'/extract/'.$artifact->id.'/'.$artifact->artifact_hash;
    $sentinel = $this->cleanupRoot.'/extract/unrelated-owned-sentinel';
    File::ensureDirectoryExists(dirname($sentinel));
    File::put($sentinel, 'preserve this independent fixture file');
    $sentinelBefore = hash_file('sha256', $sentinel);
    $filesystem = File::getFacadeRoot();
    $reached = 0;
    File::partialMock()->shouldReceive('ensureDirectoryExists')->passthru()->byDefault();
    File::shouldReceive('ensureDirectoryExists')->once()
        ->withArgs(fn (string $directory, int $mode = 0755, bool $recursive = true): bool => str_starts_with($directory, $destination.'.tmp-') && str_contains(substr($directory, strlen($destination)), '/run/'))
        ->andReturnUsing(function (string $directory, int $mode = 0755, bool $recursive = true) use ($filesystem, &$reached): never {
            $filesystem->ensureDirectoryExists($directory, $mode, $recursive);
            $reached++;
            throw new RuntimeException('Synthetic owned extraction filesystem refusal.');
        });
    $failure = null;
    try {
        app(NflTabularModelBundle::class)->extractAndVerify($artifact->fresh(), $path);
    } catch (Throwable $exception) {
        $failure = ['class' => $exception::class, 'message' => $exception->getMessage()];
    }
    $temporary = File::glob($destination.'.tmp-*');
    $trace = getenv('REVIEW_NFL_BUNDLE_CLEANUP_TRACE');
    if (is_string($trace) && $trace !== '') {
        file_put_contents($trace, json_encode(['kind' => 'reached-extraction-failure', 'artifact_id' => $artifact->id,
            'reached' => $reached, 'failure' => $failure, 'owned_temporary_before_assertion' => $temporary,
            'published_cache_exists' => is_dir($destination), 'zip_sha256' => hash_file('sha256', $path),
            'sentinel_preserved' => is_file($sentinel) && hash_file('sha256', $sentinel) === $sentinelBefore], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    }
    expect($reached)->toBe(1)->and($failure)->toBe(['class' => RuntimeException::class, 'message' => 'Synthetic owned extraction filesystem refusal.']);
    expect($temporary)->toBe([])
        ->and(is_dir($destination))->toBeFalse()
        ->and(hash_file('sha256', $sentinel))->toBe($sentinelBefore)
        ->and(hash_file('sha256', $path))->toBe($zipBefore)
        ->and($artifact->fresh()->getAttributes())->toBe($artifactBefore)
        ->and($artifact->trainingRun->fresh()->getAttributes())->toBe($runBefore)
        ->and(ModelArtifact::query()->count())->toBe(1)->and(ModelRun::query()->count())->toBe(1);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

// Exact existing container-only fixture body copied and renamed from the integration test.
// These are synthetic opaque byte records for archive admission, NOT native numeric/model authority.
function nflBundleCleanupContainerFixture(string $runDirectory, ?string $modelRunId = null): array
{
    File::ensureDirectoryExists($runDirectory.'/models');
    File::ensureDirectoryExists($runDirectory.'/calibrators');
    File::ensureDirectoryExists($runDirectory.'/explanations');
    File::ensureDirectoryExists($runDirectory.'/tuning');

    $files = [
        'calibrators/logistic_regression_isotonic.json' => 'logistic-isotonic',
        'calibrators/logistic_regression_platt.json' => 'logistic-platt',
        'calibrators/xgboost_isotonic.json' => 'xgboost-isotonic',
        'calibrators/xgboost_platt.json' => 'xgboost-platt',
        'feature_schema.json' => "schema_version: nfl-pregame-ml-v1\n",
        'models/logistic_classifier.json' => 'logistic-model',
        'models/xgboost_classifier.json' => 'xgboost-classifier',
        'models/xgboost_home_margin.json' => 'xgboost-margin',
        'models/xgboost_total_points.json' => 'xgboost-total',
        'tuning/optuna_provenance.json' => '{}',
        'explanations/global_shap_importance.json' => '{}',
        'explanations/xgboost_feature_importance.json' => '{}',
        'explanations/shap_values.parquet' => 'synthetic-non-executable-evidence',
        'prediction_example.json' => "{}\n",
        'preprocessor.json' => 'preprocessor',
    ];
    $modelRunId ??= (string) Str::uuid();
    $artifactId = (string) Str::uuid();
    $datasetPath = dirname($runDirectory).'/training-data.csv';
    File::put($datasetPath, "game_id,feature_home_elo,target_home_win\n1,1532,1\n");
    $datasetHash = hash_file('sha256', $datasetPath);
    $featureSchemaHash = hash('sha256', 'feature-schema');
    $configHash = hash('sha256', 'training-config');

    $evaluation = [
        'report_type' => 'nfl_tabular_walk_forward_evaluation',
        'model_run_id' => $modelRunId,
        'artifact_id' => $artifactId,
        'dataset' => ['sha256' => $datasetHash],
        'feature_schema' => ['sha256' => $featureSchemaHash],
        'final_holdout' => [
            'test_season' => 2025,
            'classifiers' => ['xgboost' => ['test_calibrated' => ['brier' => 0.21]]],
        ],
        'walk_forward' => ['summary' => ['window_count' => 11]],
    ];
    $files['evaluation.json'] = json_encode(
        $evaluation,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;

    foreach ($files as $relativePath => $contents) {
        File::put($runDirectory.'/'.$relativePath, $contents);
    }

    $inventory = [];
    foreach (array_keys($files) as $relativePath) {
        $path = $runDirectory.'/'.$relativePath;
        $inventory[$relativePath] = [
            'sha256' => hash_file('sha256', $path),
            'bytes' => filesize($path),
        ];
    }
    ksort($inventory);

    $manifest = [
        'manifest_version' => 2,
        'execution_format' => 'picksports-nfl-tabular-numeric-v1',
        'model_type' => 'nfl_tabular_bundle',
        'blend_version' => 'baseline-anchored-v2',
        'model_version' => 'nfl-tabular-v3',
        'model_run_id' => $modelRunId,
        'artifact_id' => $artifactId,
        'generated_at' => '2026-07-26T12:00:00+00:00',
        'dataset_hash' => $datasetHash,
        'feature_schema_version' => 'nfl-pregame-ml-v1',
        'feature_schema_hash' => $featureSchemaHash,
        'config_hash' => $configHash,
        'code_version' => str_repeat('a', 40),
        'source_hash' => hash('sha256', 'source'),
        'seed' => 20260726,
        'champion_classifier' => 'xgboost',
        'selected_calibrators' => [
            'logistic_regression' => 'platt',
            'xgboost' => 'platt',
        ],
        'training_seasons' => range(2009, 2023),
        'calibration_season' => 2024,
        'held_out_test_season' => 2025,
        'artifacts' => $inventory,
    ];
    File::put(
        $runDirectory.'/manifest.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
    );

    return [
        'run_directory' => $runDirectory,
        'dataset_path' => $datasetPath,
        'dataset_hash' => $datasetHash,
        'model_run_id' => $modelRunId,
        'artifact_id' => $artifactId,
        'config_hash' => $configHash,
    ];
}
