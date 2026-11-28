<?php

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\ML\MlArtifactStorage;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\ML\TrainingAttemptService;
use App\Services\MLB\MlbExistingArtifactMutation;
use App\Services\MLB\MlbExistingArtifactStorage;
use App\Services\MLB\MlbTabularArtifactAdmission;
use App\Services\MLB\MlbTabularFiles;
use App\Services\MLB\MlbTrainingCompletion;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Support\EloClockFixture;
use Tests\Support\ObservedMlbDatasetFixture;
use Tests\Support\SyntheticMlbTabularBundleFixture;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    if (DB::connection()->getDriverName() !== 'mysql') {
        throw new RuntimeException('Actual MLB completion contention requires disposable MySQL.');
    }
    $this->nativeDefault = DB::getDefaultConnection();
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);
    Http::preventStrayRequests();
    EloClockFixture::prepare();
    config(['database.connections.completion_peer' => config('database.connections.'.$this->nativeDefault)]);
    DB::setDefaultConnection('completion_peer');
    EloClockFixture::prepare();
    DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::setDefaultConnection($this->nativeDefault);
    expect(DB::connection()->getPdo())->not->toBe(DB::connection('completion_peer')->getPdo());
    Storage::fake('mlb-native-completion');
    Storage::fake('mcc');
    $this->nativeRoot = storage_path('framework/testing/mlb-native-completion-'.Str::uuid());
    config(['ml.storage.disk' => 'mlb-native-completion', 'ml.storage.cache_disk' => 'mcc',
        'mlb_ml.bundle.staging_directory' => $this->nativeRoot.'/staging']);
    $this->nativeSources = [];
});

afterEach(function () {
    DB::setDefaultConnection($this->nativeDefault);
    foreach ([$this->nativeDefault, 'completion_peer'] as $name) {
        $connection = DB::connection($name);
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
    DB::purge('completion_peer');
    foreach ($this->nativeSources as $source) {
        @unlink($source['path']);
        @unlink($source['path'].'.manifest.json');
    }
    File::deleteDirectory($this->nativeRoot);
});

function archiveNativeSource($test, int $season, string $label): array
{
    // Independent capture jobs must not share the recorder's cached batch run and hide cohort contention.
    app()->forgetInstance(ModelRunRecorder::class);
    $test->travelTo(Carbon::parse($season.'-06-27T06:00:00Z'));
    $source = ObservedMlbDatasetFixture::create(retainDataset: true, gameDate: $season.'-06-28', season: $season,
        exportSeasons: [$season], finalizedAt: Carbon::parse($season.'-06-30T06:00:00Z'));
    $test->nativeSources[] = $source;
    $test->travelTo(Carbon::parse($season.'-06-30T06:00:00Z'));

    return $source;
}

function archiveNativeCompletion($test, array $source, string $label): array
{
    expect(array_column($source['proof']['references'], 'game_id'))->toBe([$source['game']->id]);
    $specification = ['model_type' => 'mlb_tabular_bundle', 'model_version' => 'mlb-tabular-v1', 'feature_version' => 'mlb-pregame-ml-v1'];
    $cycle = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'mlb', 'run_type' => 'weekly_training_cycle',
        'model_version' => $specification['model_version'], 'feature_version' => $specification['feature_version'],
        'blend_version' => 'automated-weekly-v1', 'config_hash' => hash('sha256', 'synthetic-'.$label),
        'status' => 'running', 'started_at' => now(), 'metadata' => ['dataset_path' => $source['path'],
            'dataset_hash' => $source['proof']['dataset_sha256'], 'training_fingerprint' => hash('sha256', $label),
            'training_specification' => $specification]]);
    $ids = match ($label) {
        'first' => ['30000000-0000-4000-8000-000000000001', '10000000-0000-4000-8000-000000000101', '10000000-0000-4000-8000-000000000201'],
        'second' => ['b0000000-0000-4000-8000-000000000002', 'f0000000-0000-4000-8000-000000000102', 'f0000000-0000-4000-8000-000000000202'],
        default => null,
    };
    if ($ids !== null) {
        Str::createUuidsUsingSequence([Uuid::fromString($ids[0]), Uuid::fromString($ids[1])]);
    }
    try {
        $attempt = app(TrainingAttemptService::class)->claim($cycle->id, 300);
    } finally {
        if ($ids !== null) {
            Str::createUuidsNormally();
        }
    }
    $fixture = SyntheticMlbTabularBundleFixture::create($test->nativeRoot.'/'.$label, $source['path'], $attempt->python_run_id, $ids[2] ?? null);
    // Pure synthetic gate math, never model fitting or an empirical quality claim.
    $reportPath = $fixture['run_directory'].'/evaluation.json';
    $report = json_decode(File::get($reportPath), true, flags: JSON_THROW_ON_ERROR);
    $report['delta_convention'] = 'baseline_minus_challenger';
    $report['rolling_weekly']['windows'] = array_fill(0, 3, ['baseline_brier' => 0.25, 'challenger_brier' => 0.20,
        'baseline_log_loss' => 0.70, 'challenger_log_loss' => 0.60, 'brier_delta' => 0.05, 'log_loss_delta' => 0.10, 'spread_mae_delta' => 0.5, 'total_mae_delta' => 0.5]);
    File::put($reportPath, json_encode($report, JSON_THROW_ON_ERROR));
    $manifestPath = $fixture['run_directory'].'/manifest.json';
    $manifest = json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    $manifest['artifacts']['evaluation.json'] = ['sha256' => hash_file('sha256', $reportPath), 'bytes' => filesize($reportPath)];
    File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
    $service = app(MlbTrainingCompletion::class);
    $prepared = $service->prepare($cycle, $attempt, $fixture['run_directory'], $source['path'], hash('sha256', 'output-'.$label));
    expect($prepared->decision['eligible'])->toBeTrue()->and($prepared->decision['eligible_markets'])->toHaveCount(3);
    foreach (['artifact_path', 'dataset_path', 'evaluation_report_path'] as $field) {
        // The fixture cache root must fit the existing path columns; never rely on SQL truncation.
        expect(strlen($prepared->registration->registration->artifactAttributes[$field]))->toBeLessThanOrEqual(255);
    }

    return compact('source', 'cycle', 'attempt', 'fixture', 'service', 'prepared');
}

