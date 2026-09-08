<?php

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;

uses()->group('nfl', 'commands');

it('audits point projections without loading model layers by default', function () {
    $home = Team::factory()->create(['abbreviation' => 'SEA']);
    $away = Team::factory()->create(['abbreviation' => 'NE']);
    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 1,
        'game_date' => '2026-09-08',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 27,
        'away_score' => 20,
        'odds_data' => [
            'home_team' => 'Seattle Seahawks',
            'bookmakers' => [[
                'markets' => [
                    ['key' => 'spreads', 'outcomes' => [['name' => 'Seattle Seahawks', 'point' => -3.5]]],
                    ['key' => 'totals', 'outcomes' => [['name' => 'Over', 'point' => 44.5]]],
                ],
            ]],
        ],
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 4.0,
        'predicted_total' => 45.0,
        'win_probability' => 0.62,
        'model_metadata' => [
            'true_epa' => ['applied' => true],
            'large_unused_payload' => array_fill(0, 1000, 'not-needed-without-layers'),
        ],
    ]);

    $this->artisan('nfl:analyze-point-projections', ['--season' => 2026])
        ->expectsOutputToContain('NFL Point Projection Audit')
        ->expectsOutputToContain('Rows: 1')
        ->expectsOutputToContain('Model vs Market')
        ->doesntExpectOutputToContain('By Active Model Layer')
        ->assertSuccessful();
});

it('loads model metadata when the point projection layer audit is requested', function () {
    $home = Team::factory()->create(['abbreviation' => 'SEA']);
    $away = Team::factory()->create(['abbreviation' => 'NE']);
    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 1,
        'game_date' => '2026-09-08',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 27,
        'away_score' => 20,
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 4.0,
        'predicted_total' => 45.0,
        'win_probability' => 0.62,
        'model_metadata' => ['true_epa' => ['applied' => true]],
    ]);

    $this->artisan('nfl:analyze-point-projections', [
        '--season' => 2026,
        '--layers' => true,
    ])
        ->expectsOutputToContain('By Active Model Layer')
        ->expectsOutputToContain('true_epa')
        ->assertSuccessful();
});

it('keeps the prediction accuracy audit on its minimal game projection', function () {
    $home = Team::factory()->create(['abbreviation' => 'SEA']);
    $away = Team::factory()->create(['abbreviation' => 'NE']);
    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 1,
        'game_date' => '2026-09-08',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 27,
        'away_score' => 20,
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 4.0,
        'predicted_total' => 45.0,
        'win_probability' => 0.62,
        'model_metadata' => [
            'analysis_layer' => [
                'bet_classification' => 'bet',
                'trust_score' => 80,
                'reason_codes' => ['test_signal'],
            ],
        ],
    ]);

    $this->artisan('nfl:analyze-predictions', [
        '--season' => 2026,
        '--detailed' => true,
    ])
        ->expectsOutputToContain('Total Predictions')
        ->expectsOutputToContain('Analysis Layer Breakdown')
        ->expectsOutputToContain('SEA vs NE')
        ->assertSuccessful();
});
