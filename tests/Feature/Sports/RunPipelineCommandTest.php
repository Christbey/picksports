<?php

use App\Services\Sports\PipelineCommandRunner;

use function Pest\Laravel\artisan;

it('prints a dry run plan for the nba full pipeline', function () {
    artisan('sports:run-pipeline nba --mode=full --season=2026 --date=2026-03-29 --dry-run')
        ->expectsOutput('Pipeline: NBA [full]')
        ->expectsOutput('Dry run only. No commands will be executed.')
        ->expectsOutputToContain('espn:sync-nba-games-scoreboard --from-date=2026-03-29 --to-date=2026-04-05')
        ->expectsOutputToContain('nba:generate-predictions --season=2026')
        ->expectsOutputToContain('sports:sync-futures-odds --sport=nba --season=2026')
        ->assertSuccessful();
});

it('runs the cfb predict pipeline in the expected order', function () {
    $runner = Mockery::mock(PipelineCommandRunner::class);
    $runner->shouldReceive('call')->once()->ordered()->with('cfb:grade-predictions', ['--season' => 2026])->andReturn(0);
    $runner->shouldReceive('call')->once()->ordered()->with('cfb:calculate-elo', ['--season' => 2026])->andReturn(0);
    $runner->shouldReceive('call')->once()->ordered()->with('cfb:import-fpi', ['--season' => 2026, '--week' => 8])->andReturn(0);
    $runner->shouldReceive('call')->once()->ordered()->with('cfb:calculate-team-metrics', ['--season' => 2026])->andReturn(0);
    $runner->shouldReceive('call')->once()->ordered()->with('cfb:generate-predictions', ['--season' => 2026])->andReturn(0);

    app()->instance(PipelineCommandRunner::class, $runner);

    artisan('sports:run-pipeline cfb --mode=predict --season=2026 --week=8')
        ->assertSuccessful();
});

it('fails for an unsupported sport', function () {
    artisan('sports:run-pipeline soccer --mode=full')
        ->expectsOutput('Unsupported sport [soccer]. Supported sports: nba, nfl, mlb, cbb, wcbb, wnba, cfb.')
        ->assertFailed();
});
