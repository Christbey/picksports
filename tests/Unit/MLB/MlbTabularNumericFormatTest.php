<?php

use App\Services\MLB\MlbTabularModelBundle;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\SyntheticMlbTabularBundleFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->numericRoot = storage_path('framework/testing/mlb-numeric-format-'.Str::uuid());
    File::ensureDirectoryExists($this->numericRoot);
    file_put_contents($this->numericRoot.'/dataset.csv', "game_id,target_home_win\n1,1\n");
    config()->set('mlb_ml.bundle.staging_directory', $this->numericRoot.'/staging');
    $this->numericFixture = SyntheticMlbTabularBundleFixture::create($this->numericRoot.'/run', $this->numericRoot.'/dataset.csv');
});

afterEach(function () {
    File::deleteDirectory($this->numericRoot);
});

it('archives the fixed versioned numeric inventory without executing its synthetic component placeholders', function () {
    $prepared = app(MlbTabularModelBundle::class)->create($this->numericFixture['run_directory'], $this->numericFixture['dataset_path']);
    expect($prepared['manifest']['execution_format'])->toBe('picksports-tabular-numeric-v1')
        ->and($prepared['bundle']['execution_format'])->toBe('picksports-tabular-numeric-v1')
        ->and(count($prepared['manifest']['artifacts']))->toBe(12)
        ->and(array_keys($prepared['manifest']['artifacts']))->toContain('feature_schema.json', 'models/xgboost_classifier.json')
        ->and(collect(array_keys($prepared['manifest']['artifacts']))->contains(fn ($path) => preg_match('/\.(joblib|ubj|yaml)$/', $path)))->toBeFalse();
});

it('refuses unsupported formats inventory and declared per-component budgets before hashing bodies', function (string $case) {
    $root = $this->numericFixture['run_directory'];
    $manifest = json_decode(file_get_contents($root.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    if ($case === 'legacy') {
        unset($manifest['execution_format']);
    } elseif ($case === 'wrong_format') {
        $manifest['execution_format'] = 'unsupported-v999';
    } elseif ($case === 'extra_executable') {
        file_put_contents($root.'/legacy.joblib', 'retained synthetic bytes');
        $manifest['artifacts']['legacy.joblib'] = ['sha256' => hash_file('sha256', $root.'/legacy.joblib'), 'bytes' => filesize($root.'/legacy.joblib')];
    } elseif ($case === 'yaml_schema') {
        rename($root.'/feature_schema.json', $root.'/feature_schema.yaml');
        $manifest['artifacts']['feature_schema.yaml'] = $manifest['artifacts']['feature_schema.json'];
        unset($manifest['artifacts']['feature_schema.json']);
    } else {
        $entry = $case === 'model_bytes' ? 'preprocessor.json' : 'evaluation.json';
        $manifest['artifacts'][$entry]['bytes'] = ($case === 'model_bytes' ? 8388608 : 2097152) + 1;
    }
    file_put_contents($root.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $message = in_array($case, ['legacy', 'wrong_format'], true) ? 'not an MLB tabular bundle' : (in_array($case, ['model_bytes', 'metadata_bytes'], true) ? 'metadata for' : 'exact supported file set');
    expect(fn () => app(MlbTabularModelBundle::class)->create($root, $this->numericFixture['dataset_path']))->toThrow(RuntimeException::class, $message);
})->with(['legacy', 'wrong_format', 'extra_executable', 'yaml_schema', 'model_bytes', 'metadata_bytes']);
