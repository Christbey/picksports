<?php

use App\Services\MLB\MlbPeriodFeatureBuilder;

it('refuses unavailable default registration before historical source scanning', function () {
    $calls = 0;
    $builder = Mockery::mock(MlbPeriodFeatureBuilder::class);
    $builder->shouldReceive('historicalRows')->andReturnUsing(function () use (&$calls) {
        $calls++;

        return collect();
    });
    app()->instance(MlbPeriodFeatureBuilder::class, $builder);
    $this->artisan('mlb:train-period-models')->assertFailed();
    expect($calls)->toBe(0);
});

it('retains no-register retrospective refusal before child work', function () {
    $builder = Mockery::mock(MlbPeriodFeatureBuilder::class);
    $builder->shouldReceive('historicalRows')->once()->andReturn(collect([
        ['pregame_safe' => false, 'availability_status' => 'reconstructed_now'],
    ]));
    app()->instance(MlbPeriodFeatureBuilder::class, $builder);
    $this->artisan('mlb:train-period-models', ['--no-register' => true])
        ->expectsOutput('Retrospective or unknown-availability rows cannot enter point-in-time model training.')
        ->assertFailed();
});

it('retains no-register empty source refusal', function () {
    $builder = Mockery::mock(MlbPeriodFeatureBuilder::class);
    $builder->shouldReceive('historicalRows')->once()->andReturn(collect());
    app()->instance(MlbPeriodFeatureBuilder::class, $builder);
    $this->artisan('mlb:train-period-models', ['--no-register' => true])
        ->expectsOutput('No MLB period training rows were available.')
        ->assertFailed();
});