function archiveNativeArtifact($test, int $season, string $label): ModelArtifact
{
    $prepared = archiveNativeCompletion($test, archiveNativeSource($test, $season, $label), $label);
    $receipt = $prepared['service']->commit($prepared['prepared']);

    return ModelArtifact::findOrFail($receipt['artifact_id']);
}

function archiveNativeRows(): array
{
    return [DB::table('model_artifacts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        DB::table('model_runs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
}

it('verifies qualified canonical archives and reads original training reports without uploads or SQL writes', function () {
    $source = archiveNativeSource($this, 2026, 'qualified');
    $owned = archiveNativeCompletion($this, $source, 'qualified');
    $receipt = $owned['service']->commit($owned['prepared']);
    $artifact = ModelArtifact::findOrFail($receipt['artifact_id']);
    $runs = archiveNativeRows()[1];
    $beforeArchive = archiveNativeRows();
    Storage::fake('mlb-native-alt-cache');
    config(['ml.storage.cache_disk' => 'mlb-native-alt-cache']);
    $storage = Mockery::mock(MlArtifactStorage::class)->makePartial();
    $storage->shouldNotReceive('storeArtifact');
    $storage->shouldNotReceive('storeReport');
    $storage->shouldNotReceive('storeDataset');
    app()->instance(MlArtifactStorage::class, $storage);
    $registry = app(ModelArtifactRegistry::class);
    $archived = $registry->archiveExisting($artifact);
    expect($archived->artifact_hash)->toBe($artifact->artifact_hash)
        ->and($archived->artifact_size)->toBe($artifact->artifact_size)
        ->and($archived->artifact_path)->not->toBe($artifact->artifact_path)
        ->and(hash_file('sha256', $archived->artifact_path))->toBe($artifact->artifact_hash)
        ->and(archiveNativeRows())->toBe($beforeArchive)
        ->and(archiveNativeRows()[1])->toBe($runs);
    $beforeReport = archiveNativeRows();
    $read = $registry->attachEvaluationReport($artifact->fresh(), $owned['fixture']['run_directory'].'/evaluation.json');
    expect($read)->toBeInstanceOf(ModelArtifact::class)
        ->and(hash_file('sha256', $read->evaluation_report_path))->toBe($artifact->evaluation_report_hash)
        ->and(hash_file('sha256', $registry->materializeEvaluationReport($artifact->fresh())))->toBe($artifact->evaluation_report_hash)
        ->and(archiveNativeRows())->toBe($beforeReport);
});

it('relocates a qualified direct-SQL legacy pointer shape while preserving its original run and content', function () {
    $artifact = archiveNativeArtifact($this, 2026, 'legacy-shape');
    $original = $artifact->getAttributes();
    // Explicit fixture shape only: this does not mint or repair historical provenance.
    DB::table('model_artifacts')->where('id', $artifact->id)->update(['artifact_disk' => null, 'artifact_object_key' => null, 'artifact_uri' => null]);
    $artifact->refresh();
    $runs = archiveNativeRows()[1];
    $artifact = app(ModelArtifactRegistry::class)->archiveExisting($artifact);
    expect($artifact->artifact_disk)->toBe('mlb-native-completion')
        ->and($artifact->artifact_object_key)->not->toBeNull()
        ->and($artifact->artifact_hash)->toBe($original['artifact_hash'])
        ->and($artifact->artifact_size)->toBe((int) $original['artifact_size'])
        ->and($artifact->status)->toBe($original['status'])
        ->and(archiveNativeRows()[1])->toBe($runs)
        ->and(app(MlbTabularArtifactAdmission::class)->admit($artifact)['artifact']->id)->toBe($artifact->id);
});

it('refuses archive prepared target owner and source changes without pointer writes', function () {
    $artifact = archiveNativeArtifact($this, 2026, 'drift');
    $storage = app(MlbExistingArtifactStorage::class);
    $prepared = $storage->prepare($artifact);
    $other = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'mlb', 'run_type' => 'explicit_unqualified',
        'model_version' => 'synthetic', 'feature_version' => 'synthetic', 'blend_version' => 'none',
        'config_hash' => hash('sha256', 'synthetic'), 'status' => 'completed', 'started_at' => now()]);
    DB::table('model_artifacts')->where('id', $artifact->id)->update(['training_run_id' => $other->id]);
    $before = archiveNativeRows();
    expect(fn () => $storage->commit($prepared))->toThrow(RuntimeException::class)
        ->and(archiveNativeRows())->toBe($before);
    DB::table('model_artifacts')->where('id', $artifact->id)->update(['training_run_id' => $artifact->training_run_id]);
    $prepared = $storage->prepare($artifact->fresh());
    DB::table('mlb_games')->where('id', $this->nativeSources[0]['game']->id)->update(['status' => 'STATUS_POSTPONED']);
    $before = archiveNativeRows();
    expect(fn () => $storage->commit($prepared))->toThrow(RuntimeException::class)
        ->and(archiveNativeRows())->toBe($before);
});

