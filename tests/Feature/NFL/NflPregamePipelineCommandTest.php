<?php

use App\Services\NFL\NflPregamePipelineRunner;

it('fails visibly before any pregame work when the canonical pipeline is disabled', function () {
    config()->set('prediction_lifecycle.canonical_pipeline.nfl', false);
    $runner = Mockery::mock(NflPregamePipelineRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(NflPregamePipelineRunner::class, $runner);

    $this->artisan('nfl:run-pregame-pipeline', ['--season' => 2026])
        ->expectsOutputToContain('PREDICTION_LIFECYCLE_NFL_CANONICAL_PIPELINE is disabled')
        ->assertFailed();
});

it('wires one bounded horizon through odds legacy and verified canonical generation', function () {
    config()->set('prediction_lifecycle.canonical_pipeline.nfl', true);
    $this->travelTo('2026-09-15 09:50:00');
    $runner = Mockery::mock(NflPregamePipelineRunner::class);
    $runner->shouldReceive('run')
        ->once()
        ->withArgs(function (array $steps, callable $afterStep): bool {
            expect($steps)->toBe([
                [
                    'name' => 'odds_sync',
                    'command' => 'nfl:sync-odds',
                    'arguments' => ['--days' => 8],
                ],
                [
                    'name' => 'legacy_generation',
                    'command' => 'nfl:generate-predictions',
                    'arguments' => ['--season' => 2026, '--date' => '2026-09-15', '--days-forward' => 8],
                    'continue_on_failure' => true,
                ],
                [
                    'name' => 'canonical_generation_and_readiness',
                    'command' => 'nfl:generate-canonical-predictions',
                    'arguments' => [
                        '--season' => 2026,
                        '--date' => '2026-09-15',
                        '--days-forward' => 8,
                        '--verify-readiness' => true,
                    ],
                ],
            ]);

            return true;
        })
        ->andReturn(['successful' => true, 'failed_step' => null, 'exit_code' => 0]);
    app()->instance(NflPregamePipelineRunner::class, $runner);

    $this->artisan('nfl:run-pregame-pipeline', ['--season' => 2026, '--days-forward' => 8])
        ->expectsOutputToContain('completed with full 8-day readiness verification')
        ->assertSuccessful();
});
