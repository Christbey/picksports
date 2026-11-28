<?php

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Models\ModelTrainingAttempt;
use App\Services\ML\LocalTrainingExecutor;
use App\Services\ML\ShadowArtifactSelector;
use App\Services\ML\WeeklyChallengerTrainingService;
use App\Services\MLB\MlbArtifactCohortSerializer;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
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
    Storage::fake('mlb-recovery-source');
    Storage::fake('mlb-recovery-cache');
    config(['ml.storage.disk' => 'mlb-recovery-source', 'ml.storage.cache_disk' => 'mlb-recovery-cache']);
    app()->instance(MlbArtifactCohortSerializer::class, new SingleActorSqliteMlbArtifactCohortSerializer);
    // Explicit production writer/export before the CLI facade is mocked; no read-time certification.
    $this->source = ObservedMlbDatasetFixture::create(true);
    $this->directory = sys_get_temp_dir().'/picksports-recovery-'.Str::uuid();
    config()->set('mlb_ml.weekly_training.work_directory', $this->directory);
    config()->set('mlb_ml.weekly_training.enabled', true);
    config()->set('mlb_ml.bundle.staging_directory', $this->directory.'/owned-staging');
    $this->shadow = Mockery::mock(ShadowArtifactSelector::class);
    $this->shadow->shouldReceive('activeChallenger')->andReturnNull();
    app()->instance(ShadowArtifactSelector::class, $this->shadow);
    Artisan::shouldReceive('call')->with('mlb:export-training-data', Mockery::on(function (array $arguments): bool {
        File::copy($this->source['path'], $arguments['--path']);
        File::copy($this->source['path'].'.manifest.json', $arguments['--path'].'.manifest.json');

        return true;
    }))->once()->andReturn(0);
    $this->executor = Mockery::mock(LocalTrainingExecutor::class);
    app()->instance(LocalTrainingExecutor::class, $this->executor);
});

afterEach(function () {
    File::deleteDirectory($this->directory);
    @unlink($this->source['path']);
    @unlink($this->source['path'].'.manifest.json');
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

it('resumes a prepared cycle and recovers interrupted registration before replaying its receipt', function (bool $interruptCompletion) {
    $this->executor->shouldReceive('execute')->once()->andThrow(new RuntimeException('first failure'));
    $service = app(WeeklyChallengerTrainingService::class);
    expect(fn () => $service->run('mlb'))->toThrow(RuntimeException::class, 'first failure');
    $cycle = ModelRun::query()->where('run_type', 'weekly_training_cycle')->sole();
    $first = ModelTrainingAttempt::query()->sole();
    $dataset = data_get($cycle->metadata, 'dataset_path');
    $this->executor->shouldReceive('execute')->times($interruptCompletion ? 2 : 1)->andReturnUsing(function ($sport, $runId, $path, $hash, $output) use ($dataset): string {
        expect($sport)->toBe('mlb')->and($path)->toBe($dataset)->and(hash_file('sha256', $path))->toBe($hash);
        $directory = $output.'/'.$runId;
        SyntheticMlbTabularBundleFixture::create($directory, $path, $runId);

        return $directory;
    });
    $evaluations = 0;
    Event::listen('eloquent.updating: '.ModelArtifact::class, function (ModelArtifact $artifact) use (&$evaluations, $interruptCompletion): void {
        if ($artifact->isDirty('promotion_decision') && $interruptCompletion && $evaluations++ === 0) {
            throw new RuntimeException('interrupted after registration');
        }
    });
    $service = app(WeeklyChallengerTrainingService::class);
    if ($interruptCompletion) {
        expect(fn () => $service->resume('mlb', $cycle->id))->toThrow(RuntimeException::class, 'interrupted after registration');
        expect(ModelArtifact::query()->count())->toBe(0)
            ->and(ModelRun::query()->where('run_type', 'training')->count())->toBe(0)
            ->and(data_get($cycle->refresh()->metadata, 'failed_stage'))->toBe('completion_commit');
    }
    $result = $service->resume('mlb', $cycle->id);
    $replay = $service->resume('mlb', $cycle->id);
    // Native JSON storage may reorder object members; keep strict values/types.
    ksort($result);
    ksort($replay);

    expect($result['status'])->toBe('completed')
        ->and($result['model_run_id'])->not->toBe($first->python_run_id)
        ->and(ModelTrainingAttempt::query()->count())->toBe($interruptCompletion ? 3 : 2)
        ->and(ModelArtifact::query()->count())->toBe(1)
        ->and(File::exists(dirname($dataset)))->toBeFalse()
        ->and($replay)->toBe($result);
})->with([false, true]);

it('refuses resume when the frozen dataset has changed', function () {
    $this->executor->shouldReceive('execute')->once()->andThrow(new RuntimeException('failure'));
    $service = app(WeeklyChallengerTrainingService::class);
    expect(fn () => $service->run('mlb'))->toThrow(RuntimeException::class, 'failure');
    $cycle = ModelRun::query()->where('run_type', 'weekly_training_cycle')->sole();
    File::put(data_get($cycle->metadata, 'dataset_path'), "target_home_win\n0\n");

    expect(fn () => $service->resume('mlb', $cycle->id))->toThrow(RuntimeException::class, 'Frozen training');
    expect(ModelTrainingAttempt::query()->count())->toBe(1);
});
