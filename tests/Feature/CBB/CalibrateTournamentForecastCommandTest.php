<?php

use App\Actions\CBB\GenerateTournamentForecast;
use App\Services\TournamentForecast\CbbTournamentForecastTuningStore;
use Mockery\MockInterface;

uses()->group('cbb', 'commands');

it('fails instead of reporting a save success when tuned params cannot be persisted', function () {
    config()->set('cbb.tournament_forecast.field_size', 68);

    $result = collect([
        [
            'team_id' => 1,
            'selection_score' => 1.0,
            'tournament_make_probability' => 0.9,
            'champion_probability' => 0.1,
        ],
        [
            'team_id' => 2,
            'selection_score' => 0.8,
            'tournament_make_probability' => 0.8,
            'champion_probability' => 0.2,
        ],
    ]);

    $this->mock(GenerateTournamentForecast::class, function (MockInterface $mock) use ($result): void {
        $mock->shouldReceive('simulateForBacktest')
            ->andReturn($result);
    });

    $this->mock(CbbTournamentForecastTuningStore::class, function (MockInterface $mock): void {
        $mock->shouldReceive('setForSeason')
            ->once()
            ->andThrow(new RuntimeException('Application settings table is missing.'));
    });

    $this->artisan('cbb:calibrate-tournament-forecast', [
        '--season' => 2026,
        '--simulations' => 250,
        '--repeats' => 2,
        '--save' => true,
    ])
        ->expectsOutputToContain('Failed to save tuned params: Application settings table is missing.')
        ->assertExitCode(1);
});
