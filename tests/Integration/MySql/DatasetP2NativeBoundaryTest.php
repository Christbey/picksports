<?php

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\ML\MlArtifactStorage;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\ML\TrainingAttemptService;
use App\Services\MLB\MlbTabularArtifactAdmission;
use App\Services\MLB\MlbTrainingCompletion;
use App\Services\Predictions\ModelRunRecorder;
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

function datasetP2NativeSource($test, int $season, string $label): array
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

function datasetP2NativeCompletion($test, array $source, string $label): array
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

function datasetP2NativeArtifact($test, int $season, string $label): ModelArtifact
{
    $prepared = datasetP2NativeCompletion($test, datasetP2NativeSource($test, $season, $label), $label);
    $receipt = $prepared['service']->commit($prepared['prepared']);

    return ModelArtifact::findOrFail($receipt['artifact_id']);
}

function datasetP2NativeRows(): array
{
    return [DB::table('model_artifacts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        DB::table('model_runs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
}

it('reads and attaches the original qualified dataset without changing protected training evidence', function () {
    $owned = datasetP2NativeCompletion($this, datasetP2NativeSource($this, 2026, 'dp'), 'dp');
    $receipt = $owned['service']->commit($owned['prepared']);
    $artifact = ModelArtifact::findOrFail($receipt['artifact_id']);
    $before = datasetP2NativeRows();
    Storage::fake('dataset-native-next');
    config(['ml.storage.cache_disk' => 'dataset-native-next']);
    $registry = app(ModelArtifactRegistry::class);
    $read = $registry->materializeDataset($artifact, false);
    expect(hash_file('sha256', $read))->toBe($artifact->dataset_hash)->and(datasetP2NativeRows())->toBe($before);
    $attached = $registry->attachDataset($artifact->fresh(), $owned['source']['path']);
    expect($attached->id)->toBe($artifact->id)->and(hash_file('sha256', $attached->dataset_path))->toBe($artifact->dataset_hash)
        ->and(datasetP2NativeRows())->toBe($before);
    $path = $registry->materializeDataset($artifact->fresh());
    $expected = $before;
    foreach ($expected[0] as &$row) {
        if ($row['id'] === $artifact->id) {
            $row['dataset_path'] = $path;
            $row['updated_at'] = DB::table('model_artifacts')->where('id', $artifact->id)->value('updated_at');
        }
    }
    unset($row);
    expect(datasetP2NativeRows())->toBe($expected)->and(datasetP2NativeRows()[1])->toBe($before[1]);
    expect(app(MlbTabularArtifactAdmission::class)->admit($artifact->fresh())['artifact']->id)->toBe($artifact->id);
});

it('rolls back qualified dataset cache save cancellation and preserves all training proof rows', function () {
    $artifact = datasetP2NativeArtifact($this, 2026, 'dc');
    Storage::fake('dataset-native-next');
    config(['ml.storage.cache_disk' => 'dataset-native-next']);
    $before = datasetP2NativeRows();
    $dispatcher = ModelArtifact::getEventDispatcher();
    ModelArtifact::setEventDispatcher(clone $dispatcher);
    $reached = 0;
    ModelArtifact::saving(function () use (&$reached) {
        $reached++;

        return false;
    });
    try {
        expect(fn () => app(ModelArtifactRegistry::class)->materializeDataset($artifact))->toThrow(RuntimeException::class, 'cancelled');
    } finally {
        ModelArtifact::setEventDispatcher($dispatcher);
    }
    expect($reached)->toBe(1)->and(datasetP2NativeRows())->toBe($before);
});

it('refuses current owner or protected source drift after actual bounded dataset materialization', function () {
    $artifact = datasetP2NativeArtifact($this, 2026, 'dd');
    Storage::fake('dataset-native-next');
    config(['ml.storage.cache_disk' => 'dataset-native-next']);
    $real = app(MlArtifactStorage::class);
    $owner = $artifact->training_run_id;
    $other = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'mlb', 'run_type' => 'explicit_unqualified',
        'model_version' => 'synthetic', 'feature_version' => 'synthetic', 'blend_version' => 'none',
        'config_hash' => hash('sha256', 'dataset-other'), 'status' => 'completed', 'started_at' => now()]);
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
            $afterIndependent = datasetP2NativeRows();

            return $path;
        });
        app()->instance(MlArtifactStorage::class, $storage);
        expect(fn () => app(ModelArtifactRegistry::class)->materializeDataset($artifact))->toThrow(RuntimeException::class)
            ->and($afterIndependent)->not->toBeNull()->and(datasetP2NativeRows())->toBe($afterIndependent);
        DB::table('model_artifacts')->where('id', $artifact->id)->update(['training_run_id' => $owner]);
        $artifact->refresh();
    }
});

it('returns a clean full stored descriptor from qualified readonly attach for a natural readonly chain', function () {
    $owned = datasetP2NativeCompletion($this, datasetP2NativeSource($this, 2026, 'clean-chain'), 'clean-chain');
    $receipt = $owned['service']->commit($owned['prepared']);
    $artifact = ModelArtifact::findOrFail($receipt['artifact_id']);
    $rows = datasetP2NativeRows();
    Storage::fake('dataset-native-next');
    config(['ml.storage.cache_disk' => 'dataset-native-next']);
    $registry = app(ModelArtifactRegistry::class);
    $attached = $registry->attachDataset($artifact, $owned['source']['path']);
    expect($attached->isDirty())->toBeFalse()->and($attached->getRawOriginal('dataset_uri'))->toBe($artifact->getRawOriginal('dataset_uri'));
    $path = $registry->materializeDataset($attached, false);
    expect(hash_file('sha256', $path))->toBe($artifact->dataset_hash)->and(datasetP2NativeRows())->toBe($rows);
});

it('refuses an observer replacing the intended qualified dataset cache hint', function () {
    $artifact = datasetP2NativeArtifact($this, 2026, 'typed-intent');
    $rows = datasetP2NativeRows();
    Storage::fake('dataset-native-next');
    config(['ml.storage.cache_disk' => 'dataset-native-next']);
    $dispatcher = ModelArtifact::getEventDispatcher();
    ModelArtifact::setEventDispatcher(clone $dispatcher);
    $reached = false;
    ModelArtifact::saving(function ($row) use (&$reached) {
        $reached = true;
        $row->dataset_path = 'synthetic-unrequested-typed-path';
    });
    $failure = null;
    try {
        app(ModelArtifactRegistry::class)->materializeDataset($artifact);
    } catch (Throwable $error) {
        $failure = $error;
    } finally {
        ModelArtifact::setEventDispatcher($dispatcher);
    }
    expect($reached)->toBeTrue()->and(datasetP2NativeRows())->toBe($rows)->and($failure)->toBeInstanceOf(RuntimeException::class);
});
