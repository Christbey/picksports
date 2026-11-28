<?php

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\NFL\NflTabularModelBundle;
use App\Services\NFL\NflTabularModelInferenceService;
use App\Services\NFL\NflTabularModelRunRegistrar;
use App\Support\Json\JsonAllocationBudget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->numericRoot = storage_path('framework/testing/nfl-numeric-'.Str::uuid());
    config()->set('nfl_ml.bundle.staging_directory', $this->numericRoot.'/staging');
    config()->set('nfl_ml.bundle.extraction_directory', $this->numericRoot.'/extract');
});
afterEach(function () {
    File::deleteDirectory($this->numericRoot);
});

it('refuses a legacy executable bundle before registration or Python execution', function () {
    $fixture = nflLegacyNumericBoundaryFixture($this->numericRoot.'/legacy');
    expect(fn () => app(NflTabularModelBundle::class)->create($fixture['run_directory'], $fixture['dataset_path']))
        ->toThrow(RuntimeException::class, 'numeric-state');
    expect(ModelRun::query()->count())->toBe(0)->and(ModelArtifact::query()->count())->toBe(0);
});

it('extracts an exact numeric inner run and executes the real NFL Python CLI', function () {
    $fixture = nflNumericInteropFixture();
    $runPath = $fixture['run_directory'];
    $dataset = $fixture['dataset_path'];
    $service = app(NflTabularModelBundle::class);
    $prepared = $service->create($runPath, $dataset);
    try {
        $m = $prepared['manifest'];
        $training = new ModelRun(['config_hash' => $m['config_hash'], 'parameters' => ['feature_schema_hash' => $m['feature_schema_hash']], 'metadata' => ['python_model_run_id' => $m['model_run_id']]]);
        $artifact = new ModelArtifact(['id' => $m['artifact_id'], 'dataset_hash' => $m['dataset_hash'], 'artifact_hash' => hash_file('sha256', $prepared['path'])]);
        $artifact->setRelation('trainingRun', $training);
        $run = $service->extractAndVerify($artifact, $prepared['path']);
        expect(basename($run))->toBe('run')->and(is_file($run.'/bundle.json'))->toBeFalse()->and(is_dir($run.'/dataset'))->toBeFalse()
            ->and(File::allFiles($run))->toHaveCount(17);
        $handle = fopen($dataset, 'rb');
        $headers = fgetcsv($handle, escape: '');
        $row = fgetcsv($handle, escape: '');
        fclose($handle);
        $input = $this->numericRoot.'/input.json';
        File::put($input, json_encode([array_combine($headers, $row)], JSON_THROW_ON_ERROR));
        $process = new Process([$fixture['python'], '-m', 'picksports_nfl_ml.cli', 'predict', '--run-dir', $run, '--input', $input]);
        $process->setEnv(['PYTHONPATH' => base_path('ml/nfl/src'), 'PYTHONNOUSERSITE' => '1', 'PYTHONDONTWRITEBYTECODE' => '1', 'OMP_NUM_THREADS' => '1', 'OPENBLAS_NUM_THREADS' => '1']);
        $process->setTimeout(30);
        $process->mustRun();
        $outputs = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $expected = $fixture['expected_output'];
        foreach ($expected as $field => $value) {
            expect($outputs[0][$field])->toBe($value);
        }
        // An undeclared cache file is refused by Python's exact inventory, never executed.
        File::put($run.'/extra.json', '{}');
        $process->run();
        expect($process->isSuccessful())->toBeFalse();
    } finally {
        File::deleteDirectory($prepared['temporary_directory']);
    }
})->group('ci-isolated-interop');

