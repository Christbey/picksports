<?php

use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\PredictionEvaluation;
use App\Services\Predictions\PredictionEvaluationRecorder;

it('compares actual home margin with a normalized bookmaker home line', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();
    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 105,
        'away_score' => 100,
        'status' => 'STATUS_FINAL',
    ]);
    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'vegas_spread' => -3.5,
        'spread_error' => 2.0,
    ]);

    app(PredictionEvaluationRecorder::class)->record($prediction, $game, 'nba', 5.0, 205.0);

    $comparison = PredictionEvaluation::query()->sole()->market_comparison;

    expect((float) $comparison['bookmaker_home_spread'])->toBe(-3.5)
        ->and((float) $comparison['market_spread'])->toBe(3.5)
        ->and((float) $comparison['market_spread_error'])->toBe(1.5)
        ->and($comparison['bookmaker_spread_convention'])->toBe('bookmaker_home_line_negative_favorite')
        ->and($comparison['market_spread_convention'])->toBe('home_margin_positive_home')
        ->and($comparison['model_beats_market_spread'])->toBeFalse();
});

it('does not score tied games as away wins', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();
    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 100,
        'away_score' => 100,
        'status' => 'STATUS_FINAL',
    ]);
    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'win_probability' => 0.7,
        'winner_correct' => false,
    ]);

    app(PredictionEvaluationRecorder::class)->record($prediction, $game, 'nba', 0.0, 200.0);

    $evaluation = PredictionEvaluation::query()->sole();

    expect($evaluation->actuals['actual_home_win'])->toBeNull()
        ->and($evaluation->errors['winner_correct'])->toBeNull()
        ->and($evaluation->errors['win_probability_error'])->toBeNull()
        ->and($evaluation->errors['brier_score'])->toBeNull()
        ->and($evaluation->errors['log_loss'])->toBeNull();
});

it('rebuilds existing evaluations with the canonical spread convention', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();
    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 105,
        'away_score' => 100,
        'status' => 'STATUS_FINAL',
    ]);
    $prediction = Prediction::factory()->create([
        'game_id' => $game->id,
        'vegas_spread' => -3.5,
        'spread_error' => 2.0,
        'graded_at' => now(),
    ]);

    app(PredictionEvaluationRecorder::class)->record($prediction, $game, 'nba', 5.0, 205.0);
    PredictionEvaluation::query()->update([
        'market_comparison' => [
            'market_spread' => -3.5,
            'market_spread_error' => 8.5,
        ],
    ]);

    $this->artisan('sports:rebuild-prediction-evaluations', [
        '--sport' => ['nba'],
        '--season' => $game->season,
    ])->assertSuccessful();

    $comparison = PredictionEvaluation::query()->sole()->market_comparison;

    expect((float) $comparison['market_spread'])->toBe(3.5)
        ->and((float) $comparison['market_spread_error'])->toBe(1.5);
});
