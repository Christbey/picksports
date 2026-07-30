<?php

use App\Services\ML\WeeklyChallengerTrainingService;

uses()->group('sports');

it('rejects unsupported sports before starting a training cycle', function () {
    $this->artisan('sports:train-weekly-model-challenger', ['sport' => 'nba'])
        ->expectsOutputToContain('supports only MLB and NFL')
        ->assertFailed();
});

it('does not run a disabled weekly training cycle unless forced', function () {
    config()->set('mlb_ml.weekly_training.enabled', false);

    $this->artisan('sports:train-weekly-model-challenger', ['sport' => 'mlb'])
        ->expectsOutputToContain('weekly model training is disabled')
        ->expectsOutputToContain('Status: disabled')
        ->assertSuccessful();
});

it('reports the immutable lineage returned by a completed weekly cycle', function () {
    $service = Mockery::mock(WeeklyChallengerTrainingService::class);
    $service->shouldReceive('run')
        ->once()
        ->with('nfl', true, false, true)
        ->andReturn([
            'status' => 'completed',
            'message' => 'NFL weekly challenger trained.',
            'cycle_run_id' => 'cycle-id',
            'model_run_id' => 'model-run-id',
            'artifact_id' => 'artifact-id',
            'dataset_hash' => str_repeat('a', 64),
            'training_fingerprint' => str_repeat('b', 64),
            'shadow_artifact_id' => 'shadow-id',
        ]);
    app()->instance(WeeklyChallengerTrainingService::class, $service);

    $this->artisan('sports:train-weekly-model-challenger', [
        'sport' => 'NFL',
        '--force' => true,
        '--no-promote' => true,
        '--retain-workdir' => true,
    ])
        ->expectsOutputToContain('Artifact: artifact-id')
        ->expectsOutputToContain('Dataset SHA-256: '.str_repeat('a', 64))
        ->expectsOutputToContain('Active shadow challenger: shadow-id')
        ->assertSuccessful();
});