it('registers a numeric archive with bounded storage and runs the real registered inference entry', function () {
    Storage::fake('nfl-numeric-artifacts');
    Storage::fake('nfl-numeric-cache');
    config()->set('ml.storage.disk', 'nfl-numeric-artifacts');
    config()->set('ml.storage.cache_disk', 'nfl-numeric-cache');
    $fixture = nflNumericInteropFixture();
    $run = $fixture['run_directory'];
    $dataset = $fixture['dataset_path'];
    $artifact = app(NflTabularModelRunRegistrar::class)->register($run, $dataset);
    $reportedAt = json_decode(File::get($run.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR)['generated_at'];
    expect($artifact->trainingRun->fresh()->started_at->getTimestamp())->toBe(CarbonImmutable::parse($reportedAt)->getTimestamp());
    config()->set('nfl_ml.process.command', [$fixture['python'], '-m', 'picksports_nfl_ml.cli']);
    config()->set('nfl_ml.process.package_directory', base_path('ml/nfl'));
    $stream = fopen($dataset, 'rb');
    $headers = fgetcsv($stream, escape: '');
    $values = fgetcsv($stream, escape: '');
    fclose($stream);
    $features = array_filter(array_combine($headers, $values), fn ($key) => str_starts_with($key, 'feature_'), ARRAY_FILTER_USE_KEY);
    $features = array_map(fn ($value) => $value === '' ? null : (float) $value, $features);
    $output = app(NflTabularModelInferenceService::class)->predict($artifact, $features);
    $expected = $fixture['expected_output'];
    foreach ($expected as $field => $value) {
        expect($output[$field])->toBe($value);
    }
    expect($artifact->refresh()->artifact_hash)->toMatch('/^[a-f0-9]{64}$/');
})->group('ci-isolated-interop');

it('refuses extra numeric inventory members before publishing an archive', function () {
    $run = $this->numericRoot.'/extra';
    nflNumericContainerFixture($run);
    $manifest = json_decode(File::get($run.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    File::put($run.'/extra.json', '{}');
    $manifest['artifacts']['extra.json'] = ['bytes' => 2, 'sha256' => hash('sha256', '{}')];
    File::put($run.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    expect(fn () => app(NflTabularModelBundle::class)->create($run))->toThrow(RuntimeException::class, 'exact supported file set');
    expect(File::glob($this->numericRoot.'/staging/*'))->toBe([]);
});

it('refuses over-budget declared component bytes before hashing or archive staging', function () {
    $run = $this->numericRoot.'/over';
    nflNumericContainerFixture($run);
    $manifest = json_decode(File::get($run.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $manifest['artifacts']['preprocessor.json']['bytes'] = 8388609;
    File::put($run.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    expect(fn () => app(NflTabularModelBundle::class)->create($run))->toThrow(RuntimeException::class, 'metadata');
    expect(File::glob($this->numericRoot.'/staging/*'))->toBe([]);
});

it('admits JSON structure before decoding a dense metadata body', function () {
    $run = $this->numericRoot.'/dense';
    File::ensureDirectoryExists($run);
    File::put($run.'/manifest.json', '{"items":['.str_repeat('[],', 20000).'[]]}');
    File::put($run.'/evaluation.json', '{}');
    expect(fn () => app(NflTabularModelBundle::class)->create($run))->toThrow(RuntimeException::class, 'token budget');
    expect(File::glob($this->numericRoot.'/staging/*'))->toBe([]);
});

it('requires bounds for every registration object before storage or database writes', function () {
    $run = new ModelRun(['sport' => 'nfl']);
    $registry = app(ModelArtifactRegistry::class);
    expect(fn () => $registry->register(id: (string) Str::uuid(), trainingRun: $run, marketType: 'multi_market', modelType: 'nfl_tabular_bundle', modelVersion: 'nfl-tabular-v3', featureVersion: 'fixture', datasetHash: str_repeat('a', 64), artifactPath: '/absent-artifact', metrics: [], datasetPath: '/absent-dataset', storageExpectations: ['artifact' => ['bytes' => 1, 'sha256' => str_repeat('a', 64)]]))
        ->toThrow(RuntimeException::class, 'every supplied path');
    expect(ModelRun::query()->count())->toBe(0)->and(ModelArtifact::query()->count())->toBe(0);
});

it('archives only private sealed exact copies and retains captured dataset and report bytes', function () {
    $run = $this->numericRoot.'/mutable-source';
    $fixture = nflNumericContainerFixture($run);
    $dataset = $fixture['dataset_path'];
    $prepared = app(NflTabularModelBundle::class)->create($run, $dataset);
    try {
        $captured = File::get($run.'/preprocessor.json');
        File::put($run.'/preprocessor.json', 'changed original');
        File::put($dataset, 'changed original dataset');
        File::put($run.'/evaluation.json', 'changed original report');
        $zip = new ZipArchive;
        expect($zip->open($prepared['path'], ZipArchive::RDONLY))->toBeTrue();
        expect($zip->getFromName('preprocessor.json'))->toBe($captured);
        $zip->close();
        expect(hash_file('sha256', $prepared['owned_dataset_path']))->toBe($prepared['manifest']['dataset_hash'])
            ->and(hash_file('sha256', $prepared['owned_report_path']))->toBe($prepared['storage_expectations']['report']['sha256'])
            ->and(fileperms($prepared['owned_dataset_path']) & 0777)->toBe(0400)
            ->and(fileperms($prepared['owned_report_path']) & 0777)->toBe(0400);
    } finally {
        File::deleteDirectory($prepared['temporary_directory']);
    }
});

it('refuses unsupported cache identities before archive or destination IO', function (string $id, string $hash) {
    $artifact = new ModelArtifact(['id' => $id, 'artifact_hash' => $hash]);
    expect(fn () => app(NflTabularModelBundle::class)->extractAndVerify($artifact, '/absent-archive'))
        ->toThrow(RuntimeException::class, 'cache identity');
    expect(File::glob($this->numericRoot.'/extract/*'))->toBe([]);
})->with([
    ['../outside', str_repeat('a', 64)],
    ['11111111-1111-4111-8111-111111111111', '../outside'],
]);

it('refuses oversized inference maps before artifact IO or child execution', function (array $features) {
    $artifact = new ModelArtifact(['sport' => 'nfl', 'model_type' => 'nfl_tabular_bundle', 'training_run_id' => '11111111-1111-4111-8111-111111111111']);
    $artifact->exists = true;
    $registry = Mockery::mock(ModelArtifactRegistry::class);
    $registry->shouldReceive('materializeArtifact')->andReturnUsing(function () {
        throw new AssertionError('Artifact IO reached before input shape admission.');
    });
    $service = new NflTabularModelInferenceService($registry, app(NflTabularModelBundle::class));
    expect(fn () => $service->predict($artifact, $features))->toThrow(RuntimeException::class, 'bounded');
    expect(File::glob($this->numericRoot.'/inputs/*'))->toBe([]);
})->with([
    [array_fill_keys(array_map(fn (int $i): string => 'feature_'.$i, range(1, 513)), 0)],
    [[str_repeat('f', 129) => 0]],
]);

function nflLegacyNumericBoundaryFixture(string $runDirectory, ?string $modelRunId = null): array
{
    File::ensureDirectoryExists($runDirectory.'/models');
    File::ensureDirectoryExists($runDirectory.'/calibrators');

    $files = [
        'calibrators/logistic_regression_isotonic.joblib' => 'logistic-isotonic',
        'calibrators/logistic_regression_platt.joblib' => 'logistic-platt',
        'calibrators/xgboost_isotonic.joblib' => 'xgboost-isotonic',
        'calibrators/xgboost_platt.joblib' => 'xgboost-platt',
        'feature_schema.yaml' => "schema_version: nfl-pregame-ml-v1\n",
        'models/logistic_classifier.joblib' => 'logistic-model',
        'models/xgboost_classifier.ubj' => 'xgboost-classifier',
        'models/xgboost_home_margin.ubj' => 'xgboost-margin',
        'models/xgboost_total_points.ubj' => 'xgboost-total',
        'prediction_example.json' => "{}\n",
        'preprocessor.joblib' => 'preprocessor',
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
        'manifest_version' => 1,
        'model_version' => 'nfl-tabular-v1',
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

/** Bounded test-fixture IO; no OS sandbox or producer-attestation claim. */
function nflNumericFixtureBytes(string $path, int $maximum): string
{
    if (is_link($path) || ! is_file($path)) {
        throw new RuntimeException('NFL CI fixture requires regular files.');
    }
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException('NFL CI fixture file is unavailable.');
    }
    try {
        $before = fstat($stream);
        if ($before === false || ($before['mode'] & 0170000) !== 0100000 || $before['size'] > $maximum) {
            throw new RuntimeException('NFL CI fixture file exceeds its byte budget.');
        }
        $bytes = stream_get_contents($stream, $maximum + 1);
        $after = fstat($stream);
        if (! is_string($bytes) || strlen($bytes) > $maximum || strlen($bytes) !== $before['size']
            || $after === false || $after['size'] !== $before['size'] || $after['mtime'] !== $before['mtime'] || $after['ctime'] !== $before['ctime']) {
            throw new RuntimeException('NFL CI fixture file changed or exceeds its byte budget.');
        }

        return $bytes;
    } finally {
        fclose($stream);
    }
}

/** Explicit portable synthetic test fixture; never production/model approval authority. */
function nflNumericInteropFixture(): array
{
    $root = getenv('PICKSPORTS_NFL_NUMERIC_FIXTURE_ROOT');
    if (! is_string($root) || $root === '' || ! is_dir($root)) {
        test()->markTestSkipped('Real Python numeric interoperability requires the explicitly prepared synthetic NFL fixture root.');
    }
    $root = rtrim($root, '/');
    $descriptorPath = $root.'/fixture.json';
    $raw = nflNumericFixtureBytes($descriptorPath, 65536);
    JsonAllocationBudget::inspect($raw, 65536, 2000, 12, 'NFL CI fixture');
    $descriptor = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
    if (($descriptor['schema_version'] ?? null) !== 'picksports-nfl-ci-interop-v1'
        || ($descriptor['synthetic'] ?? null) !== true
        || ! in_array($descriptor['source_mode'] ?? null, ['tiny_synthetic_ci_fit', 'retained_synthetic_no_fit'], true)
        || ($descriptor['run_directory'] ?? null) !== 'run'
        || ($descriptor['dataset'] ?? null) !== 'dataset.csv'
        || ($descriptor['expected_output'] ?? null) !== 'expected-output.json') {
        throw new RuntimeException('NFL CI interoperability fixture contract is unsupported.');
    }
    $objects = [];
    foreach (['manifest_sha256' => 'run/manifest.json', 'dataset_sha256' => 'dataset.csv', 'expected_output_sha256' => 'expected-output.json'] as $key => $relative) {
        $path = $root.'/'.$relative;
        $limit = $relative === 'expected-output.json' ? 65536 : 2097152;
        $objects[$relative] = nflNumericFixtureBytes($path, $limit);
        if (! is_string($descriptor[$key] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $descriptor[$key])
            || ! hash_equals($descriptor[$key], hash('sha256', $objects[$relative]))) {
            throw new RuntimeException('NFL CI interoperability fixture bytes differ from their descriptor.');
        }
    }
    $python = getenv('PICKSPORTS_NFL_NUMERIC_PYTHON');
    if (! is_string($python) || $python === '' || ! is_file($python) || ! is_executable($python)) {
        throw new RuntimeException('An explicit NFL CI Python executable is required.');
    }
    $expectedRaw = $objects['expected-output.json'];
    JsonAllocationBudget::inspect($expectedRaw, 65536, 2000, 12, 'NFL CI expected output');

    $expected = json_decode($expectedRaw, true, 12, JSON_THROW_ON_ERROR);
    $expectedKeys = ['home_win_probability', 'expected_home_margin', 'expected_total', 'home_cover_probability',
        'over_probability', 'uncertainty', 'model_run_id', 'artifact_id', 'dataset_hash', 'feature_hash'];
    $actualKeys = is_array($expected) ? array_keys($expected) : [];
    sort($expectedKeys);
    sort($actualKeys);
    if ($actualKeys !== $expectedKeys) {
        throw new RuntimeException('NFL CI expected output must contain the complete inference contract.');
    }

    return ['run_directory' => $root.'/run', 'dataset_path' => $root.'/dataset.csv',
        'expected_output' => $expected, 'python' => $python];
}

/** Container-only synthetic bytes; this fixture does not claim native numerical admission. */
function nflNumericContainerFixture(string $run): array
{
    $fixture = nflLegacyNumericBoundaryFixture($run);
    $manifest = json_decode(File::get($run.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $files = [];
    foreach (array_keys($manifest['artifacts']) as $old) {
        $name = str_replace(['.joblib', '.ubj', 'feature_schema.yaml'], ['.json', '.json', 'feature_schema.json'], $old);
        if ($old !== $name) {
            File::move($run.'/'.$old, $run.'/'.$name);
        }
        $files[] = $name;
    }
    foreach (['tuning/optuna_provenance.json', 'explanations/global_shap_importance.json', 'explanations/xgboost_feature_importance.json', 'explanations/shap_values.parquet'] as $name) {
        File::ensureDirectoryExists(dirname($run.'/'.$name));
        File::put($run.'/'.$name, 'synthetic nonexecuted evidence');
        $files[] = $name;
    }
    $manifest['manifest_version'] = 2;
    $manifest['execution_format'] = 'picksports-nfl-tabular-numeric-v1';
    $manifest['model_type'] = 'nfl_tabular_bundle';
    $manifest['model_version'] = 'nfl-tabular-v3';
    $manifest['blend_version'] = 'baseline-anchored-v2';
    $manifest['artifacts'] = [];
    foreach ($files as $name) {
        $manifest['artifacts'][$name] = ['sha256' => hash_file('sha256', $run.'/'.$name), 'bytes' => filesize($run.'/'.$name)];
    }
    File::put($run.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    return $fixture;
}

it('preserves an explicit registration generation instant through raw and hydrated legacy storage', function (string $reportedAt, string $expectedWall) {
    Storage::fake('nfl-registration-clock');
    Storage::fake('nfl-registration-cache');
    config(['app.timezone' => 'America/Chicago', 'ml.storage.disk' => 'nfl-registration-clock', 'ml.storage.cache_disk' => 'nfl-registration-cache']);
    $fixture = nflNumericContainerFixture($this->numericRoot.'/clock');
    $path = $fixture['run_directory'].'/manifest.json';
    $manifest = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
    $manifest['generated_at'] = $reportedAt;
    File::put($path, json_encode($manifest, JSON_THROW_ON_ERROR));
    $artifact = app(NflTabularModelRunRegistrar::class)->register($fixture['run_directory'], $fixture['dataset_path']);
    $run = $artifact->trainingRun->fresh();
    expect($run->getRawOriginal('started_at'))->toBe($expectedWall)
        ->and($run->started_at->getTimestamp())->toBe(CarbonImmutable::parse($reportedAt)->getTimestamp());
})->with([
    ['2026-07-26T12:00:00Z', '2026-07-26 07:00:00'],
    ['2026-07-26T12:00:00-05:00', '2026-07-26 12:00:00'],
    ['2026-11-01T06:30:00Z', '2026-11-01 01:30:00'],
]);

it('refuses missing or unrepresentable registration generation evidence before persistence', function (?string $reportedAt) {
    Storage::fake('nfl-registration-clock');
    Storage::fake('nfl-registration-cache');
    config(['app.timezone' => 'America/Chicago', 'ml.storage.disk' => 'nfl-registration-clock', 'ml.storage.cache_disk' => 'nfl-registration-cache']);
    $fixture = nflNumericContainerFixture($this->numericRoot.'/invalid-clock');
    $path = $fixture['run_directory'].'/manifest.json';
    $manifest = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
    if ($reportedAt === null) {
        unset($manifest['generated_at']);
    } else {
        $manifest['generated_at'] = $reportedAt;
    }
    File::put($path, json_encode($manifest, JSON_THROW_ON_ERROR));
    expect(fn () => app(NflTabularModelRunRegistrar::class)->register($fixture['run_directory'], $fixture['dataset_path']))
        ->toThrow(RuntimeException::class);
    expect(ModelRun::query()->count())->toBe(0)->and(ModelArtifact::query()->count())->toBe(0)
        ->and(Storage::disk('nfl-registration-clock')->allFiles())->toBe([]);
})->with([
    'missing' => [null], 'relative' => ['now'], 'naive' => ['2026-07-26 12:00:00'],
    'invalid calendar' => ['2026-02-30T12:00:00Z'], 'second fold' => ['2026-11-01T07:30:00Z'],
]);
