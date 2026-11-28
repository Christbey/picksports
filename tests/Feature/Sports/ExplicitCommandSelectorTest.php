<?php

use App\Actions\CBB\CalculateTeamMetrics;
use App\Actions\WNBA\GeneratePrediction;
use App\Console\Commands\CBB\CalculateTeamMetricsCommand;
use App\Console\Commands\WNBA\GeneratePredictionsCommand;
use App\Models\CBB\Team;
use App\Models\WNBA\Game;
use App\Models\WNBA\Prediction;
use Illuminate\Support\Facades\Artisan;

it('never widens an explicit empty or zero team selector into bulk metric calculation', function (string $sport, string $selected) {
    $teamClass = 'App\\Models\\'.strtoupper($sport).'\\Team';
    $actionClass = 'App\\Actions\\'.strtoupper($sport).'\\CalculateTeamMetrics';
    $commandClass = 'App\\Console\\Commands\\'.strtoupper($sport).'\\CalculateTeamMetricsCommand';
    $teamClass::factory()->create();
    $bulk = 0;
    $action = Mockery::mock($actionClass);
    $action->shouldReceive('executeForAllTeams')->andReturnUsing(function () use (&$bulk): int {
        $bulk++;

        return 0;
    });
    app()->instance($actionClass, $action);
    Artisan::registerCommand(app($commandClass));
    expect(Artisan::call($sport.':calculate-team-metrics', ['--season' => '2026', '--team' => $selected]))->toBe(1)
        ->and($bulk)->toBe(0);
})->with([['cbb', '0'], ['cbb', ''], ['wcbb', '0'], ['wcbb', '']]);

it('retains valid selected-team and omitted bulk metric behavior', function (bool $selected) {
    $team = Team::factory()->create();
    $action = Mockery::mock(CalculateTeamMetrics::class);
    $options = ['--season' => '2026'];
    if ($selected) {
        $options['--team'] = (string) $team->id;
        $action->shouldReceive('execute')->once()->withArgs(fn ($actual, $season) => $actual->is($team) && (int) $season === 2026)->andReturnNull();
    } else {
        $action->shouldReceive('executeForAllTeams')->once()->with('2026')->andReturn(0);
    }
    app()->instance(CalculateTeamMetrics::class, $action);
    Artisan::registerCommand(app(CalculateTeamMetricsCommand::class));
    expect(Artisan::call('cbb:calculate-team-metrics', $options))->toBe(0);
})->with([true, false]);

it('never widens an explicit empty or zero game selector into forecast generation', function (string $selected) {
    config()->set('prediction_lifecycle.canonical_pipeline.wnba', false);
    $game = Game::factory()->create(['home_team_id' => App\Models\WNBA\Team::factory()->create()->id, 'away_team_id' => App\Models\WNBA\Team::factory()->create()->id, 'game_date' => now()->toDateString(), 'game_time' => '18:00:00', 'status' => 'STATUS_SCHEDULED']);
    $calls = 0;
    $action = Mockery::mock(GeneratePrediction::class);
    $action->shouldReceive('execute')->andReturnUsing(function () use (&$calls) {
        $calls++;

        return null;
    });
    app()->instance(GeneratePrediction::class, $action);
    Artisan::registerCommand(app(GeneratePredictionsCommand::class));
    expect(Artisan::call('wnba:generate-predictions', ['--game' => $selected, '--date' => now()->toDateString()]))->toBe(1)
        ->and($calls)->toBe(0)->and(Prediction::query()->count())->toBe(0);
})->with(['0', '']);

it('retains valid selected-game and omitted date-scoped generation', function (bool $selected) {
    config()->set('prediction_lifecycle.canonical_pipeline.wnba', false);
    $game = Game::factory()->create(['home_team_id' => App\Models\WNBA\Team::factory()->create()->id, 'away_team_id' => App\Models\WNBA\Team::factory()->create()->id, 'game_date' => now()->toDateString(), 'game_time' => '18:00:00', 'status' => 'STATUS_SCHEDULED']);
    $action = Mockery::mock(GeneratePrediction::class);
    $action->shouldReceive('execute')->once()->withArgs(fn ($actual) => $actual->is($game))->andReturnNull();
    app()->instance(GeneratePrediction::class, $action);
    Artisan::registerCommand(app(GeneratePredictionsCommand::class));
    $options = ['--date' => now()->toDateString()];
    if ($selected) {
        $options['--game'] = (string) $game->id;
    }
    expect(Artisan::call('wnba:generate-predictions', $options))->toBe(0);
})->with([true, false]);
