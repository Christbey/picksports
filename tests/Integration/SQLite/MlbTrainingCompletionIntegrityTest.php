<?php

use App\Models\MLB\Game;
use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Models\ModelTrainingWorker;
use App\Models\PredictionFeatureSnapshot;
use App\Services\ML\RemoteTrainingPreparation;
use App\Services\ML\TrainingAttemptService;
use App\Services\ML\WeeklyChallengerTrainingService;
use App\Services\MLB\MlbArtifactCohortSerializer;
use App\Services\MLB\MlbTrainingCompletion;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ObservedMlbDatasetFixture;
use Tests\Support\SingleActorSqliteMlbArtifactCohortSerializer;
use Tests\Support\SyntheticMlbTabularBundleFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    Http::preventStrayRequests();
    Storage::fake('mlb-completion-source');
    Storage::fake('mlb-completion-cache');
    config(['ml.storage.disk' => 'mlb-completion-source', 'ml.storage.cache_disk' => 'mlb-completion-cache']);
    $this->completionRoot = storage_path('framework/testing/mlb-completion-'.Str::uuid());
    config(['mlb_ml.bundle.staging_directory' => $this->completionRoot.'/staging']);
    // Functional-only serialization: the actual source fence/owned transaction checks remain real.
    app()->instance(MlbArtifactCohortSerializer::class, new SingleActorSqliteMlbArtifactCohortSerializer);
    $this->completionSource = null;
});

afterEach(function () {
    if ($this->completionSource !== null) {
        @unlink($this->completionSource['path']);
        @unlink($this->completionSource['path'].'.manifest.json');
    }
    File::deleteDirectory($this->completionRoot);
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

function syntheticMlbCompletionCycle(): ModelRun
{
    return ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'mlb', 'run_type' => 'weekly_training_cycle',
        'model_version' => 'mlb-tabular-v1', 'feature_version' => 'mlb-pregame-ml-v1', 'blend_version' => 'automated-weekly-v1',
        'config_hash' => hash('sha256', 'synthetic-frozen-specification'), 'status' => 'running', 'started_at' => now(), 'metadata' => []]);
}

function prepareSyntheticMlbCompletion($test): array
{
    $source = ObservedMlbDatasetFixture::create(true);
    $test->completionSource = $source;
    $cycle = syntheticMlbCompletionCycle();
    $specification = ['model_type' => 'mlb_tabular_bundle', 'model_version' => 'mlb-tabular-v1', 'feature_version' => 'mlb-pregame-ml-v1'];
    $cycle->update(['metadata' => ['dataset_path' => $source['path'], 'dataset_hash' => $source['proof']['dataset_sha256'],
        'training_fingerprint' => hash('sha256', 'synthetic-fingerprint'), 'training_specification' => $specification]]);
    $attempt = app(TrainingAttemptService::class)->claim($cycle->id, 300);
    $fixture = SyntheticMlbTabularBundleFixture::create($test->completionRoot.'/run', $source['path'], $attempt->python_run_id);
    $service = app(MlbTrainingCompletion::class);
    $prepared = $service->prepare($cycle, $attempt, $fixture['run_directory'], $source['path'], hash('sha256', 'synthetic-output'));

    return compact('source', 'cycle', 'attempt', 'fixture', 'service', 'prepared');
}

it('refuses an unstaged generic MLB completion callback before persistence', function () {
    $cycle = syntheticMlbCompletionCycle();
    $attempt = app(TrainingAttemptService::class)->claim($cycle->id, 300);
    $called = false;
    expect(fn () => app(TrainingAttemptService::class)->complete($attempt, hash('sha256', 'synthetic-output'), function () use (&$called, $cycle) {
        $called = true;
        $cycle->update(['artifact_path' => 'unqualified-generic-path']);

        return ['status' => 'completed'];
    }))->toThrow(RuntimeException::class, 'staged source-first entry');
    expect($called)->toBeFalse()->and($cycle->fresh()->artifact_path)->toBeNull()->and($attempt->fresh()->status)->toBe('running');
});

it('functionally commits one new owned run and artifact and replays the exact receipt', function () {
    $fixture = prepareSyntheticMlbCompletion($this);
    $before = ModelRun::count();
    expect(ModelArtifact::count())->toBe(0);
    $first = $fixture['service']->commit($fixture['prepared']);
    $second = $fixture['service']->commit($fixture['prepared']);
    expect($second)->toBe($first)->and(ModelArtifact::count())->toBe(1)->and(ModelRun::count())->toBe($before + 1)
        ->and($first['model_run_id'])->toBe($fixture['attempt']->python_run_id)
        ->and($fixture['attempt']->fresh()->status)->toBe('succeeded')->and($fixture['cycle']->fresh()->status)->toBe('completed')
        ->and(ModelRun::findOrFail($first['model_run_id'])->metadata['mlb_tabular_admission']['dataset_sha256'])->toBe($fixture['source']['proof']['dataset_sha256']);
});

it('does not let a copied prepared completion mint ownership', function () {
    $fixture = prepareSyntheticMlbCompletion($this);
    expect(fn () => $fixture['service']->commit(clone $fixture['prepared']))->toThrow(RuntimeException::class, 'exact owned prepared carrier');
    expect(ModelArtifact::count())->toBe(0)->and($fixture['attempt']->fresh()->status)->toBe('running');
});

it('refuses current source correction after staging without target inserts', function () {
    $fixture = prepareSyntheticMlbCompletion($this);
    $before = ModelRun::count();
    $fixture['source']['game']->update(['home_score' => 6]);
    expect(fn () => $fixture['service']->commit($fixture['prepared']))->toThrow(RuntimeException::class);
    expect(ModelArtifact::count())->toBe(0)->and(ModelRun::count())->toBe($before)->and($fixture['attempt']->fresh()->status)->toBe('running');
});

