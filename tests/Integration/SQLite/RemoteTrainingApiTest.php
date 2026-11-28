<?php

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Models\ModelTrainingAttempt;
use App\Models\ModelTrainingTransferSlot;
use App\Models\ModelTrainingUpload;
use App\Models\ModelTrainingWorker;
use App\Services\ML\RemoteTrainingPreparation;
use App\Services\ML\RemoteTrainingService;
use App\Services\ML\ShadowArtifactSelector;
use App\Services\ML\WeeklyChallengerTrainingService;
use App\Services\MLB\MlbArtifactCohortSerializer;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\ObservedMlbDatasetFixture;
use Tests\Support\SingleActorSqliteMlbArtifactCohortSerializer;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    Http::preventStrayRequests();
    app()->instance(MlbArtifactCohortSerializer::class, new SingleActorSqliteMlbArtifactCohortSerializer);
    // Actual owned source capture and explicit exporter; no inherited transaction or read-time stamping.
    $this->ownedMlbDataset = ObservedMlbDatasetFixture::create(true);
    $this->root = sys_get_temp_dir().'/picksports-remote-'.Str::uuid();
    Storage::fake('remote-test-objects');
    Storage::fake('remote-test-cache');
    config()->set('ml.storage.disk', 'remote-test-objects');
    config()->set('ml.storage.cache_disk', 'remote-test-cache');
    config()->set('ml.remote.enabled', true);
    config()->set('ml.remote.staging_directory', $this->root.'/staging');
    foreach (['nfl', 'mlb'] as $sport) {
        config()->set($sport.'_ml.weekly_training.work_directory', $this->root.'/'.$sport);
        config()->set($sport.'_ml.weekly_training.enabled', true);
        config()->set($sport.'_ml.bundle.staging_directory', $this->root.'/bundles/'.$sport);
        Artisan::shouldReceive('call')->with($sport.':export-training-data', Mockery::on(function (array $arguments) use ($sport): bool {
            if ($sport === 'mlb') {
                File::copy($this->ownedMlbDataset['path'], $arguments['--path']);
                File::copy($this->ownedMlbDataset['path'].'.manifest.json', $arguments['--path'].'.manifest.json');
            } else {
                File::put($arguments['--path'], "target_home_win\n1\n");
            }

            return true;
        }))->andReturn(0);
    }
    Artisan::shouldReceive('call')->with('nfl:backfill-historical-predictions', Mockery::type('array'))->andReturn(0);
    $this->completionFailures = 0;
    Event::listen('eloquent.updating: '.ModelArtifact::class, function (ModelArtifact $artifact): void {
        if ($artifact->sport === 'mlb' && $artifact->isDirty('promotion_decision') && $this->completionFailures > 0) {
            $this->completionFailures--;
            throw new RuntimeException('Simulated crash after object storage writes.');
        }
    });
    Artisan::shouldReceive('call')->with('sports:evaluate-model-promotion', Mockery::on(fn (array $arguments): bool => array_keys($arguments) === ['artifact']))
        ->andReturnUsing(function (): int {
            if ($this->completionFailures > 0) {
                $this->completionFailures--;
                throw new RuntimeException('Simulated crash after object storage writes.');
            }

            return 0;
        });
    $shadow = Mockery::mock(ShadowArtifactSelector::class);
    $shadow->shouldReceive('activeChallenger')->andReturnNull();
    app()->instance(ShadowArtifactSelector::class, $shadow);
    $this->token = bin2hex(random_bytes(48));
    $this->worker = ModelTrainingWorker::query()->create([
        'id' => (string) Str::uuid(), 'name' => 'test mini', 'token_hash' => hash('sha256', $this->token),
        'sports' => ['nfl', 'mlb'], 'abilities' => ['training:read', 'training:claim', 'training:upload', 'training:complete'],
        'expires_at' => now()->addDay(),
    ]);
    $this->headers = ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    $this->prepare = function (string $sport = 'mlb'): ModelRun {
        $result = app(WeeklyChallengerTrainingService::class)->run($sport, true, false, true, $this->worker->id);

        return ModelRun::query()->findOrFail($result['cycle_run_id']);
    };
    $this->claim = fn (ModelRun $cycle) => $this->postJson('/api/ml-worker/v1/cycles/'.$cycle->id.'/claim', [], $this->headers);
    $this->putArtifact = function (array $task, string $path, string $content, ?string $sha256 = null) {
        return $this->call('PUT', remoteAttemptUrl($task).'/files', [], [], [], [
            'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'HTTP_X_TRAINING_OWNER' => $task['owner_token'], 'HTTP_X_ARTIFACT_PATH' => $path,
            'HTTP_X_CONTENT_SHA256' => $sha256 ?? hash('sha256', $content), 'CONTENT_LENGTH' => (string) strlen($content),
            'CONTENT_TYPE' => 'application/octet-stream',
        ], $content);
    };
});

