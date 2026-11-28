<?php

use App\Exceptions\EloAdmissionRefused;
use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\ML\MlArtifactStorage;
use App\Services\ML\MlStoredObject;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\MLB\MlbArtifactCohortSerializer;
use App\Services\MLB\MlbTabularDatasetAdmission;
use App\Services\MLB\MlbTabularModelBundle;
use App\Services\MLB\MlbTabularModelRunRegistrar;
use App\Services\MLB\MlbTabularSourceFence;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ObservedMlbDatasetFixture;
use Tests\Support\SingleActorSqliteMlbArtifactCohortSerializer;
use Tests\Support\SyntheticMlbTabularBundleFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Functional range collaborator only: current source and transaction admission remain real.
    app()->instance(MlbArtifactCohortSerializer::class, new SingleActorSqliteMlbArtifactCohortSerializer);
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
});

afterEach(function () {
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

it('commits new owned target rows only after current MLB source validation', function () {
    $fixture = ObservedMlbDatasetFixture::create();
    $id = (string) Str::uuid();
    $result = app(MlbTabularSourceFence::class)->run($fixture['proof'], function () use ($id) {
        expect(DB::transactionLevel())->toBe(1);

        return ModelRun::create(['id' => $id, 'sport' => 'mlb', 'run_type' => 'training', 'model_version' => 'candidate',
            'feature_version' => 'test-v1', 'blend_version' => 'test-v1', 'config_hash' => hash('sha256', 'test'), 'status' => 'completed', 'started_at' => now()]);
    });
    expect($result->id)->toBe($id)->and(ModelRun::find($id))->not->toBeNull();
});

it('refuses current MLB source changes before the commit callback', function (string $field) {
    $fixture = ObservedMlbDatasetFixture::create();
    if ($field === 'game') {
        DB::table('mlb_games')->where('id', $fixture['game']->id)->update(['game_time' => '20:30:00']);
    } elseif ($field === 'target') {
        DB::table('prediction_evaluations')->where('id', $fixture['evaluation']->id)->update(['actuals' => json_encode(['actual_spread' => 99, 'actual_total' => 101])]);
    } elseif ($field === 'owner') {
        DB::table('prediction_feature_snapshots')->where('id', $fixture['snapshot']->id)->update(['model_run_id' => ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'mlb', 'run_type' => 'prediction', 'model_version' => 'other', 'feature_version' => 'test-v1', 'blend_version' => 'test-v1', 'config_hash' => hash('sha256', 'other'), 'status' => 'completed', 'started_at' => now()])->id]);
    } else {
        DB::table('model_runs')->where('id', $fixture['snapshot']->model_run_id)->update(['config_hash' => hash('sha256', 'changed')]);
    }
    $before = ModelRun::count();
    $called = false;
    expect(fn () => app(MlbTabularSourceFence::class)->run($fixture['proof'], function () use (&$called) {
        $called = true;
    }))
        ->toThrow(RuntimeException::class)->and($called)->toBeFalse()->and(ModelRun::count())->toBe($before);
})->with(['game', 'target', 'owner', 'capture_run_config']);

it('refuses unknown inherited lock ownership without calling the callback', function () {
    $fixture = ObservedMlbDatasetFixture::create();
    $called = false;
    DB::transaction(function () use ($fixture, &$called) {
        expect(fn () => app(MlbTabularSourceFence::class)->run($fixture['proof'], function () use (&$called) {
            $called = true;
        }))
            ->toThrow(RuntimeException::class, 'unknown inherited');
    });
    expect($called)->toBeFalse();
});

it('rolls back target insertion when a current-source commit callback fails', function () {
    $fixture = ObservedMlbDatasetFixture::create();
    $id = (string) Str::uuid();
    expect(fn () => app(MlbTabularSourceFence::class)->run($fixture['proof'], function () use ($id) {
        ModelRun::create(['id' => $id, 'sport' => 'mlb', 'run_type' => 'training', 'model_version' => 'candidate',
            'feature_version' => 'test-v1', 'blend_version' => 'test-v1', 'config_hash' => hash('sha256', 'test'), 'status' => 'running', 'started_at' => now()]);
        throw new RuntimeException('Synthetic ownership refusal.');
    }))->toThrow(RuntimeException::class, 'Synthetic ownership');
    expect(ModelRun::find($id))->toBeNull();
});

