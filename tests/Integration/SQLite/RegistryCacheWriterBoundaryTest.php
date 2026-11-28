<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Models\NFL\Game;
use App\Services\ML\MlArtifactStorage;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\MLB\MlbPeriodModelInferenceService;
use App\Services\NBA\WinProbabilityCalibrationInferenceService;
use App\Services\NFL\NflFullHistoricalShadowInferenceService;
use App\Services\NFL\NflPredictionProfileConfigurator;
use App\Support\MLB\MlbGameStart;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    Http::preventStrayRequests();
    foreach (['cache-source', 'cache-object', 'cache-old', 'cache-new'] as $disk) {
        Storage::fake($disk);
    }
    config(['ml.storage.disk' => 'cache-object', 'ml.storage.cache_disk' => 'cache-old']);
});

afterEach(function () {
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

function cacheWriterRun(string $sport = 'nfl'): ModelRun
{
    return ModelRun::create(['id' => (string) Str::uuid(), 'sport' => $sport, 'run_type' => 'training',
        'model_version' => 'synthetic-cache-v1', 'feature_version' => 'synthetic', 'blend_version' => 'none',
        'config_hash' => hash('sha256', 'cache'), 'status' => 'completed', 'started_at' => now(), 'metadata' => ['sentinel' => 'keep']]);
}

function cacheWriterArtifact(string $sport = 'nfl'): ModelArtifact
{
    $source = Storage::disk('cache-source');
    $source->put('model.json', '{"alpha":1,"beta":0}');
    $artifact = app(ModelArtifactRegistry::class)->register((string) Str::uuid(), cacheWriterRun($sport), 'win_probability',
        $sport === 'nba' ? 'platt_calibration' : 'nfl_full_historical_profile', 'synthetic-cache-v1', 'synthetic', hash('sha256', 'dataset'), $source->path('model.json'), []);
    config(['ml.storage.cache_disk' => 'cache-new']);

    return $artifact->refresh();
}

function cacheWriterRows(): array
{
    return [DB::table('model_artifacts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        DB::table('model_runs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
}

function cacheWriterAttempt(callable $work): ?Throwable
{
    try {
        $work();
    } catch (Throwable $failure) {
        return $failure;
    }

    return null;
}

it('refuses stale owner artifact cache relocation before changing any row', function () {
    $artifact = cacheWriterArtifact();
    $replacement = cacheWriterRun();
    DB::table('model_artifacts')->where('id', $artifact->id)->update(['training_run_id' => $replacement->id]);
    $before = cacheWriterRows();
    $failure = cacheWriterAttempt(fn () => app(ModelArtifactRegistry::class)->materializeArtifact($artifact));
    expect(cacheWriterRows())->toBe($before)->and($failure)->toBeInstanceOf(RuntimeException::class);
});

it('refuses cache relocation when current ownership changes during actual materialization', function () {
    $artifact = cacheWriterArtifact();
    $replacement = cacheWriterRun();
    $real = app(MlArtifactStorage::class);
    $storage = Mockery::mock(MlArtifactStorage::class)->makePartial();
    $afterIndependent = null;
    $storage->shouldReceive('materialize')->once()->andReturnUsing(function (...$args) use ($real, $artifact, $replacement, &$afterIndependent) {
        $path = $real->materialize(...$args);
        DB::table('model_artifacts')->where('id', $artifact->id)->update(['training_run_id' => $replacement->id]);
        $afterIndependent = cacheWriterRows();

        return $path;
    });
    $failure = cacheWriterAttempt(fn () => (new ModelArtifactRegistry($storage))->materializeArtifact($artifact));
    expect($afterIndependent)->not->toBeNull()->and(cacheWriterRows())->toBe($afterIndependent)
        ->and($failure)->toBeInstanceOf(RuntimeException::class);
});

it('refuses cancelled requested cache persistence rather than silently returning success', function () {
    $artifact = cacheWriterArtifact();
    $before = cacheWriterRows();
    $dispatcher = ModelArtifact::getEventDispatcher();
    ModelArtifact::setEventDispatcher(clone $dispatcher);
    $attempts = 0;
    ModelArtifact::saving(function () use (&$attempts) {
        $attempts++;

        return false;
    });
    try {
        $failure = cacheWriterAttempt(fn () => app(ModelArtifactRegistry::class)->materializeArtifact($artifact));
    } finally {
        ModelArtifact::setEventDispatcher($dispatcher);
    }
    expect($attempts)->toBe(1)->and(cacheWriterRows())->toBe($before)
        ->and($failure)->toBeInstanceOf(RuntimeException::class);
});

it('does not mix a changed owner with bytes read through a prior forPath lookup', function (bool $alias) {
    $artifact = cacheWriterArtifact();
    $replacement = cacheWriterRun();
    $path = $artifact->artifact_path;
    if ($alias) {
        $path = Storage::disk('cache-source')->path('missing-alias.json');
        expect(is_file($path))->toBeFalse();
        $run = ModelRun::findOrFail($artifact->training_run_id);
        $run->update(['metadata' => [...$run->metadata, 'inference_alias_path' => $path]]);
    }
    $real = app(MlArtifactStorage::class);
    $storage = Mockery::mock(MlArtifactStorage::class)->makePartial();
    $afterIndependent = null;
    $storage->shouldReceive('materialize')->once()->andReturnUsing(function (...$args) use ($real, $artifact, $replacement, &$afterIndependent) {
        $local = $real->materialize(...$args);
        DB::table('model_artifacts')->where('id', $artifact->id)->update(['training_run_id' => $replacement->id]);
        $afterIndependent = cacheWriterRows();

        return $local;
    });
    $found = (new ModelArtifactRegistry($storage))->forPath($path);
    expect($afterIndependent)->not->toBeNull()->and(cacheWriterRows())->toBe($afterIndependent)->and($found)->toBeNull();
})->with(['exact path' => false, 'missing alias' => true]);

it('treats forPath as a lookup rather than an implicit requested cache write', function () {
    $artifact = cacheWriterArtifact();
    $before = cacheWriterRows();
    $dispatcher = ModelArtifact::getEventDispatcher();
    ModelArtifact::setEventDispatcher(clone $dispatcher);
    $attempts = 0;
    ModelArtifact::saving(function () use (&$attempts) {
        $attempts++;

        return false;
    });
    try {
        $found = app(ModelArtifactRegistry::class)->forPath($artifact->artifact_path);
    } finally {
        ModelArtifact::setEventDispatcher($dispatcher);
    }
    expect($found?->id)->toBe($artifact->id)->and($attempts)->toBe(0)->and(cacheWriterRows())->toBe($before);
});

it('retains bounded readonly materialization without SQL writes or save callbacks', function () {
    $artifact = cacheWriterArtifact();
    $before = cacheWriterRows();
    $dispatcher = ModelArtifact::getEventDispatcher();
    ModelArtifact::setEventDispatcher(clone $dispatcher);
    $attempts = 0;
    ModelArtifact::saving(function () use (&$attempts) {
        $attempts++;

        return false;
    });
    try {
        $path = app(ModelArtifactRegistry::class)->materializeArtifact($artifact, persistCachePath: false, maximumBytes: 33554432);
    } finally {
        ModelArtifact::setEventDispatcher($dispatcher);
    }
    expect(hash_file('sha256', $path))->toBe($artifact->artifact_hash)->and($attempts)->toBe(0)
        ->and(cacheWriterRows())->toBe($before);
});

it('retains valid current-owner requested artifact cache relocation', function () {
    $artifact = cacheWriterArtifact();
    $runs = cacheWriterRows()[1];
    $previous = $artifact->artifact_path;
    $path = app(ModelArtifactRegistry::class)->materializeArtifact($artifact);
    expect($path)->not->toBe($previous)->and($artifact->fresh()->artifact_path)->toBe($path)
        ->and(hash_file('sha256', $path))->toBe($artifact->artifact_hash)->and(cacheWriterRows()[1])->toBe($runs);
});

it('keeps an admitted projection without URI fields compatible with bounded readonly materialization', function () {
    $artifact = cacheWriterArtifact();
    $raw = $artifact->getAttributes();
    unset($raw['artifact_uri'], $raw['dataset_uri'], $raw['evaluation_report_uri']);
    $projection = (new ModelArtifact)->newFromBuilder($raw, $artifact->getConnection()->getName());
    $before = cacheWriterRows();
    $path = app(ModelArtifactRegistry::class)->materializeArtifact($projection, persistCachePath: false, maximumBytes: 33554432);
    expect(hash_file('sha256', $path))->toBe($artifact->artifact_hash)->and(cacheWriterRows())->toBe($before);
});

it('preserves the actual NBA lookup then materialize caller chain across a changed cache disk', function () {
    $artifact = cacheWriterArtifact('nba');
    $storedPath = $artifact->artifact_path;
    config(['nba.prediction.win_probability_calibration' => ['enabled' => true, 'shadow_enabled' => true,
        'apply_to_live_output' => false, 'artifact_path' => $storedPath]]);
    $runs = cacheWriterRows()[1];
    $result = app(WinProbabilityCalibrationInferenceService::class)->calibrate('nba', 0.61);
    expect($result['artifact_id'])->toBe($artifact->id)->and($result['shadow_active'])->toBeTrue()
        ->and($result['calibrated_win_probability'])->toBe(0.61)->and($result['active_source'])->toBe('baseline')
        ->and($artifact->fresh()->artifact_path)->not->toBe($storedPath)->and(cacheWriterRows()[1])->toBe($runs);
});

it('preserves the actual NFL historical lookup then materialize caller chain without changing approval', function () {
    $artifact = cacheWriterArtifact();
    $storedPath = $artifact->artifact_path;
    config(['nfl_ml.shadow.enabled' => false, 'nfl.predictions.full_historical_shadow' => ['enabled' => true,
        'artifact_path' => $storedPath, 'profile' => 'synthetic-cache-profile']]);
    $profiles = Mockery::mock(NflPredictionProfileConfigurator::class);
    $profiles->shouldReceive('withProfile')->once()->with('synthetic-cache-profile', Mockery::type('callable'))
        ->andReturnUsing(fn ($name, $work) => $work());
    app()->instance(NflPredictionProfileConfigurator::class, $profiles);
    $preview = Mockery::mock(GeneratePredictionFromHistoricalElo::class);
    $preview->shouldReceive('preview')->once()->andReturn(['outputs' => ['win_probability' => 0.64]]);
    app()->instance(GeneratePredictionFromHistoricalElo::class, $preview);
    $runs = cacheWriterRows()[1];
    $result = app(NflFullHistoricalShadowInferenceService::class)->evaluate(new Game(['id' => 123]), ['win_probability' => 0.61]);
    expect($result['artifact_id'])->toBe($artifact->id)->and($result['active'])->toBeTrue()
        ->and($result['challenger_output'])->toBe(0.64)->and($result['apply_to_live_output'])->toBeFalse()
        ->and($artifact->fresh()->artifact_path)->not->toBe($storedPath)->and($artifact->fresh()->status)->toBe('challenger')
        ->and(cacheWriterRows()[1])->toBe($runs);
});

function cachePeriodArtifact(): ModelArtifact
{
    $run = cacheWriterRun('mlb');
    $run->update(['model_version' => 'mlb-period-multiclass-v1', 'feature_version' => 'mlb-period-live-v2-observed-start',
        'metadata' => ['python_model_run_id' => $run->id, 'python_manifest' => [
            'timing_policy' => MlbGameStart::TIMING_POLICY,
            'source_availability_verified' => true,
            'execution_format' => MlbPeriodModelInferenceService::EXECUTION_FORMAT,
        ]]]);
    // This exercises opaque content materialization, not Python model/parser acceptance.
    Storage::disk('cache-source')->put('period.json', '{"synthetic_period_content":"opaque read-only fixture; never executed"}');
    $artifact = app(ModelArtifactRegistry::class)->register((string) Str::uuid(), $run, 'multi_market', 'mlb_period_bundle',
        'mlb-period-multiclass-v1', 'mlb-period-live-v2-observed-start', hash('sha256', 'synthetic-period-dataset'),
        Storage::disk('cache-source')->path('period.json'), []);
    config(['ml.storage.cache_disk' => 'cache-new']);

    return $artifact->refresh();
}

it('reads current declared period content without SQL cache writes or tabular training-proof authority', function () {
    $artifact = cachePeriodArtifact();
    $before = cacheWriterRows();
    expect(data_get(ModelRun::findOrFail($artifact->training_run_id)->metadata, 'mlb_tabular_admission'))->toBeNull();
    $path = app(ModelArtifactRegistry::class)->materializeArtifact($artifact, persistCachePath: false, maximumBytes: 33554432);
    expect(hash_file('sha256', $path))->toBe($artifact->artifact_hash)->and($path)->not->toBe($artifact->artifact_path)
        ->and(cacheWriterRows())->toBe($before);
});

it('rechecks the existing period metadata admission after cache IO', function () {
    $artifact = cachePeriodArtifact();
    $real = app(MlArtifactStorage::class);
    $storage = Mockery::mock(MlArtifactStorage::class)->makePartial();
    $afterIndependent = null;
    $storage->shouldReceive('materialize')->once()->andReturnUsing(function (...$args) use ($real, $artifact, &$afterIndependent) {
        $path = $real->materialize(...$args);
        $run = ModelRun::findOrFail($artifact->training_run_id);
        $metadata = $run->metadata;
        $metadata['python_manifest']['source_availability_verified'] = false;
        $run->update(['metadata' => $metadata]);
        $afterIndependent = cacheWriterRows();

        return $path;
    });
    $failure = cacheWriterAttempt(fn () => (new ModelArtifactRegistry($storage))->materializeArtifact($artifact, persistCachePath: false, maximumBytes: 33554432));
    expect($afterIndependent)->not->toBeNull()->and($failure)->toBeInstanceOf(RuntimeException::class)
        ->and(cacheWriterRows())->toBe($afterIndependent);
});

it('refuses a transplanted artifact table context without changing either same ID row', function () {
    $artifact = cacheWriterArtifact();
    DB::statement('CREATE TABLE cache_artifact_alias AS SELECT * FROM model_artifacts');
    $artifact->setTable('cache_artifact_alias');
    expect($artifact->isDirty())->toBeFalse()
        ->and(DB::table('cache_artifact_alias')->where('id', $artifact->id)->exists())->toBeTrue();
    $before = cacheWriterRows();
    $aliasBefore = (array) DB::table('cache_artifact_alias')->where('id', $artifact->id)->first();
    $failure = cacheWriterAttempt(fn () => app(ModelArtifactRegistry::class)->materializeArtifact($artifact));
    expect($failure)->toBeInstanceOf(RuntimeException::class)
        ->and(cacheWriterRows())->toBe($before)
        ->and((array) DB::table('cache_artifact_alias')->where('id', $artifact->id)->first())->toBe($aliasBefore);
});