it('rolls back cancelled native archive pointers while retaining the distinct immutable staged object', function () {
    $artifact = archiveNativeArtifact($this, 2026, 'cancelled');
    $legacy = $this->nativeRoot.'/relocated.zip';
    MlbTabularFiles::copy($artifact->artifact_path, $legacy, $artifact->artifact_size, $artifact->artifact_hash);
    DB::table('model_artifacts')->where('id', $artifact->id)->update(['artifact_path' => $legacy, 'artifact_disk' => null, 'artifact_object_key' => null, 'artifact_uri' => null]);
    $artifact->refresh();
    $storage = app(MlbExistingArtifactStorage::class);
    $prepared = $storage->prepare($artifact);
    $object = $prepared->payload['artifact_attributes'];
    expect(Storage::disk($object['artifact_disk'])->exists($object['artifact_object_key']))->toBeTrue();
    $before = archiveNativeRows();
    $dispatcher = ModelArtifact::getEventDispatcher();
    ModelArtifact::setEventDispatcher(clone $dispatcher);
    ModelArtifact::saving(fn () => false);
    try {
        expect(fn () => $storage->commit($prepared))->toThrow(RuntimeException::class, 'cancelled');
    } finally {
        ModelArtifact::setEventDispatcher($dispatcher);
    }
    expect(archiveNativeRows())->toBe($before)
        ->and(Storage::disk($object['artifact_disk'])->exists($object['artifact_object_key']))->toBeTrue();
});

