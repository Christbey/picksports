<?php

use App\Models\ModelRun;
use App\Services\ML\LocalTrainingExecutor;
use App\Services\ML\ShadowArtifactSelector;
use App\Services\ML\TrainingAttemptService;
use App\Services\ML\WeeklyChallengerTrainingService;
use App\Services\MLB\MlbTabularModelRunRegistrar;
use App\Services\NFL\NflTabularModelRunRegistrar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('records executor failure without registering an artifact and releases the cycle lock', function () {
    $directory = sys_get_temp_dir().'/picksports-cycle-'.Str::uuid();
    config()->set('mlb_ml.weekly_training.work_directory', $directory);
    config()->set('mlb_ml.weekly_training.enabled', true);
    $executor = Mockery::mock(LocalTrainingExecutor::class);
    $executor->shouldReceive('execute')->twice()->andThrow(new RuntimeException('training failed'));
    $shadow = Mockery::mock(ShadowArtifactSelector::class);
    $shadow->shouldReceive('activeChallenger')->twice()->andReturnNull();
    $mlb = Mockery::mock(MlbTabularModelRunRegistrar::class);
    $mlb->shouldNotReceive('register');
    $nfl = Mockery::mock(NflTabularModelRunRegistrar::class);
    $nfl->shouldNotReceive('register');
    Artisan::shouldReceive('call')->with('mlb:export-training-data', Mockery::on(function (array $arguments): bool {
        File::put($arguments['--path'], "target_home_win\n1\n");

        return true;
    }))->twice()->andReturn(0);
    $service = new WeeklyChallengerTrainingService($mlb, $nfl, $shadow, $executor, app(TrainingAttemptService::class));

    try {
        foreach ([1, 2] as $attempt) {
            expect(fn () => $service->run('mlb'))->toThrow(RuntimeException::class, 'training failed');
        }

        $cycles = ModelRun::query()->where('run_type', 'weekly_training_cycle')->get();
        expect($cycles)->toHaveCount(2);
        foreach ($cycles as $cycle) {
            expect($cycle->status)->toBe('failed')
                ->and($cycle->completed_at)->not->toBeNull()
                ->and(data_get($cycle->metadata, 'failed_stage'))->toBe('python_training')
                ->and(data_get($cycle->metadata, 'error'))->toBe('training failed')
                ->and(File::exists($directory.'/'.$cycle->id.'/failure.json'))->toBeTrue();
        }
    } finally {
        File::deleteDirectory($directory);
    }
});