function mlbStagedRegistryFixture(bool $provideBundle = true, bool $cloneBundle = false): array
{
    Storage::fake('mlb-stage-source-test');
    Storage::fake('mlb-stage-cache-test');
    config()->set('ml.storage.disk', 'mlb-stage-source-test');
    config()->set('ml.storage.cache_disk', 'mlb-stage-cache-test');
    config()->set('ml.storage.prefix', 'ml');
    $fixture = ObservedMlbDatasetFixture::create(true);
    $runDirectory = $fixture['path'].'.run';
    $runId = (string) Str::uuid();
    $artifactId = (string) Str::uuid();
    $runFixture = SyntheticMlbTabularBundleFixture::create($runDirectory, $fixture['path'], $runId, $artifactId);
    config()->set('mlb_ml.bundle.staging_directory', $fixture['path'].'.staging');
    $bundles = app(MlbTabularModelBundle::class);
    $context = $provideBundle ? $bundles->prepareValidated($runDirectory, $fixture['path']) : null;
    $artifactPath = $context?->bundle['path'] ?? $runDirectory.'/manifest.json';
    $run = new ModelRun(['id' => $runId, 'sport' => 'mlb', 'run_type' => 'training',
        'model_version' => 'mlb-tabular-v1', 'feature_version' => 'mlb-pregame-ml-v1', 'blend_version' => 'mlb-tabular-v1',
        'config_hash' => $runFixture['config_hash'], 'status' => 'running', 'started_at' => now(),
        'metadata' => ['python_model_run_id' => $runId]]);
    $storage = new class extends MlArtifactStorage
    {
        public array $levels = [];

        public function storeArtifact(ModelRun $trainingRun, string $artifactId, string $sourcePath, ?string $contentType = null, ?int $expectedBytes = null, ?string $expectedSha256 = null): MlStoredObject
        {
            $this->levels[] = DB::transactionLevel();

            return parent::storeArtifact($trainingRun, $artifactId, $sourcePath, $contentType, $expectedBytes, $expectedSha256);
        }

        public function storeDataset(ModelRun $trainingRun, string $artifactId, string $sourcePath, ?string $contentType = null, ?int $expectedBytes = null, ?string $expectedSha256 = null): MlStoredObject
        {
            $this->levels[] = DB::transactionLevel();

            return parent::storeDataset($trainingRun, $artifactId, $sourcePath, $contentType, $expectedBytes, $expectedSha256);
        }
    };
    $registry = new ModelArtifactRegistry($storage);
    try {
        $prepared = $registry->prepareMlbRegistration($artifactId, $run, 'multi_market', 'mlb_tabular_bundle',
            'mlb-tabular-v1', 'mlb-pregame-ml-v1', $fixture['proof']['dataset_sha256'], $artifactPath, [], $fixture['path'], $runDirectory.'/evaluation.json', $cloneBundle && $context !== null ? clone $context : $context);
    } finally {
        @unlink($fixture['path']);
        @unlink($fixture['path'].'.manifest.json');
        if ($context !== null) {
            $bundles->releaseValidated($context);
        }
        File::deleteDirectory($runDirectory);
        File::deleteDirectory($fixture['path'].'.staging');
    }

    return compact('fixture', 'run', 'registry', 'prepared', 'storage');
}

it('stages uploads outside SQL and persists owned new artifact IDs only inside their matching source fence', function () {
    ['fixture' => $fixture, 'run' => $run, 'registry' => $registry, 'prepared' => $prepared, 'storage' => $storage] = mlbStagedRegistryFixture();
    expect($storage->levels)->toBe([0, 0])->and($run->exists)->toBeFalse()
        ->and(ModelArtifact::find($prepared->artifactId))->toBeNull();
    $artifact = app(MlbTabularSourceFence::class)->run($fixture['proof'], function () use ($run, $registry, $prepared) {
        return app(MlbArtifactCohortSerializer::class)->run(DB::connection(), function () use ($registry, $prepared, $run) {
            $registry->insertPreparedMlbRun($prepared, $run);

            return $registry->persistPreparedMlb($prepared, $run);
        });
    });
    expect($artifact->id)->toBe($prepared->artifactId)->and($artifact->status)->toBe('challenger')
        ->and($artifact->training_run_id)->toBe($run->id)
        ->and($run->fresh()->metadata['mlb_tabular_admission']['dataset_sha256'])->toBe($fixture['proof']['dataset_sha256'])
        ->and($storage->levels)->toBe([0, 0]);
});

