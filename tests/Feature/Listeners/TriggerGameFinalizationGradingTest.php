<?php

use App\Events\GameFinalized;
use App\Listeners\TriggerGameFinalizationGrading;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;

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
        'win_probability' => 0.63,
        'confidence_score' => 70,
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

    $otherPrediction->refresh();
    expect($otherPrediction->graded_at)->toBeNull();
});
