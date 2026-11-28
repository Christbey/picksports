<?php

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\ML\MlArtifactStorage;
use App\Services\ML\ModelArtifactRegistry;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    Http::preventStrayRequests();
    Storage::fake('archive-source');
    Storage::fake('archive-object');
    Storage::fake('archive-cache');
    config(['ml.storage.disk' => 'archive-object', 'ml.storage.cache_disk' => 'archive-cache']);
});

afterEach(function () {
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

function archiveReportRun(string $sport): ModelRun
{
    return ModelRun::create(['id' => (string) Str::uuid(), 'sport' => $sport, 'run_type' => 'training',
        'model_version' => 'synthetic', 'feature_version' => 'synthetic', 'blend_version' => 'none',
        'config_hash' => hash('sha256', 'synthetic'), 'status' => 'completed', 'started_at' => now(),
        'metadata' => ['sentinel' => 'must-remain']]);
}

function archiveReportArtifact(string $sport): ModelArtifact
{
    $run = archiveReportRun($sport);
    $id = (string) Str::uuid();
    $source = Storage::disk('archive-source');
    $source->put($id.'.zip', 'opaque synthetic bytes; never loaded');
    $source->put($id.'.json', '{"windows":3}');

    return ModelArtifact::create(['id' => $id, 'sport' => $sport, 'training_run_id' => $run->id,
        'model_type' => $sport.'_tabular_bundle', 'model_version' => 'synthetic', 'feature_version' => 'synthetic',
        'market_type' => 'multi_market', 'dataset_hash' => hash('sha256', 'synthetic'),
        'artifact_path' => $source->path($id.'.zip'), 'artifact_hash' => hash_file('sha256', $source->path($id.'.zip')),
        'status' => 'challenger'])->refresh();
}

function archiveReportRows(): array
{
    return [DB::table('model_artifacts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        DB::table('model_runs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
}

it('refuses a stale unqualified MLB owner before archiving or attaching report pointers', function (string $operation, string $sport) {
    $artifact = archiveReportArtifact($sport);
    $artifact->load('trainingRun');
    $newOwner = archiveReportRun($sport);
    DB::table('model_artifacts')->where('id', $artifact->id)->update(['training_run_id' => $newOwner->id]);
    $before = archiveReportRows();
    $failure = null;
    try {
        $registry = app(ModelArtifactRegistry::class);
        $operation === 'archive' ? $registry->archiveExisting($artifact)
            : $registry->attachEvaluationReport($artifact, Storage::disk('archive-source')->path($artifact->id.'.json'));
    } catch (RuntimeException $error) {
        $failure = $error;
    }
    expect(archiveReportRows())->toBe($before)->and($failure)->toBeInstanceOf(RuntimeException::class);
})->with(['mlb archive' => ['archive', 'mlb'], 'mlb report' => ['report', 'mlb'], 'nfl archive' => ['archive', 'nfl'], 'nfl report' => ['report', 'nfl']]);

it('preserves nonMLB archive report attachment and different-report refusal', function () {
    $artifact = archiveReportArtifact('nfl');
    $registry = app(ModelArtifactRegistry::class);
    $registry->archiveExisting($artifact);
    expect($artifact->artifact_disk)->toBe('archive-object');
    $path = Storage::disk('archive-source')->path($artifact->id.'.json');
    $registry->attachEvaluationReport($artifact, $path);
    expect($artifact->evaluation_report_hash)->toBe(hash_file('sha256', $path));
    Storage::disk('archive-source')->put($artifact->id.'.json', '{"windows":4}');
    $before = archiveReportRows();
    expect(fn () => $registry->attachEvaluationReport($artifact, $path))->toThrow(RuntimeException::class, 'does not match');
    expect(archiveReportRows())->toBe($before);
});

it('preserves current unrelated run metadata written during storage staging', function () {
    $artifact = archiveReportArtifact('nfl');
    $artifact->load('trainingRun');
    $real = app(MlArtifactStorage::class);
    $storage = Mockery::mock(MlArtifactStorage::class)->makePartial();
    $storage->shouldReceive('storeReport')->once()->andReturnUsing(function (...$args) use ($real, $artifact) {
        $stored = $real->storeReport(...$args);
        DB::table('model_runs')->where('id', $artifact->training_run_id)->update(['metadata' => json_encode(['sentinel' => 'must-remain', 'new_current_field' => 'observed-during-io'])]);

        return $stored;
    });
    (new ModelArtifactRegistry($storage))->attachEvaluationReport($artifact, Storage::disk('archive-source')->path($artifact->id.'.json'));
    expect(ModelRun::findOrFail($artifact->training_run_id)->metadata['new_current_field'] ?? null)->toBe('observed-during-io');
});

it('rolls back archive pointer writes when an artifact or run save is cancelled', function (string $model) {
    $artifact = archiveReportArtifact('nfl');
    $before = archiveReportRows();
    $class = $model === 'artifact' ? ModelArtifact::class : ModelRun::class;
    $original = $class::getEventDispatcher();
    $class::setEventDispatcher(clone $original);
    $class::saving(fn () => false);
    $failure = null;
    try {
        app(ModelArtifactRegistry::class)->archiveExisting($artifact);
    } catch (RuntimeException $error) {
        $failure = $error;
    } finally {
        $class::setEventDispatcher($original);
    }
    expect(archiveReportRows())->toBe($before)->and($failure)->toBeInstanceOf(RuntimeException::class);
})->with(['artifact', 'run']);

it('reports unsupported MLB archive refusal without an unfenced status invalidation', function () {
    $artifact = archiveReportArtifact('mlb');
    Storage::disk('archive-source')->delete($artifact->id.'.zip');
    $before = archiveReportRows();
    $exit = Artisan::call('sports:archive-model-artifacts', ['--artifact' => [$artifact->id]]);
    expect(archiveReportRows())->toBe($before)->and($exit)->toBe(1);
});

it('does not invalidate a changed NFL owner when storage fails after concurrent ownership drift', function () {
    $artifact = archiveReportArtifact('nfl');
    $replacement = archiveReportRun('nfl');
    $afterIndependentWrite = null;
    $storage = Mockery::mock(MlArtifactStorage::class)->makePartial();
    $storage->shouldReceive('storeArtifact')->once()->andReturnUsing(function () use ($artifact, $replacement, &$afterIndependentWrite) {
        DB::table('model_artifacts')->where('id', $artifact->id)->update(['training_run_id' => $replacement->id]);
        $afterIndependentWrite = archiveReportRows();
        throw new RuntimeException('Synthetic storage backend failed after independent owner change.');
    });
    app()->instance(ModelArtifactRegistry::class, new ModelArtifactRegistry($storage));
    $exit = Artisan::call('sports:archive-model-artifacts', ['--artifact' => [$artifact->id]]);
    expect($afterIndependentWrite)->not->toBeNull()
        ->and(archiveReportRows())->toBe($afterIndependentWrite)->and($exit)->toBe(1);
});

it('retains current-owner nonMLB missing-file invalidation behavior', function () {
    $artifact = archiveReportArtifact('nfl');
    Storage::disk('archive-source')->delete($artifact->id.'.zip');
    $run = ModelRun::findOrFail($artifact->training_run_id)->getAttributes();
    $exit = Artisan::call('sports:archive-model-artifacts', ['--artifact' => [$artifact->id]]);
    expect($exit)->toBe(0)->and($artifact->fresh()->status)->toBe('invalidated')
        ->and($artifact->fresh()->promotion_decision['reason'])->toBe('artifact_file_missing_or_hash_mismatch')
        ->and(ModelRun::findOrFail($artifact->training_run_id)->getAttributes())->toBe($run);
});
