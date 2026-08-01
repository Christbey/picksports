<?php

use App\Actions\WNBA\GradePredictions;
use App\Models\WNBA\Game;
use App\Models\WNBA\Prediction;
use App\Models\WNBA\Team;
use Illuminate\Support\Carbon;

function createFinalWnbaPrediction(
    int $homeScore,
    int $awayScore,
    float $predictedSpread,
    float $predictedTotal,
    float $confidence = 62.0
): Prediction {
    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'season_type' => 2,
        'status' => 'STATUS_FINAL',
        'home_score' => $homeScore,
        'away_score' => $awayScore,
        'game_date' => now()->subDay()->toDateString(),
    ]);

    return Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => $predictedSpread,
        'predicted_total' => $predictedTotal,
        'win_probability' => $predictedSpread >= 0 ? 0.62 : 0.38,
        'confidence_score' => $confidence,
    ]);
}

test('wnba grading uses positive spread for home edge and negative spread for away edge', function () {
    $homePrediction = createFinalWnbaPrediction(
        homeScore: 84,
        awayScore: 76,
        predictedSpread: 5.5,
        predictedTotal: 161.0,
        confidence: 68.0
    );
    $awayPrediction = createFinalWnbaPrediction(
        homeScore: 72,
        awayScore: 80,
        predictedSpread: -4.0,
        predictedTotal: 150.0,
        confidence: 64.0
    );
    $wrongPrediction = createFinalWnbaPrediction(
        homeScore: 78,
        awayScore: 84,
        predictedSpread: 3.0,
        predictedTotal: 160.0,
        confidence: 58.0
    );

    $results = app(GradePredictions::class)->execute(2026);

    expect($results['graded'])->toBe(3)
        ->and($results['winner_accuracy'])->toBe(66.7);

    $homePrediction->refresh();
    $awayPrediction->refresh();
    $wrongPrediction->refresh();

    expect((float) $homePrediction->actual_spread)->toBe(8.0)
        ->and((float) $homePrediction->spread_error)->toBe(2.5)
        ->and((float) $homePrediction->actual_total)->toBe(160.0)
        ->and((float) $homePrediction->total_error)->toBe(1.0)
        ->and($homePrediction->winner_correct)->toBeTrue()
        ->and((float) $awayPrediction->actual_spread)->toBe(-8.0)
        ->and((float) $awayPrediction->spread_error)->toBe(4.0)
        ->and($awayPrediction->winner_correct)->toBeTrue()
        ->and($wrongPrediction->winner_correct)->toBeFalse();
});

test('wnba calibration report exposes accuracy bias and confidence buckets', function () {
    createFinalWnbaPrediction(86, 80, 4.0, 164.0, 54.0);
    createFinalWnbaPrediction(77, 84, -5.0, 158.0, 66.0);

    app(GradePredictions::class)->execute(2026);

    $this->artisan('wnba:report-calibration', ['--season' => 2026])
        ->expectsOutputToContain('WNBA Prediction Calibration Report')
        ->expectsOutputToContain('Winner accuracy')
        ->expectsOutputToContain('Spread bias')
        ->expectsOutputToContain('Confidence Buckets')
        ->assertSuccessful();
});

test('wnba grading skips games marked final before the post tip window', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:00', 'UTC'));

    try {
        $home = Team::factory()->create();
        $away = Team::factory()->create();

        $game = Game::factory()->create([
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'season' => 2026,
            'season_type' => 2,
            'status' => 'STATUS_FINAL',
            'home_score' => 98,
            'away_score' => 112,
            'game_date' => '2026-08-01',
            'game_time' => '02:00:00',
        ]);

        $prediction = Prediction::query()->create([
            'game_id' => $game->id,
            'predicted_spread' => -4.0,
            'predicted_total' => 160.0,
            'win_probability' => 0.58,
            'confidence_score' => 61.0,
        ]);

        $results = app(GradePredictions::class)->execute(2026);

        expect($results['graded'])->toBe(0)
            ->and($prediction->refresh()->graded_at)->toBeNull()
            ->and($prediction->actual_spread)->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

test('wnba grading allows finals after the post tip window', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-01 04:00:00', 'UTC'));

    try {
        $home = Team::factory()->create();
        $away = Team::factory()->create();

        $game = Game::factory()->create([
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'season' => 2026,
            'season_type' => 2,
            'status' => 'STATUS_FINAL',
            'home_score' => 98,
            'away_score' => 112,
            'game_date' => '2026-08-01',
            'game_time' => '02:00:00',
        ]);

        $prediction = Prediction::query()->create([
            'game_id' => $game->id,
            'predicted_spread' => -4.0,
            'predicted_total' => 160.0,
            'win_probability' => 0.58,
            'confidence_score' => 61.0,
        ]);

        $results = app(GradePredictions::class)->execute(2026);

        expect($results['graded'])->toBe(1)
            ->and((float) $prediction->refresh()->actual_spread)->toBe(-14.0)
            ->and($prediction->graded_at)->not->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});
