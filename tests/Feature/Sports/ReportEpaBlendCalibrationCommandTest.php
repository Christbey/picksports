<?php

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;

uses()->group('sports', 'nfl', 'commands');

it('reports nfl epa calibration using the winner correct column', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create([
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 27,
        'away_score' => 20,
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 4.0,
        'actual_spread' => 7.0,
        'predicted_total' => 44.0,
        'actual_total' => 47.0,
        'winner_correct' => true,
        'graded_at' => now(),
        'model_metadata' => [
            'true_epa' => ['true_epa_applied' => true],
        ],
    ]);

    $this->artisan('sports:report-epa-blend-calibration', [
        'sport' => 'nfl',
        '--season' => 2026,
    ])
        ->expectsOutputToContain('NFL EPA Blend Calibration Report')
        ->expectsOutputToContain('Total graded predictions: 1')
        ->expectsOutputToContain('100.00')
        ->assertSuccessful();
});