it('refuses an expired attempt after staging without target inserts', function () {
    $fixture = prepareSyntheticMlbCompletion($this);
    $before = ModelRun::count();
    $this->travel(300)->seconds();
    expect(fn () => $fixture['service']->commit($fixture['prepared']))->toThrow(RuntimeException::class, 'no longer active');
    expect(ModelArtifact::count())->toBe(0)->and(ModelRun::count())->toBe($before)->and($fixture['attempt']->fresh()->status)->toBe('running');
});

it('refuses an unsupported SQLite mutation before any staging writes', function () {
    app()->instance(MlbArtifactCohortSerializer::class, new MlbArtifactCohortSerializer);
    $cycle = syntheticMlbCompletionCycle();
    $attempt = app(TrainingAttemptService::class)->claim($cycle->id, 300);
    $before = ModelRun::count();
    expect(fn () => app(MlbTrainingCompletion::class)->prepare($cycle, $attempt, '/unread-staging-sentinel', '/unread-dataset-sentinel', hash('sha256', 'synthetic')))
        ->toThrow(RuntimeException::class, 'verified MySQL range serialization');
    expect(ModelArtifact::count())->toBe(0)->and(ModelRun::count())->toBe($before)->and(Storage::disk('mlb-completion-source')->allFiles())->toBe([]);
});

it('retains a server-owned inspected dataset proof separately from worker-visible inputs', function () {
    $source = ObservedMlbDatasetFixture::create(true);
    $this->completionSource = $source;
    $cycle = syntheticMlbCompletionCycle();
    $cycle->update(['metadata' => ['dataset_path' => $source['path'], 'dataset_hash' => $source['proof']['dataset_sha256']]]);
    config(['ml.remote.enabled' => true]);
    $worker = ModelTrainingWorker::create(['id' => (string) Str::uuid(), 'name' => 'synthetic-worker',
        'token_hash' => hash('sha256', 'synthetic-only'), 'sports' => ['mlb'], 'abilities' => ['training:complete'], 'expires_at' => now()->addDay()]);
    app(RemoteTrainingPreparation::class)->prepare($cycle, $worker->id, [
        'schema_path' => base_path('ml/mlb/config/feature_schema.yaml'), 'package_directory' => base_path('ml/mlb'),
    ]);
    $metadata = $cycle->fresh()->metadata;
    expect(data_get($metadata, 'remote_inputs.dataset_proof'))->toBeArray();
    expect(data_get($metadata, 'mlb_remote_protocol'))->toBe('mlb-staged-source-v1');
    expect(array_keys($metadata['remote_inputs']))->toBe(['dataset', 'schema', 'dataset_proof']);
});

it('refuses a frozen specification changed after its caller snapshot before preparation', function () {
    $fixture = prepareSyntheticMlbCompletion($this);
    $snapshot = $fixture['cycle']->fresh();
    $current = $fixture['cycle']->fresh();
    $current->update(['metadata' => [...$current->metadata, 'training_specification' => [...$current->metadata['training_specification'], 'threads' => 2]]]);
    expect(fn () => $fixture['service']->prepare($snapshot, $fixture['attempt'], $fixture['fixture']['run_directory'],
        $fixture['source']['path'], hash('sha256', 'synthetic-output')))->toThrow(RuntimeException::class, 'binding changed');
    expect(ModelArtifact::count())->toBe(0);
});

it('does not restore stale frozen metadata through local completion progress updates', function () {
    $fixture = prepareSyntheticMlbCompletion($this);
    $snapshot = $fixture['cycle']->fresh();
    $current = $fixture['cycle']->fresh();
    $changed = [...$current->metadata['training_specification'], 'threads' => 2];
    $current->update(['metadata' => [...$current->metadata, 'training_specification' => $changed]]);
    expect(fn () => app(WeeklyChallengerTrainingService::class)->completeAttempt(
        $snapshot, $fixture['attempt'], $fixture['fixture']['run_directory'], hash('sha256', 'synthetic-output'),
    ))->toThrow(RuntimeException::class, 'binding changed');
    expect($current->fresh()->metadata['training_specification'])->toBe($changed)->and(ModelArtifact::count())->toBe(0);
});

it('retains complete disjoint season exports for native completion contention without truncating history', function () {
    $sources = [];
    try {
        foreach ([2025, 2026] as $season) {
            app()->forgetInstance(ModelRunRecorder::class);
            $this->travelTo(Carbon::parse($season.'-06-27T06:00:00Z'));
            $source = ObservedMlbDatasetFixture::create(retainDataset: true, gameDate: $season.'-06-28', season: $season,
                exportSeasons: [$season], finalizedAt: Carbon::parse($season.'-06-30T06:00:00Z'));
            $sources[] = $source;
            expect($source['proof']['complete_scan'])->toBeTrue()->and($source['proof']['limit_hit'])->toBeFalse()
                ->and($source['proof']['seasons'])->toBe([$season])
                ->and(array_column($source['proof']['references'], 'game_id'))->toBe([$source['game']->id]);
        }
        expect(array_intersect(array_column($sources[0]['proof']['references'], 'model_run_id'),
            array_column($sources[1]['proof']['references'], 'model_run_id')))->toBe([]);
        expect($sources[0]['game']->id)->not->toBe($sources[1]['game']->id)
            ->and(Game::count())->toBe(2)
            ->and(PredictionFeatureSnapshot::count())->toBe(2);
    } finally {
        foreach ($sources as $source) {
            @unlink($source['path']);
            @unlink($source['path'].'.manifest.json');
        }
    }
});
