<?php

use App\Actions\CBB\GradePredictions;
use App\Models\CBB\Game;
use App\Models\CBB\Prediction;

uses()->group('cbb');

it('stores ats grading fields using the stored vegas spread snapshot', function () {
    $game = Game::factory()->create([
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'home_score' => 80,
        'away_score' => 70,
    ]);

    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => 7.5,
        'predicted_total' => 148.0,
        'win_probability' => 0.72,
        'confidence_score' => 72.0,
        'vegas_spread' => -4.5,
    ]);

    $results = app(GradePredictions::class)->executeForGame($game->id);

    $prediction->refresh();

    expect($results['graded'])->toBe(1)
        ->and((float) $prediction->actual_spread)->toBe(10.0)
        ->and($prediction->ats_pick_side)->toBe('home')
        ->and($prediction->ats_pick_result)->toBe('win')
        ->and((float) $prediction->ats_pick_edge)->toBe(3.0);
});
