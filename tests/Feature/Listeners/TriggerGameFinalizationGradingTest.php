<?php

use App\Events\GameFinalized;
use App\Listeners\TriggerGameFinalizationGrading;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\PredictionEvaluation;
use Illuminate\Contracts\Queue\ShouldBeUnique;

it('queues finalization grading uniquely per sport and game', function () {
    $listener = app(TriggerGameFinalizationGrading::class);
    $event = new GameFinalized(
        sport: 'mlb',
        gameId: 720,
        season: 2026,
        gameModelClass: App\Models\MLB\Game::class,
    );

    expect($listener)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($listener->uniqueFor)->toBe(86400)
        ->and($listener->uniqueId($event))->toBe('mlb:720')
        ->and($listener->middleware($event))->toHaveCount(1);
});

it('grades nba predictions when nba game finalized event is handled', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'home_score' => 108,
        'away_score' => 100,
    ]);

    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => 6.5,
        'predicted_total' => 210.5,
        'win_probability' => 0.58,
        'confidence_score' => 70,
        'model_metadata' => [
            'win_probability_calibration' => [
                'enabled' => true,
                'active_source' => 'calibrated',
                'baseline_win_probability' => 0.63,
                'calibrated_win_probability' => 0.58,
            ],
        ],
        'graded_at' => null,
    ]);

    $otherGame = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'home_score' => 99,
        'away_score' => 90,
    ]);

    $otherPrediction = Prediction::query()->create([
        'game_id' => $otherGame->id,
        'predicted_spread' => 2.5,
        'predicted_total' => 197.5,
        'win_probability' => 0.54,
        'confidence_score' => 58,
        'graded_at' => null,
    ]);

    app(TriggerGameFinalizationGrading::class)->handle(
        new GameFinalized(
            sport: 'nba',
            gameId: $game->id,
            season: 2026,
            gameModelClass: Game::class,
        )
    );

    $prediction->refresh();

    expect($prediction->graded_at)->not->toBeNull();
    expect((float) $prediction->actual_spread)->toBe(8.0);
    expect((float) $prediction->actual_total)->toBe(208.0);
    expect($prediction->winner_correct)->toBeTrue();

    $evaluation = PredictionEvaluation::query()
        ->where('prediction_table', 'nba_predictions')
        ->where('prediction_id', $prediction->id)
        ->first();

    expect($evaluation)->not->toBeNull()
        ->and((float) $evaluation->actuals['actual_spread'])->toBe(8.0)
        ->and($evaluation->errors['winner_correct'])->toBeTrue()
        ->and(round((float) $evaluation->errors['baseline_brier_score'], 4))->toBe(0.1369)
        ->and(round((float) $evaluation->errors['calibrated_brier_score'], 4))->toBe(0.1764)
        ->and($evaluation->errors['calibration_beats_baseline_brier'])->toBeFalse()
        ->and($evaluation->errors['active_win_probability_source'])->toBe('calibrated');

    $otherPrediction->refresh();
    expect($otherPrediction->graded_at)->toBeNull();
});
