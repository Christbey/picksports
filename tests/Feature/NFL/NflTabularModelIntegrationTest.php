<?php

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\NFL\NflFullHistoricalShadowInferenceService;
use App\Services\NFL\NflTabularModelInferenceService;
use App\Services\NFL\NflTabularModelRunRegistrar;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('nfl-tabular-artifacts');
    Storage::fake('nfl-tabular-cache');

    config()->set('ml.storage.disk', 'nfl-tabular-artifacts');
    config()->set('ml.storage.cache_disk', 'nfl-tabular-cache');
    config()->set('ml.storage.prefix', 'ml');
    config()->set('filesystems.disks.nfl-tabular-artifacts.driver', 'local');

    $this->nflMlTestRoot = storage_path('framework/testing/nfl-tabular-'.Str::uuid());
    config()->set('nfl_ml.bundle.staging_directory', $this->nflMlTestRoot.'/staging');
    config()->set('nfl_ml.bundle.extraction_directory', $this->nflMlTestRoot.'/extracted');
    config()->set('nfl_ml.bundle.input_directory', $this->nflMlTestRoot.'/inputs');
});

afterEach(function () {
    File::deleteDirectory($this->nflMlTestRoot);
});

it('registers a verified completed Python run as one immutable bundle with the exact dataset', function () {
    $fixture = nflTabularRunFixture($this->nflMlTestRoot.'/run');

    $this->artisan('nfl:register-tabular-model-run', [
        'run-directory' => $fixture['run_directory'],
        '--dataset' => $fixture['dataset_path'],
    ])
        ->expectsOutputToContain('NFL tabular model run registered.')
        ->expectsOutputToContain('Model run: '.$fixture['model_run_id'])
        ->expectsOutputToContain('Artifact: '.$fixture['artifact_id'])
        ->assertSuccessful();

    $artifact = ModelArtifact::query()->findOrFail($fixture['artifact_id']);
    $run = ModelRun::query()->findOrFail($fixture['model_run_id']);

    expect($artifact->model_type)->toBe('nfl_tabular_bundle')
        ->and($artifact->market_type)->toBe('multi_market')
        ->and($artifact->dataset_hash)->toBe($fixture['dataset_hash'])
        ->and($artifact->artifact_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($artifact->artifact_content_type)->toBe('application/zip')
        ->and($artifact->dataset_hash)->toBe(hash_file('sha256', $artifact->dataset_path))
        ->and($run->status)->toBe('completed')
        ->and($run->config_hash)->toBe($fixture['config_hash'])
        ->and(data_get($run->metadata, 'bundle_manifest.dataset_entry'))->toBe('dataset/training-data.csv')
        ->and(data_get($run->metadata, 'artifact_storage.sha256'))->toBe($artifact->artifact_hash)
        ->and(data_get($run->metadata, 'inference_alias_path'))->toBeNull();

    $bundlePath = app(ModelArtifactRegistry::class)->materializeArtifact($artifact);
    $zip = new ZipArchive;
    expect($zip->open($bundlePath, ZipArchive::RDONLY))->toBeTrue()
        ->and($zip->locateName('manifest.json'))->not->toBeFalse()
        ->and($zip->locateName('evaluation.json'))->not->toBeFalse()
        ->and($zip->locateName('preprocessor.joblib'))->not->toBeFalse()
        ->and($zip->locateName('models/xgboost_classifier.ubj'))->not->toBeFalse()
        ->and($zip->locateName('calibrators/xgboost_platt.joblib'))->not->toBeFalse()
        ->and($zip->locateName('dataset/training-data.csv'))->not->toBeFalse();
    $zip->close();
});

it('refuses to register a run whose declared artifact bytes were changed', function () {
    $fixture = nflTabularRunFixture($this->nflMlTestRoot.'/tampered-run');
    File::append($fixture['run_directory'].'/preprocessor.joblib', '-changed');

    $this->artisan('nfl:register-tabular-model-run', [
        'run-directory' => $fixture['run_directory'],
        '--dataset' => $fixture['dataset_path'],
    ])
        ->expectsOutputToContain('failed hash verification')
        ->assertFailed();

    expect(ModelRun::query()->count())->toBe(0)
        ->and(ModelArtifact::query()->count())->toBe(0);
});

it('materializes and verifies a registered bundle before invoking the predict CLI without a shell', function () {
    $fixture = nflTabularRunFixture(
        $this->nflMlTestRoot.'/inference-run',
        'real-export-final-verification',
    );
    $artifact = app(NflTabularModelRunRegistrar::class)->register(
        $fixture['run_directory'],
        $fixture['dataset_path'],
    );
    $cli = nflTabularFakeCli($this->nflMlTestRoot.'/fake-cli.php');
    config()->set('nfl_ml.process.command', [PHP_BINARY, $cli]);

    $output = app(NflTabularModelInferenceService::class)->predict($artifact, [
        'feature_market_total' => 44.5,
        'feature_home_elo' => 1532,
        'feature_market_home_spread' => -2.5,
    ]);

    expect($output)->toHaveKeys([
        'home_win_probability',
        'expected_home_margin',
        'expected_total',
        'home_cover_probability',
        'over_probability',
        'uncertainty',
        'model_run_id',
        'artifact_id',
        'dataset_hash',
        'feature_hash',
    ])
        ->and($output['home_win_probability'])->toBe(0.614)
        ->and($output['expected_home_margin'])->toBe(3.8)
        ->and($output['expected_total'])->toBe(44.7)
        ->and($output['model_run_id'])->toBe($fixture['model_run_id'])
        ->and($output['artifact_id'])->toBe($fixture['artifact_id'])
        ->and($output['dataset_hash'])->toBe($fixture['dataset_hash'])
        ->and($output['feature_hash'])->toMatch('/^[a-f0-9]{64}$/');

    $extractedRun = config('nfl_ml.bundle.extraction_directory')
        .'/'.$artifact->id.'/'.$artifact->artifact_hash;
    expect(File::exists($extractedRun.'/manifest.json'))->toBeTrue()
        ->and(File::exists($extractedRun.'/models/xgboost_classifier.ubj'))->toBeTrue()
        ->and(File::exists($extractedRun.'/dataset/training-data.csv'))->toBeTrue()
        ->and(File::glob(config('nfl_ml.bundle.input_directory').'/*.json'))->toBe([]);

    File::append($extractedRun.'/preprocessor.joblib', '-tampered');
    expect(fn () => app(NflTabularModelInferenceService::class)->predict($artifact, [
        'feature_home_elo' => 1532,
    ]))->toThrow(RuntimeException::class, 'failed hash verification');
});

it('rejects a subprocess response whose artifact lineage does not match Laravel', function () {
    $fixture = nflTabularRunFixture($this->nflMlTestRoot.'/lineage-run');
    $artifact = app(NflTabularModelRunRegistrar::class)->register($fixture['run_directory']);
    $cli = nflTabularFakeCli($this->nflMlTestRoot.'/wrong-lineage-cli.php', [
        'artifact_id' => (string) Str::uuid(),
    ]);
    config()->set('nfl_ml.process.command', [PHP_BINARY, $cli]);

    expect(fn () => app(NflTabularModelInferenceService::class)->predict($artifact, [
        'feature_home_elo' => 1532,
    ]))->toThrow(RuntimeException::class, 'failed lineage verification');
});

it('maps a registered tabular model into tracking-only NFL shadow outputs', function () {
    $fixture = nflTabularRunFixture($this->nflMlTestRoot.'/shadow-run');
    $artifact = app(NflTabularModelRunRegistrar::class)->register($fixture['run_directory']);
    $cli = nflTabularFakeCli($this->nflMlTestRoot.'/shadow-cli.php');
    config()->set('nfl_ml.process.command', [PHP_BINARY, $cli]);
    config()->set('nfl_ml.shadow.enabled', true);
    config()->set('nfl_ml.shadow.artifact_id', $artifact->id);

    $game = Game::factory()->create([
        'home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id,
        'status' => 'STATUS_SCHEDULED',
    ]);
    $shadow = app(NflFullHistoricalShadowInferenceService::class)->evaluate(
        $game,
        [
            'predicted_spread' => 2.1,
            'predicted_total' => 43.9,
            'win_probability' => 0.55,
            'confidence_score' => 55.0,
        ],
        [
            'feature_home_elo' => 1532,
            'feature_market_home_spread' => -2.5,
            'feature_market_total' => 44.5,
        ],
    );

    expect($shadow)
        ->not->toBeNull()
        ->and($shadow['profile'])->toBe('python-tabular')
        ->and($shadow['challenger_output'])->toBe(0.614)
        ->and(data_get($shadow, 'challenger_outputs.predicted_spread'))->toBe(3.8)
        ->and(data_get($shadow, 'challenger_outputs.predicted_total'))->toBe(44.7)
        ->and($shadow['active_source'])->toBe('baseline')
        ->and($shadow['apply_to_live_output'])->toBeFalse()
        ->and($shadow['public_output_changed'])->toBeFalse();
});

/**
 * @return array{
 *     run_directory: string,
 *     dataset_path: string,
 *     dataset_hash: string,
 *     model_run_id: string,
 *     artifact_id: string,
 *     config_hash: string
 * }
 */
function nflTabularRunFixture(string $runDirectory, ?string $modelRunId = null): array
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

/**
 * @param  array<string, mixed>  $overrides
 */
function nflTabularFakeCli(string $path, array $overrides = []): string
{
    $overrideCode = var_export($overrides, true);
    File::put($path, <<<'PHP'
<?php

$arguments = array_slice($argv, 1);
if (($arguments[0] ?? null) !== 'predict') {
    fwrite(STDERR, "expected predict command\n");
    exit(2);
}
$runDirectory = $arguments[array_search('--run-dir', $arguments, true) + 1] ?? null;
$inputPath = $arguments[array_search('--input', $arguments, true) + 1] ?? null;
$manifest = json_decode((string) file_get_contents($runDirectory.'/manifest.json'), true);
$features = json_decode((string) file_get_contents($inputPath), true);
$output = [
    'home_win_probability' => 0.614,
    'expected_home_margin' => 3.8,
    'expected_total' => 44.7,
    'home_cover_probability' => 0.558,
    'over_probability' => 0.472,
    'uncertainty' => 0.081,
    'model_run_id' => $manifest['model_run_id'],
    'artifact_id' => $manifest['artifact_id'],
    'dataset_hash' => $manifest['dataset_hash'],
    'feature_hash' => hash('sha256', json_encode($features)),
];
$output = array_replace($output, __OVERRIDES__);
echo json_encode([$output], JSON_THROW_ON_ERROR);
PHP);
    File::put($path, str_replace('__OVERRIDES__', $overrideCode, File::get($path)));

    return $path;
}