it('refuses a cloned or unfenced prepared descriptor without registering objects', function () {
    ['fixture' => $fixture, 'run' => $run, 'registry' => $registry, 'prepared' => $prepared] = mlbStagedRegistryFixture();
    expect(fn () => $registry->persistPreparedMlb($prepared, $run))->toThrow(RuntimeException::class, 'source-first fence');
    expect(fn () => app(MlbTabularSourceFence::class)->run($fixture['proof'], function () use ($run, $registry, $prepared) {
        return app(MlbArtifactCohortSerializer::class)->run(DB::connection(), function () use ($registry, $prepared, $run) {
            $registry->insertPreparedMlbRun($prepared, $run);

            return $registry->persistPreparedMlb(clone $prepared, $run);
        });
    }))->toThrow(RuntimeException::class, 'owner changed');
    expect(ModelRun::find($run->id))->toBeNull()->and(ModelArtifact::find($prepared->artifactId))->toBeNull();
});

it('retains staged private objects while refusing a changed source before registry mutation', function () {
    ['fixture' => $fixture, 'run' => $run, 'registry' => $registry, 'prepared' => $prepared] = mlbStagedRegistryFixture();
    DB::table('mlb_games')->where('id', $fixture['game']->id)->update(['game_time' => '20:30:00']);
    expect(fn () => app(MlbTabularSourceFence::class)->run($fixture['proof'], function () use ($run, $registry, $prepared) {
        return app(MlbArtifactCohortSerializer::class)->run(DB::connection(), function () use ($registry, $prepared, $run) {
            $registry->insertPreparedMlbRun($prepared, $run);

            return $registry->persistPreparedMlb($prepared, $run);
        });
    }))->toThrow(RuntimeException::class);
    expect(ModelRun::find($run->id))->toBeNull()->and(ModelArtifact::find($prepared->artifactId))->toBeNull();
    Storage::disk($prepared->artifactAttributes['artifact_disk'])->assertExists($prepared->artifactAttributes['artifact_object_key']);
    Storage::disk($prepared->artifactAttributes['dataset_disk'])->assertExists($prepared->artifactAttributes['dataset_object_key']);
});

it('refuses generic MLB artifact registration without owned exact dataset evidence', function () {
    Storage::fake('mlb-legacy-stage-test');
    Storage::fake('mlb-legacy-cache-test');
    config()->set('ml.storage.disk', 'mlb-legacy-stage-test');
    config()->set('ml.storage.cache_disk', 'mlb-legacy-cache-test');
    $path = storage_path('framework/testing/mlb-legacy-registration-'.Str::uuid());
    file_put_contents($path, 'synthetic unproved file');
    $run = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'mlb', 'run_type' => 'training',
        'model_version' => 'mlb-tabular-test', 'feature_version' => 'test-v1', 'blend_version' => 'test-v1',
        'config_hash' => hash('sha256', 'test-config'), 'status' => 'running', 'started_at' => now()]);
    try {
        expect(fn () => app(ModelArtifactRegistry::class)->register((string) Str::uuid(), $run,
            'multi_market', 'mlb_tabular_bundle', 'mlb-tabular-test', 'test-v1', hash_file('sha256', $path), $path, [], $path))
            ->toThrow(RuntimeException::class);
        expect(ModelArtifact::count())->toBe(0)->and($run->fresh()->status)->toBe('running');
    } finally {
        @unlink($path);
    }
});

it('refuses direct MLB run registration without its exact dataset evidence before bundle preparation', function () {
    $bundles = Mockery::mock(MlbTabularModelBundle::class);
    $bundles->shouldReceive('create')->andThrow(new RuntimeException('Bundle preparation ran without exact dataset admission.'));
    $registrar = new MlbTabularModelRunRegistrar($bundles, app(ModelArtifactRegistry::class));
    expect(fn () => $registrar->register('synthetic-unread-run-directory'))
        ->toThrow(RuntimeException::class, 'exact training dataset');
    expect(ModelRun::count())->toBe(0)->and(ModelArtifact::count())->toBe(0);
});

it('refuses staging raw tabular bytes without an opaque inspected bundle context', function () {
    expect(fn () => mlbStagedRegistryFixture(false))->toThrow(RuntimeException::class, 'inspected bundle');
});

it('refuses a copied inspected-bundle carrier that was not issued under that object identity', function () {
    expect(fn () => mlbStagedRegistryFixture(true, true))->toThrow(RuntimeException::class, 'original opaque');
});

it('refuses a current final-score correction that has not yet refreshed legacy evaluation targets', function () {
    $fixture = ObservedMlbDatasetFixture::create();
    $fixture['game']->update(['home_score' => 6]);
    $called = false;
    expect(fn () => app(MlbTabularSourceFence::class)->run($fixture['proof'], function () use (&$called) {
        $called = true;
    }))
        ->toThrow(RuntimeException::class)->and($called)->toBeFalse();
});

