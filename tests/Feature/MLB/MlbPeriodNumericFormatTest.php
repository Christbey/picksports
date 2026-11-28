<?php

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\ML\ShadowArtifactSelector;
use App\Services\MLB\MlbPeriodModelInferenceService;
use App\Services\MLB\MlbPeriodModelRunRegistrar;
use App\Support\MLB\MlbGameStart;

it('refuses legacy period inference before materializing any artifact', function () {
    $registry = Mockery::mock(ModelArtifactRegistry::class);
    $registry->shouldNotReceive('materializeArtifact');
    $artifact = new ModelArtifact(['sport' => 'mlb', 'model_type' => 'mlb_period_bundle', 'status' => 'challenger']);
    $artifact->setRelation('trainingRun', new ModelRun(['metadata' => ['python_manifest' => [
        'timing_policy' => MlbGameStart::TIMING_POLICY,
        'source_availability_verified' => true,
    ]]]));

    expect(fn () => (new MlbPeriodModelInferenceService($registry))->predict($artifact, []))
        ->toThrow(RuntimeException::class, 'numeric execution format');
});

it('refuses legacy period registration before accessing dataset or bundle bytes', function () {
    $directory = sys_get_temp_dir().'/picksports-period-format-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory.'/manifest.json', json_encode([
        'timing_policy' => MlbGameStart::TIMING_POLICY,
        'source_availability_verified' => true,
    ], JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/evaluation.json', '{}');
    try {
        $registrar = new MlbPeriodModelRunRegistrar(Mockery::mock(ModelArtifactRegistry::class), Mockery::mock(ShadowArtifactSelector::class));
        expect(fn () => $registrar->register($directory.'/not-present.json', $directory.'/manifest.json', $directory.'/evaluation.json', $directory.'/not-present.csv'))
            ->toThrow(RuntimeException::class, 'numeric execution format');
    } finally {
        unlink($directory.'/manifest.json');
        unlink($directory.'/evaluation.json');
        rmdir($directory);
    }
});

it('admits the numeric period marker through to the existing artifact materializer', function () {
    $registry = Mockery::mock(ModelArtifactRegistry::class);
    $registry->shouldReceive('materializeArtifact')->once()->andThrow(new RuntimeException('synthetic materialization boundary'));
    $artifact = new ModelArtifact(['sport' => 'mlb', 'model_type' => 'mlb_period_bundle', 'status' => 'challenger']);
    $artifact->setRelation('trainingRun', new ModelRun(['metadata' => ['python_manifest' => [
        'timing_policy' => MlbGameStart::TIMING_POLICY,
        'source_availability_verified' => true,
        'execution_format' => MlbPeriodModelInferenceService::EXECUTION_FORMAT,
    ]]]));

    expect(fn () => (new MlbPeriodModelInferenceService($registry))->predict($artifact, []))
        ->toThrow(RuntimeException::class, 'synthetic materialization boundary');
});

it('keeps observed-start admission mandatory for a numeric period manifest', function () {
    $registry = Mockery::mock(ModelArtifactRegistry::class);
    $registry->shouldNotReceive('materializeArtifact');
    $artifact = new ModelArtifact(['sport' => 'mlb', 'model_type' => 'mlb_period_bundle', 'status' => 'challenger']);
    $artifact->setRelation('trainingRun', new ModelRun(['metadata' => ['python_manifest' => [
        'execution_format' => MlbPeriodModelInferenceService::EXECUTION_FORMAT,
    ]]]));

    expect(fn () => (new MlbPeriodModelInferenceService($registry))->predict($artifact, []))
        ->toThrow(RuntimeException::class, 'observed-start/source-availability policy');
});

it('materializes admitted numeric period bytes read only under the existing 32 MiB policy', function () {
    $registry = Mockery::mock(ModelArtifactRegistry::class);
    $observed = null;
    $registry->shouldReceive('materializeArtifact')->once()->andReturnUsing(function (ModelArtifact $current, bool $persistCachePath = true, ?int $maximumBytes = null) use (&$observed) {
        $observed = [$persistCachePath, $maximumBytes];
        throw new RuntimeException('synthetic admitted period materializer');
    });
    $artifact = new ModelArtifact(['sport' => 'mlb', 'model_type' => 'mlb_period_bundle', 'status' => 'challenger']);
    $artifact->setRelation('trainingRun', new ModelRun(['metadata' => ['python_manifest' => [
        'timing_policy' => MlbGameStart::TIMING_POLICY,
        'source_availability_verified' => true,
        'execution_format' => MlbPeriodModelInferenceService::EXECUTION_FORMAT,
    ]]]));
    expect(fn () => (new MlbPeriodModelInferenceService($registry))->predict($artifact, []))
        ->toThrow(RuntimeException::class, 'synthetic admitted period materializer');
    expect($observed)->toBe([false, 33554432]);
});
