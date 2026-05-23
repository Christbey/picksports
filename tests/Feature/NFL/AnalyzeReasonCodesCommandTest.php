<?php

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;

it('analyzes required reason-code sets and generated combinations', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $createPrediction = function (array $gameOverrides, float $winProbability, array $reasonCodes) use ($homeTeam, $awayTeam): void {
        $game = Game::factory()->create(array_merge([
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'season' => 2025,
            'status' => 'STATUS_FINAL',
            'home_score' => 24,
            'away_score' => 17,
        ], $gameOverrides));

        Prediction::factory()->create([
            'game_id' => $game->id,
            'predicted_spread' => $winProbability > 0.5 ? 4.5 : -4.5,
            'predicted_total' => 44.5,
            'win_probability' => $winProbability,
            'confidence_score' => 68,
            'model_metadata' => [
                'analysis_layer' => [
                    'applied' => true,
                    'trust_score' => 68,
                    'reason_codes' => $reasonCodes,
                ],
            ],
        ]);
    };

    $createPrediction([], 0.7, ['strong_model_signal', 'qb_form_signal', 'rolling_efficiency_home_edge']);
    $createPrediction(['home_score' => 14, 'away_score' => 21], 0.4, ['strong_model_signal', 'qb_form_signal']);
    $createPrediction(['home_score' => 14, 'away_score' => 21], 0.8, ['strong_model_signal']);

    $this->artisan('nfl:analyze-reason-codes', [
        '--season' => 2025,
        '--codes' => ['strong_model_signal', 'qb_form_signal'],
        '--min-games' => 1,
        '--top' => 5,
    ])
        ->expectsOutputToContain('Analyzing 3 predictions with reason codes from season 2025')
        ->expectsOutputToContain('strong_model_signal + qb_form_signal')
        ->expectsOutputToContain('100%')
        ->assertSuccessful();
});