it('refuses archive prepared connection replacement and report caller mutation before writes', function () {
    $artifact = archiveNativeArtifact($this, 2026, 'connection');
    $storage = app(MlbExistingArtifactStorage::class);
    $prepared = $storage->prepare($artifact);
    $before = archiveNativeRows();
    DB::setDefaultConnection('completion_peer');
    try {
        expect(fn () => $storage->commit($prepared))->toThrow(RuntimeException::class);
    } finally {
        DB::setDefaultConnection($this->nativeDefault);
    }
    $connection = DB::connection();
    $original = $connection->getPdo();
    $connection->setPdo(DB::connection('completion_peer')->getPdo());
    try {
        expect(fn () => $storage->commit($prepared))->toThrow(RuntimeException::class);
    } finally {
        $connection->setPdo($original);
    }
    $artifact->evaluation_report_hash = null;
    expect(fn () => app(ModelArtifactRegistry::class)->attachEvaluationReport($artifact, 'never-opened-dirty-caller.json'))->toThrow(RuntimeException::class)
        ->and(archiveNativeRows())->toBe($before);
});

function archiveNativeBlocked(callable $attempt, array $sqlParts): void
{
    try {
        $attempt();
        test()->fail('Expected owned archive lock contention.');
    } catch (Throwable $failure) {
        $error = $failure;
        for ($depth = 0; ! $error instanceof QueryException && $depth < 12; $depth++) {
            $error = $error->getPrevious();
            if ($error === null) {
                throw $failure;
            }
        }
        expect($error)->toBeInstanceOf(QueryException::class);
        expect((int) ($error->errorInfo[1] ?? 0))->toBe(1205)
            ->and($error->getConnectionName())->toBe('completion_peer');
        foreach ($sqlParts as $part) {
            expect(strtolower($error->getSql()))->toContain($part);
        }
    }
}