afterEach(function () {
    File::deleteDirectory($this->root);
    @unlink($this->ownedMlbDataset['path']);
    @unlink($this->ownedMlbDataset['path'].'.manifest.json');
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

function remoteAttemptUrl(array $task): string
{
    return '/api/ml-worker/v1/cycles/'.$task['cycle_id'].'/attempts/'.$task['attempt_id'];
}

function remoteFiles(array $task, string $schema): array
{
    $artifact = (string) Str::uuid();
    $schemaHash = hash('sha256', 'semantic-schema');
    $files = [
        'feature_schema.yaml' => $schema, 'preprocessor.joblib' => 'preprocessor', 'prediction_example.json' => '{}',
        'models/logistic_classifier.joblib' => 'logistic', 'models/xgboost_classifier.ubj' => 'classifier',
        'models/xgboost_home_margin.ubj' => 'margin', 'models/xgboost_total_points.ubj' => 'total',
        'calibrators/logistic_regression_isotonic.joblib' => 'isotonic', 'calibrators/logistic_regression_platt.joblib' => 'platt',
        'calibrators/xgboost_isotonic.joblib' => 'xgb-isotonic', 'calibrators/xgboost_platt.joblib' => 'xgb-platt',
        'evaluation.json' => json_encode([
            'report_type' => $task['sport'].'_tabular_walk_forward_evaluation',
            'model_type' => $task['sport'].'_tabular_bundle',
            'model_run_id' => $task['run_id'], 'artifact_id' => $artifact,
            'dataset' => ['sha256' => $task['dataset']['sha256']], 'feature_schema' => ['sha256' => $schemaHash],
        ]),
    ];
    if ($task['sport'] === 'nfl') {
        $files = array_combine(
            array_map(fn (string $path): string => str_replace(['.joblib', '.ubj', 'feature_schema.yaml'], ['.json', '.json', 'feature_schema.json'], $path), array_keys($files)),
            array_values($files),
        );
        $files['feature_schema.json'] = json_encode(Yaml::parse($schema), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
        $files['tuning/optuna_provenance.json'] = '{}';
        $files['explanations/global_shap_importance.json'] = '{}';
        $files['explanations/xgboost_feature_importance.json'] = '{}';
        $files['explanations/shap_values.parquet'] = 'synthetic-nonexecuted-evidence';
    }
    if ($task['sport'] === 'mlb') {
        $files = array_combine(
            array_map(fn (string $path): string => str_replace(['.joblib', '.ubj', 'feature_schema.yaml'], ['.json', '.json', 'feature_schema.json'], $path), array_keys($files)),
            array_values($files),
        );
        $files['feature_schema.json'] = json_encode(Yaml::parse($schema), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
    }
    $inventory = [];
    foreach ($files as $path => $content) {
        $inventory[$path] = ['sha256' => hash('sha256', $content), 'bytes' => strlen($content)];
    }
    $files['manifest.json'] = json_encode([
        'manifest_version' => $task['sport'] === 'nfl' ? 2 : 1,
        ...($task['sport'] === 'nfl' ? ['execution_format' => 'picksports-nfl-tabular-numeric-v1', 'blend_version' => 'baseline-anchored-v2'] : []),
        ...($task['sport'] === 'mlb' ? ['execution_format' => 'picksports-tabular-numeric-v1', 'generated_at' => now()->utc()->toIso8601String()] : []),
        'model_type' => $task['sport'].'_tabular_bundle',
        'model_version' => $task['sport'] === 'nfl' ? 'nfl-tabular-v3' : 'mlb-tabular-v1',
        'package' => 'picksports_'.$task['sport'].'_ml', 'module' => 'picksports_'.$task['sport'].'_ml',
        'model_run_id' => $task['run_id'], 'artifact_id' => $artifact,
        'dataset_hash' => $task['dataset']['sha256'], 'feature_schema_hash' => $schemaHash,
        'feature_schema_version' => $task['sport'] === 'nfl' ? 'nfl-pregame-ml-v3' : 'mlb-pregame-ml-v1',
        'generated_at' => now()->utc()->toIso8601String(),
        'source_hash' => $task['source_hash'], 'config_hash' => hash('sha256', 'config'), 'artifacts' => $inventory,
    ]);

    return $files;
}

function remoteResultHash(array $files): string
{
    ksort($files);
    $context = hash_init('sha256');
    foreach ($files as $path => $content) {
        hash_update($context, $path."\0".hash('sha256', $content)."\0".strlen($content)."\n");
    }

    return hash_final($context);
}

it('is opt in and requires dedicated expiring credentials rather than user authentication', function () {
    config()->set('ml.remote.enabled', false);
    $this->getJson('/api/ml-worker/v1/cycles', $this->headers)->assertNotFound();
    config()->set('ml.remote.enabled', true);
    $this->getJson('/api/ml-worker/v1/cycles')->assertUnauthorized();
    $this->getJson('/api/ml-worker/v1/cycles', ['Authorization' => 'Bearer '.str_repeat('b', 96)])->assertUnauthorized();
    $this->worker->update(['expires_at' => now()->subSecond()]);
    $this->getJson('/api/ml-worker/v1/cycles', $this->headers)->assertUnauthorized();
    $this->worker->update(['expires_at' => now()->addDay(), 'revoked_at' => now()]);
    $this->getJson('/api/ml-worker/v1/cycles', $this->headers)->assertUnauthorized();
});

it('enforces operation sport and assigned worker scope without exposing private metadata', function () {
    $cycle = ($this->prepare)();
    $this->getJson('/api/ml-worker/v1/cycles', $this->headers)->assertExactJson(['data' => [['cycle_id' => $cycle->id, 'sport' => 'mlb']]]);
    $this->worker->update(['abilities' => ['training:read']]);
    ($this->claim)($cycle)->assertForbidden();
    $this->worker->update(['abilities' => ['training:read', 'training:claim'], 'sports' => ['nfl']]);
    ($this->claim)($cycle)->assertNotFound();
    $this->worker->update(['sports' => ['mlb']]);
    $cycle->update(['metadata' => [...$cycle->metadata, 'remote_worker_id' => (string) Str::uuid()]]);
    ($this->claim)($cycle)->assertNotFound();
    expect(ModelTrainingAttempt::query()->count())->toBe(0);
});

it('downloads private immutable inputs replays claims and registers a complete verified run once', function (string $sport) {
    $cycle = ($this->prepare)($sport);
    $task = ($this->claim)($cycle)->assertOk()->json();
    expect(($this->claim)($cycle)->assertOk()->json())->toBe($task)
        ->and(ModelTrainingAttempt::query()->count())->toBe(1)
        ->and(json_encode($task))->not->toContain('object_key', 'token_hash', 'dataset_path', 'python_command', 'schema_path');
    File::delete(data_get($cycle->metadata, 'dataset_path'));
    if ($sport === 'mlb') {
        File::delete(data_get($cycle->metadata, 'dataset_path').'.manifest.json');
    }
    $ownerHeaders = [...$this->headers, 'X-Training-Owner' => $task['owner_token']];
    $this->get(remoteAttemptUrl($task).'/inputs/dataset', $ownerHeaders)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $schema = File::get(config($sport.'_ml.weekly_training.schema_path'));
    $this->get(remoteAttemptUrl($task).'/inputs/schema', $ownerHeaders)->assertOk();
    $files = remoteFiles($task, $schema);
    foreach ($files as $path => $content) {
        ($this->putArtifact)($task, $path, $content)->assertOk();
    }
    ($this->putArtifact)($task, 'preprocessor.json', 'preprocessor')->assertOk();
    ($this->putArtifact)($task, 'preprocessor.json', 'conflict')->assertConflict();
    $headers = [...$ownerHeaders, 'X-Result-SHA256' => remoteResultHash($files)];
    $result = $this->postJson(remoteAttemptUrl($task).'/complete', [], $headers)->assertOk()->json();
    $this->postJson(remoteAttemptUrl($task).'/complete', [], $headers)->assertOk()->assertExactJson($result);
    ($this->claim)($cycle)->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('result.artifact_id', $result['artifact_id']);
    $this->postJson(remoteAttemptUrl($task).'/complete', [], [...$ownerHeaders, 'X-Result-SHA256' => str_repeat('a', 64)])->assertConflict();
    ($this->putArtifact)($task, 'unexpected.json', '{}')->assertConflict();
    expect(ModelArtifact::query()->count())->toBe(1)
        ->and(ModelArtifact::query()->sole()->status)->toBe('challenger')
        ->and(ModelRun::query()->findOrFail($cycle->id)->status)->toBe('completed')
        ->and(ModelTrainingUpload::query()->count())->toBe(count($files));
    foreach (ModelTrainingUpload::query()->get() as $upload) {
        expect(Storage::disk($upload->disk)->getVisibility($upload->object_key))->toBe('private');
    }
})->with(['mlb', 'nfl']);

it('rejects traversal wrong hashes oversize files and quota overflow', function () {
    $task = ($this->claim)(($this->prepare)())->assertOk()->json();
    foreach (['../escape', '/absolute', 'models/../../escape', 'models\\escape', '.hidden', 'models//x'] as $path) {
        ($this->putArtifact)($task, $path, 'bad')->assertUnprocessable();
    }
    ($this->putArtifact)($task, 'model.ubj', 'bad', str_repeat('a', 64))->assertUnprocessable();
    config()->set('ml.remote.maximum_file_bytes', 2);
    ($this->putArtifact)($task, 'model.ubj', 'bad')->assertUnprocessable();
    config()->set('ml.remote.maximum_file_bytes', 100);
    config()->set('ml.remote.maximum_total_bytes', 4);
    ($this->putArtifact)($task, 'one.ubj', '123')->assertOk();
    ($this->putArtifact)($task, 'two.ubj', '456')->assertStatus(413);
    expect(ModelTrainingUpload::query()->count())->toBe(1);
});

it('rejects late or forged owners and recovers an expired claim', function () {
    $cycle = ($this->prepare)();
    $task = ($this->claim)($cycle)->assertOk()->json();
    $this->getJson(remoteAttemptUrl($task).'/inputs/dataset', [...$this->headers, 'X-Training-Owner' => (string) Str::uuid()])->assertConflict();
    $this->travel(15001)->seconds();
    $replacement = ($this->claim)($cycle)->assertOk()->json();
    expect($replacement['attempt_id'])->not->toBe($task['attempt_id']);
    ($this->putArtifact)($task, 'old.json', '{}')->assertConflict();
    $this->postJson(remoteAttemptUrl($task).'/fail', [], [...$this->headers, 'X-Training-Owner' => $task['owner_token']])->assertConflict();
    expect(ModelTrainingUpload::query()->count())->toBe(0)
        ->and(ModelTrainingAttempt::query()->findOrFail($task['attempt_id'])->status)->toBe('abandoned');
});

it('rejects mismatched lineage schema and inventories without registration', function (string $problem) {
    $cycle = ($this->prepare)();
    $task = ($this->claim)($cycle)->assertOk()->json();
    $files = remoteFiles($task, File::get(config('mlb_ml.weekly_training.schema_path')));
    $manifest = json_decode($files['manifest.json'], true);
    if ($problem === 'source') {
        $manifest['source_hash'] = str_repeat('a', 64);
    } elseif ($problem === 'schema') {
        $files['feature_schema.json'] = 'wrong schema';
    } elseif ($problem === 'inventory') {
        $files['models/xgboost_classifier.json'] = 'corrupted after manifest';
    } else {
        unset($files['models/xgboost_classifier.json']);
    }
    $files['manifest.json'] = json_encode($manifest);
    foreach ($files as $path => $content) {
        ($this->putArtifact)($task, $path, $content)->assertOk();
    }
    $this->postJson(remoteAttemptUrl($task).'/complete', [], [...$this->headers,
        'X-Training-Owner' => $task['owner_token'], 'X-Result-SHA256' => remoteResultHash($files),
    ])->assertUnprocessable();
    expect(ModelArtifact::query()->count())->toBe(0)
        ->and(ModelTrainingAttempt::query()->sole()->status)->toBe('running');
})->with(['source', 'schema', 'inventory', 'missing']);

it('makes upload receipts immutable even through quiet model methods', function () {
    $task = ($this->claim)(($this->prepare)())->assertOk()->json();
    ($this->putArtifact)($task, 'example.json', '{}')->assertOk();
    $upload = ModelTrainingUpload::query()->sole();
    expect(fn () => $upload->updateQuietly(['sha256' => str_repeat('b', 64)]))->toThrow(LogicException::class);
    expect(fn () => $upload->deleteQuietly())->toThrow(LogicException::class);
    expect(fn () => $upload->incrementQuietly('bytes'))->toThrow(LogicException::class);
    expect(fn () => $upload->decrementQuietly('bytes'))->toThrow(LogicException::class);
    expect($upload->refresh()->sha256)->toBe(hash('sha256', '{}'));
});

it('fences the public completion service against a mismatched cycle', function () {
    $cycle = ($this->prepare)();
    ($this->claim)($cycle)->assertOk();
    $other = $cycle->replicate();
    $other->id = (string) Str::uuid();
    $other->save();
    expect(fn () => app(WeeklyChallengerTrainingService::class)->completeAttempt(
        $other, ModelTrainingAttempt::query()->sole(), '/unused', str_repeat('a', 64),
    ))->toThrow(RuntimeException::class, 'does not match its attempt');
    expect(ModelArtifact::query()->count())->toBe(0);
});

it('reuses immutable stored objects when database completion rolls back after registration', function () {
    $cycle = ($this->prepare)();
    $task = ($this->claim)($cycle)->assertOk()->json();
    $files = remoteFiles($task, File::get(config('mlb_ml.weekly_training.schema_path')));
    foreach ($files as $path => $content) {
        ($this->putArtifact)($task, $path, $content)->assertOk();
    }
    $headers = [...$this->headers, 'X-Training-Owner' => $task['owner_token'], 'X-Result-SHA256' => remoteResultHash($files)];
    $this->completionFailures = 1;
    $this->postJson(remoteAttemptUrl($task).'/complete', [], $headers)->assertConflict();
    expect(ModelArtifact::query()->count())->toBe(0)
        ->and(ModelTrainingAttempt::query()->sole()->status)->toBe('running');
    $objectsAfterFailure = Storage::disk('remote-test-objects')->allFiles();
    $this->postJson(remoteAttemptUrl($task).'/complete', [], $headers)->assertOk();
    expect(ModelArtifact::query()->count())->toBe(1)
        ->and(Storage::disk('remote-test-objects')->allFiles())->toBe($objectsAfterFailure);
});

it('does not grant customer API access to a worker credential', function () {
    $this->getJson('/api/v2/auth/me', $this->headers)->assertUnauthorized();
});

it('matches the Python training source identity for each supported sport', function (string $sport) {
    $process = new Process([
        'python3', '-c', 'import runpy,sys; m=runpy.run_path(sys.argv[1]); print(m["source_hash"](m["Path"](sys.argv[2]),sys.argv[3]))',
        base_path('scripts/ml_worker.py'), base_path('ml/'.$sport), $sport,
    ]);
    $process->mustRun();
    expect(app(RemoteTrainingPreparation::class)->sourceHash($sport, base_path('ml/'.$sport)))
        ->toBe(trim($process->getOutput()));
})->with(['nfl', 'mlb']);

it('reserves bounded staging before body reads and reclaims expired upload reservations', function () {
    $cycle = ($this->prepare)();
    $task = ($this->claim)($cycle)->assertOk()->json();
    $oldOwner = (string) Str::uuid();
    $slot = ModelTrainingTransferSlot::query()->create([
        'worker_id' => $this->worker->id, 'attempt_id' => $task['attempt_id'],
        'owner_token' => $oldOwner, 'reserved_bytes' => 3, 'expires_at' => now()->addSeconds(600),
    ]);
    $oldDirectory = $this->root.'/staging/'.$oldOwner;
    File::ensureDirectoryExists($oldDirectory);
    File::put($oldDirectory.'/upload', 'old');
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, 'new');
    rewind($stream);
    try {
        expect(fn () => app(RemoteTrainingService::class)->upload(
            $this->worker, $cycle->id, $task['attempt_id'], $task['owner_token'], 'model.ubj', hash('sha256', 'new'), $stream, 3,
        ))->toThrow(HttpException::class, 'Upload staging is busy');
        expect(ftell($stream))->toBe(0)->and(ModelTrainingUpload::query()->count())->toBe(0);
        $this->travel(601)->seconds();
        ($this->putArtifact)($task, 'model.ubj', 'new')->assertOk();
        expect(File::exists($oldDirectory))->toBeFalse()
            ->and($slot->refresh()->reserved_bytes)->toBe(0);
    } finally {
        fclose($stream);
    }
});

it('enforces global staging bytes and metadata caps before reading files', function () {
    $task = ($this->claim)(($this->prepare)())->assertOk()->json();
    config()->set('ml.remote.maximum_staging_bytes', 2);
    ($this->putArtifact)($task, 'model.ubj', '123')->assertStatus(429);
    config()->set('ml.remote.maximum_staging_bytes', 100);
    config()->set('ml.remote.maximum_manifest_bytes', 2);
    ($this->putArtifact)($task, 'manifest.json', '{} ')->assertStatus(413);
    config()->set('ml.remote.maximum_report_bytes', 2);
    ($this->putArtifact)($task, 'evaluation.json', '{} ')->assertStatus(413);
    expect(ModelTrainingUpload::query()->count())->toBe(0);
});

it('retains exhausted attempt history when a remote recovery claim is refused', function () {
    config()->set('ml.training.maximum_attempts', 1);
    $cycle = ($this->prepare)();
    $task = ($this->claim)($cycle)->assertOk()->json();
    $this->travel(15001)->seconds();
    ($this->claim)($cycle)->assertConflict();
    expect(ModelTrainingAttempt::query()->sole()->status)->toBe('abandoned')
        ->and($cycle->refresh()->status)->toBe('failed');
    $this->getJson('/api/ml-worker/v1/cycles', $this->headers)->assertExactJson(['data' => []]);
});

it('refuses unqualified remote completion and changed frozen caller bindings', function (string $change) {
    $cycle = ($this->prepare)();
    ($this->claim)($cycle)->assertOk();
    $cycle->refresh();
    if ($change === 'dataset') {
        File::put(data_get($cycle->metadata, 'dataset_path'), "target_home_win\n0\n");
    } else {
        $metadata = $cycle->metadata;
        $metadata['training_specification']['model_type'] = 'other-model';
        $cycle->metadata = $metadata;
    }
    expect(fn () => app(WeeklyChallengerTrainingService::class)->completeAttempt(
        $cycle, ModelTrainingAttempt::query()->sole(), '/unused', str_repeat('a', 64),
    ))->toThrow(RuntimeException::class, $change === 'dataset'
        ? 'MLB completion must bind its actual remote credential and uploads.'
        : 'MLB completion frozen cycle binding changed after its caller snapshot.');
    expect(ModelArtifact::query()->count())->toBe(0)
        ->and(ModelRun::query()->where('run_type', 'training')->count())->toBe(0)
        ->and(ModelTrainingAttempt::query()->sole()->status)->toBe('running');
    if ($change === 'dataset') {
        expect(hash_file('sha256', data_get($cycle->metadata, 'dataset_path')))->not->toBe(data_get($cycle->fresh()->metadata, 'dataset_hash'));
    }
})->with(['dataset', 'specification']);

it('refuses NFL numeric returned feature order drift before artifact registration', function () {
    $cycle = ($this->prepare)('nfl');
    $task = ($this->claim)($cycle)->assertOk()->json();
    $files = remoteFiles($task, File::get(config('nfl_ml.weekly_training.schema_path')));
    $schema = json_decode($files['feature_schema.json'], true, flags: JSON_THROW_ON_ERROR);
    $schema['features'] = array_reverse($schema['features'], true);
    $files['feature_schema.json'] = json_encode($schema, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    $manifest = json_decode($files['manifest.json'], true, flags: JSON_THROW_ON_ERROR);
    $manifest['artifacts']['feature_schema.json'] = ['bytes' => strlen($files['feature_schema.json']), 'sha256' => hash('sha256', $files['feature_schema.json'])];
    $files['manifest.json'] = json_encode($manifest, JSON_THROW_ON_ERROR);
    foreach ($files as $path => $body) {
        ($this->putArtifact)($task, $path, $body)->assertOk();
    }
    $this->postJson(remoteAttemptUrl($task).'/complete', [], [...$this->headers, 'X-Training-Owner' => $task['owner_token'], 'X-Result-SHA256' => remoteResultHash($files)])
        ->assertUnprocessable();
    expect(ModelArtifact::query()->count())->toBe(0);
});

it('transfers NFL numeric components under their fixed component cap without broadening unknown JSON', function () {
    $cycle = ($this->prepare)('nfl');
    $task = ($this->claim)($cycle)->assertOk()->json();
    // Transport-only bytes: no completion or numerical model admission is claimed.
    $body = '{"padding":"'.str_repeat('x', 2097152).'"}';
    ($this->putArtifact)($task, 'preprocessor.json', $body)->assertOk();
    ($this->putArtifact)($task, 'unknown-model.json', $body)->assertStatus(413);
    $this->call('PUT', remoteAttemptUrl($task).'/files', [], [], [], [
        'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        'HTTP_X_TRAINING_OWNER' => $task['owner_token'], 'HTTP_X_ARTIFACT_PATH' => 'models/xgboost_classifier.json',
        'HTTP_X_CONTENT_SHA256' => hash('sha256', ''), 'CONTENT_LENGTH' => '8388609', 'CONTENT_TYPE' => 'application/octet-stream',
    ], '')->assertStatus(413);
    expect(ModelTrainingUpload::query()->count())->toBe(1)->and(ModelArtifact::query()->count())->toBe(0);
});

it('publishes MLB upload storage outside worker and cycle SQL locks', function () {
    $cycle = ($this->prepare)();
    $task = ($this->claim)($cycle)->assertOk()->json();
    $real = Storage::disk('remote-test-objects');
    $disk = Mockery::mock(FilesystemAdapter::class);
    $levels = [];
    $disk->shouldReceive('put')->once()->andReturnUsing(function ($path, $contents, $options) use ($real, &$levels) {
        $levels[] = DB::connection()->transactionLevel();

        return $real->put($path, $contents, $options);
    });
    Storage::shouldReceive('disk')->with('remote-test-objects')->andReturn($disk);
    ($this->putArtifact)($task, 'preprocessor.json', '{}')->assertOk();
    expect($levels)->toBe([0])->and(ModelTrainingUpload::query()->count())->toBe(1);
});

it('rechecks MLB upload credentials after object staging before publishing its pointer', function () {
    $cycle = ($this->prepare)();
    $task = ($this->claim)($cycle)->assertOk()->json();
    $real = Storage::disk('remote-test-objects');
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->once()->andReturnUsing(function ($path, $contents, $options) use ($real) {
        $stored = $real->put($path, $contents, $options);
        ModelTrainingWorker::query()->whereKey($this->worker->id)->update(['revoked_at' => now()]);

        return $stored;
    });
    Storage::shouldReceive('disk')->with('remote-test-objects')->andReturn($disk);
    ($this->putArtifact)($task, 'preprocessor.json', '{}')->assertUnauthorized();
    expect(ModelTrainingUpload::query()->count())->toBe(0)
        ->and(ModelTrainingAttempt::query()->sole()->status)->toBe('running');
});

it('refuses MLB upload lease changes during object staging without a pointer', function (string $lease) {
    $cycle = ($this->prepare)();
    $task = ($this->claim)($cycle)->assertOk()->json();
    $real = Storage::disk('remote-test-objects');
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->once()->andReturnUsing(function ($path, $contents, $options) use ($real, $lease, $task) {
        $stored = $real->put($path, $contents, $options);
        if ($lease === 'attempt') {
            ModelTrainingAttempt::query()->whereKey($task['attempt_id'])->update(['lease_expires_at' => now()->subSecond()]);
        } else {
            ModelTrainingTransferSlot::query()->whereKey($this->worker->id)->update(['expires_at' => now()->subSecond()]);
        }

        return $stored;
    });
    Storage::shouldReceive('disk')->with('remote-test-objects')->andReturn($disk);
    ($this->putArtifact)($task, 'preprocessor.json', '{}')->assertConflict();
    expect(ModelTrainingUpload::query()->count())->toBe(0)
        ->and(ModelTrainingAttempt::query()->sole()->status)->toBe('running');
})->with(['attempt', 'transfer']);

it('refuses excess MLB transfer reservation inventory before consuming the request stream', function () {
    $cycle = ($this->prepare)();
    $task = ($this->claim)($cycle)->assertOk()->json();
    $workers = $slots = [];
    for ($i = 0; $i < 1000; $i++) {
        $id = (string) Str::uuid();
        $workers[] = ['id' => $id, 'name' => 'synthetic inventory', 'token_hash' => hash('sha256', $id),
            'sports' => '["mlb"]', 'abilities' => '[]', 'expires_at' => now()->addDay()];
        $slots[] = ['worker_id' => $id, 'attempt_id' => $task['attempt_id'], 'owner_token' => (string) Str::uuid(),
            'reserved_bytes' => 0, 'expires_at' => now()->subSecond()];
    }
    ModelTrainingWorker::query()->insert($workers);
    ModelTrainingTransferSlot::query()->insert($slots);
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, 'x');
    rewind($stream);
    try {
        expect(fn () => app(RemoteTrainingService::class)->upload($this->worker, $cycle->id, $task['attempt_id'],
            $task['owner_token'], 'preprocessor.json', hash('sha256', 'x'), $stream, 1))
            ->toThrow(HttpException::class, 'reservation inventory exceeds');
        expect(ftell($stream))->toBe(0)->and(ModelTrainingUpload::query()->count())->toBe(0)
            ->and(ModelTrainingTransferSlot::query()->count())->toBe(1000);
    } finally {
        fclose($stream);
    }
});