it('declines stale read-replica target evidence while keeping the final write-current fence', function () {
    $fixture = ObservedMlbDatasetFixture::create(true);
    $connection = DB::connection();
    $write = $connection->getPdo();
    $originalRead = $connection->getReadPdo();
    $replica = new PDO('sqlite::memory:');
    foreach (['mlb_games', 'prediction_feature_snapshots', 'prediction_evaluations', 'model_runs', 'game_odds_snapshots'] as $table) {
        $statement = $write->prepare("select sql from sqlite_master where type = 'table' and name = ?");
        $statement->execute([$table]);
        $replica->exec($statement->fetchColumn());
        // Only this bounded, newly created synthetic fixture is copied; never a production dataset reader.
        foreach ($write->query('select * from "'.$table.'" limit 100')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns = implode(',', array_map(fn ($column) => '"'.$column.'"', array_keys($row)));
            $insert = $replica->prepare('insert into "'.$table.'" ('.$columns.') values ('.implode(',', array_fill(0, count($row), '?')).')');
            $insert->execute(array_values($row));
        }
    }
    $fixture['game']->update(['home_score' => 6]);
    $connection->forgetRecordModificationState();
    $connection->useWriteConnectionWhenReading(false);
    $connection->setReadPdo($replica);
    try {
        expect($connection->getReadPdo())->toBe($replica)
            ->and((int) $replica->query('select home_score from mlb_games')->fetchColumn())->toBe(5)
            ->and((int) $write->query('select home_score from mlb_games')->fetchColumn())->toBe(6);
        $called = false;
        expect(fn () => app(MlbTabularSourceFence::class)->run($fixture['proof'], function () use (&$called) {
            $called = true;
        }))
            ->toThrow(RuntimeException::class)->and($called)->toBeFalse();
        expect(fn () => app(MlbTabularDatasetAdmission::class)->inspect($fixture['path']))
            ->toThrow(RuntimeException::class);
        expect($connection->getReadPdo())->toBe($replica)->and($connection->getPdo())->toBe($write);
    } finally {
        $connection->setReadPdo($originalRead);
        @unlink($fixture['path']);
        @unlink($fixture['path'].'.manifest.json');
    }
});

it('refuses primary handle replacement inside the actual source commit boundary', function () {
    $fixture = ObservedMlbDatasetFixture::create();
    $connection = DB::connection();
    $original = $connection->getPdo();
    $replacement = new PDO('sqlite::memory:');
    try {
        expect(fn () => app(MlbTabularSourceFence::class)->run($fixture['proof'], function () use ($connection, $replacement) {
            // An independent synthetic primary transaction makes an otherwise silent replacement observable.
            $replacement->beginTransaction();
            $connection->setPdo($replacement);

            return 'unqualified replacement commit';
        }))->toThrow(EloAdmissionRefused::class);
    } finally {
        if ($original->inTransaction()) {
            $original->rollBack();
        }
        if ($replacement->inTransaction()) {
            $replacement->rollBack();
        }
        $connection->setPdo($original);
    }
});

it('refuses application clock policy drift inside the actual source commit boundary', function () {
    $fixture = ObservedMlbDatasetFixture::create();
    $timezone = config('app.timezone');
    try {
        expect(fn () => app(MlbTabularSourceFence::class)->run($fixture['proof'], function () use ($timezone) {
            config()->set('app.timezone', $timezone === 'UTC' ? 'America/Chicago' : 'UTC');

            return 'unqualified clock policy commit';
        }))->toThrow(EloAdmissionRefused::class);
    } finally {
        config()->set('app.timezone', $timezone);
    }
});

it('keeps the explicitly unused replica resolver outside primary-only source admission', function () {
    $fixture = ObservedMlbDatasetFixture::create();
    $connection = DB::connection();
    $originalRead = $connection->getReadPdo();
    $readCalls = 0;
    $connection->setReadPdo(function () use (&$readCalls) {
        $readCalls++;
        throw new LogicException('An unused replica must not be resolved by primary source admission.');
    });
    try {
        expect(app(MlbTabularSourceFence::class)->run($fixture['proof'], fn () => 'primary source unchanged'))->toBe('primary source unchanged')
            ->and($readCalls)->toBe(0);
    } finally {
        $connection->setReadPdo($originalRead);
    }
});