it('holds current archive source target and cohort against a disjoint qualified selector then admits retries', function () {
    $sourceA = archiveNativeSource($this, 2025, 'source-first');
    $first = archiveNativeCompletion($this, $sourceA, 'first');
    $receiptA = $first['service']->commit($first['prepared']);
    $sourceB = archiveNativeSource($this, 2026, 'source-second');
    $second = archiveNativeCompletion($this, $sourceB, 'second');
    $receiptB = $second['service']->commit($second['prepared']);
    foreach (['game_id', 'prediction_id', 'snapshot_id', 'evaluation_id', 'model_run_id'] as $field) {
        expect(array_intersect(array_column($sourceA['proof']['references'], $field), array_column($sourceB['proof']['references'], $field)))->toBe([]);
    }
    $artifactA = ModelArtifact::findOrFail($receiptA['artifact_id']);
    // Direct-SQL fixture shape only; both original content digest and protected source proof are unchanged.
    DB::table('model_artifacts')->where('id', $artifactA->id)->update(['artifact_disk' => null, 'artifact_object_key' => null, 'artifact_uri' => null]);
    DB::setDefaultConnection('completion_peer');
    try {
        $selector = app(MlbExistingArtifactMutation::class);
        $selection = $selector->prepareSelection(ModelArtifact::findOrFail($receiptB['artifact_id']), 'activate', ['metadata' => []]);
    } finally {
        DB::setDefaultConnection($this->nativeDefault);
    }
    $storage = app(MlbExistingArtifactStorage::class);
    $prepared = $storage->prepare($artifactA->fresh());
    $runs = archiveNativeRows()[1];
    $blocked = 0;
    $dispatcher = ModelArtifact::getEventDispatcher();
    ModelArtifact::setEventDispatcher(clone $dispatcher);
    ModelArtifact::saving(function (ModelArtifact $row) use ($artifactA, $sourceA, $selector, $selection, &$blocked) {
        if ($row->id !== $artifactA->id) {
            return;
        }
        $peer = DB::connection('completion_peer');
        $peer->beginTransaction();
        try {
            archiveNativeBlocked(fn () => $peer->table('mlb_games')->useWritePdo()->where('id', $sourceA['game']->id)->select('id')->lockForUpdate()->get(), ['from `mlb_games`', 'for update']);
            $blocked++;
        } finally {
            $peer->rollBack();
        }
        $peer->beginTransaction();
        try {
            archiveNativeBlocked(fn () => $peer->table('model_artifacts')->where('id', $artifactA->id)->update(['artifact_uri' => 'synthetic-rolled-back-probe']), ['update `model_artifacts`', '`artifact_uri`']);
            $blocked++;
        } finally {
            $peer->rollBack();
        }
        DB::setDefaultConnection('completion_peer');
        try {
            archiveNativeBlocked(fn () => $selector->commit($selection), ['force index (`model_artifacts_sport_index`)', 'where sport =', 'for update']);
            $blocked++;
        } finally {
            DB::setDefaultConnection($this->nativeDefault);
        }
        expect($peer->transactionLevel())->toBe(0);
    });
    try {
        $archived = $storage->commit($prepared);
    } finally {
        ModelArtifact::setEventDispatcher($dispatcher);
    }
    expect($blocked)->toBe(3)->and(archiveNativeRows()[1])->toBe($runs)
        ->and($archived->artifact_hash)->toBe($artifactA->artifact_hash);
    $peer = DB::connection('completion_peer');
    $peer->beginTransaction();
    try {
        expect($peer->table('mlb_games')->where('id', $sourceA['game']->id)->select('id')->lockForUpdate()->get())->toHaveCount(1);
        expect($peer->table('model_artifacts')->where('id', $artifactA->id)->update(['artifact_uri' => 'synthetic-rolled-back-probe']))->toBe(1);
    } finally {
        $peer->rollBack();
    }
    DB::setDefaultConnection('completion_peer');
    try {
        $selector->commit($selection);
    } finally {
        DB::setDefaultConnection($this->nativeDefault);
    }
    expect(data_get(ModelArtifact::findOrFail($receiptB['artifact_id'])->metrics, 'shadow_selection.active'))->toBeTrue()
        ->and(ModelArtifact::findOrFail($artifactA->id)->artifact_uri)->toBe($archived->artifact_uri)
        ->and(archiveNativeRows()[1])->toBe($runs)
        ->and(app(MlbTabularArtifactAdmission::class)->admit($archived)['artifact']->id)->toBe($artifactA->id);
});

it('persists qualified native cache hints only and retains readonly partial header compatibility', function () {
    $artifact = archiveNativeArtifact($this, 2026, 'cp');
    $before = archiveNativeRows();
    Storage::fake('native-cache-next');
    config(['ml.storage.cache_disk' => 'native-cache-next']);
    $registry = app(ModelArtifactRegistry::class);
    $partial = app(MlbTabularArtifactAdmission::class)->admit($artifact)['artifact'];
    expect(array_key_exists('artifact_uri', $partial->getRawOriginal()))->toBeFalse();
    $readonly = $registry->materializeArtifact($partial, persistCachePath: false, maximumBytes: 268435456);
    expect(hash_file('sha256', $readonly))->toBe($artifact->artifact_hash)
        ->and(archiveNativeRows())->toBe($before);
    $path = $registry->materializeArtifact($artifact);
    $expected = $before;
    foreach ($expected[0] as &$row) {
        if ($row['id'] === $artifact->id) {
            $row['artifact_path'] = $path;
            $row['updated_at'] = DB::table('model_artifacts')->where('id', $artifact->id)->value('updated_at');
        }
    }
    unset($row);
    expect($path)->not->toBe($before[0][0]['artifact_path'])
        ->and(hash_file('sha256', $path))->toBe($artifact->artifact_hash)
        ->and(archiveNativeRows())->toBe($expected)
        ->and(archiveNativeRows()[1])->toBe($before[1])
        ->and(app(MlbTabularArtifactAdmission::class)->admit($artifact->fresh())['artifact']->id)->toBe($artifact->id);
});

it('rolls back qualified native cache hint persistence when saving is cancelled', function () {
    $artifact = archiveNativeArtifact($this, 2026, 'cc');
    Storage::fake('native-cache-next');
    config(['ml.storage.cache_disk' => 'native-cache-next']);
    $before = archiveNativeRows();
    $dispatcher = ModelArtifact::getEventDispatcher();
    ModelArtifact::setEventDispatcher(clone $dispatcher);
    $attempts = 0;
    ModelArtifact::saving(function () use (&$attempts) {
        $attempts++;

        return false;
    });
    try {
        expect(fn () => app(ModelArtifactRegistry::class)->materializeArtifact($artifact))->toThrow(RuntimeException::class, 'cancelled');
    } finally {
        ModelArtifact::setEventDispatcher($dispatcher);
    }
    expect($attempts)->toBe(1)->and(archiveNativeRows())->toBe($before);
});

it('refuses qualified native cache owner and source drift after actual bounded IO', function () {
    $artifact = archiveNativeArtifact($this, 2026, 'cd');
    Storage::fake('native-cache-next');
    config(['ml.storage.cache_disk' => 'native-cache-next']);
    $real = app(MlArtifactStorage::class);
    $originalOwner = $artifact->training_run_id;
    $other = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'mlb', 'run_type' => 'explicit_unqualified',
        'model_version' => 'synthetic', 'feature_version' => 'synthetic', 'blend_version' => 'none',
        'config_hash' => hash('sha256', 'cache-other'), 'status' => 'completed', 'started_at' => now()]);
    foreach (['owner', 'source'] as $kind) {
        $storage = Mockery::mock(MlArtifactStorage::class)->makePartial();
        $afterIndependent = null;
        $storage->shouldReceive('materialize')->once()->andReturnUsing(function (...$args) use ($real, $artifact, $other, $kind, &$afterIndependent) {
            $path = $real->materialize(...$args);
            if ($kind === 'owner') {
                DB::table('model_artifacts')->where('id', $artifact->id)->update(['training_run_id' => $other->id]);
            } else {
                DB::table('mlb_games')->where('id', $this->nativeSources[0]['game']->id)->update(['status' => 'STATUS_POSTPONED']);
            }
            $afterIndependent = archiveNativeRows();

            return $path;
        });
        app()->instance(MlArtifactStorage::class, $storage);
        expect(fn () => app(ModelArtifactRegistry::class)->materializeArtifact($artifact))->toThrow(RuntimeException::class)
            ->and($afterIndependent)->not->toBeNull()->and(archiveNativeRows())->toBe($afterIndependent);
        DB::table('model_artifacts')->where('id', $artifact->id)->update(['training_run_id' => $originalOwner]);
        $artifact->refresh();
    }
});

it('refuses a qualified artifact transplanted table context at central typed admission', function () {
    $artifact = archiveNativeArtifact($this, 2026, 'ct');
    expect(app(MlbTabularArtifactAdmission::class)->admit($artifact)['artifact']->id)->toBe($artifact->id);
    DB::statement('CREATE TABLE cache_typed_alias LIKE model_artifacts');
    DB::statement('INSERT INTO cache_typed_alias SELECT * FROM model_artifacts');
    $artifact->setTable('cache_typed_alias');
    expect($artifact->isDirty())->toBeFalse()
        ->and(DB::table('cache_typed_alias')->where('id', $artifact->id)->exists())->toBeTrue();
    $before = archiveNativeRows();
    $aliasBefore = (array) DB::table('cache_typed_alias')->where('id', $artifact->id)->first();
    try {
        expect(fn () => app(MlbTabularArtifactAdmission::class)->admit($artifact))->toThrow(RuntimeException::class);
        expect(archiveNativeRows())->toBe($before)
            ->and((array) DB::table('cache_typed_alias')->where('id', $artifact->id)->first())->toBe($aliasBefore);
    } finally {
        DB::statement('DROP TABLE cache_typed_alias');
    }
});
