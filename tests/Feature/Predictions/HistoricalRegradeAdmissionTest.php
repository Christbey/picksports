<?php

use Illuminate\Support\Facades\Artisan;

it('refuses legacy historical grade clearing before generation or grading', function (string $sport) {
    $namespace = strtoupper($sport);
    $gameClass = 'App\\Models\\'.$namespace.'\\Game';
    $teamClass = 'App\\Models\\'.$namespace.'\\Team';
    $predictionClass = 'App\\Models\\'.$namespace.'\\Prediction';
    $game = $gameClass::factory()->create([
        'home_team_id' => $teamClass::factory(), 'away_team_id' => $teamClass::factory(),
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL',
        'home_score' => 28, 'away_score' => 21,
        'game_date' => '2026-08-10', 'game_time' => '00:00:00',
    ]);
    $prediction = $predictionClass::query()->create([
        'game_id' => $game->id, 'predicted_spread' => 5.5, 'predicted_total' => 49,
        'win_probability' => 0.65, 'confidence_score' => 65,
        'actual_spread' => 7, 'actual_total' => 49, 'spread_error' => 1.5,
        'total_error' => 0, 'winner_correct' => true, 'graded_at' => now()->subDay(),
    ]);
    $before = $prediction->fresh()->getRawOriginal();
    $calls = 0;
    $generatorClass = 'App\\Actions\\'.$namespace.'\\'.($sport === 'nfl' ? 'GeneratePredictionFromHistoricalElo' : 'GeneratePrediction');
    $generator = Mockery::mock($generatorClass);
    $generator->shouldReceive($sport === 'nfl' ? 'execute' : 'executeHistorical')
        ->andReturnUsing(function () use (&$calls, $sport) {
            $calls++;

            return $sport === 'nfl' ? 'unchanged' : null;
        });
    app()->instance($generatorClass, $generator);
    $graderClass = 'App\\Actions\\'.$namespace.'\\GradePredictions';
    $grader = Mockery::mock($graderClass);
    $grader->shouldReceive('executeForGameIds')->andReturnUsing(function () use (&$calls): array {
        $calls++;

        return ['graded' => 0];
    });
    app()->instance($graderClass, $grader);

    $exit = Artisan::call($sport.':backfill-historical-predictions', ['--season' => 2026, '--regrade' => true]);

    expect($prediction->fresh()->getRawOriginal())->toBe($before)
        ->and($calls)->toBe(0)
        ->and($exit)->toBe(1)
        ->and(Artisan::output())->toContain('sports:repair-legacy-prediction-grades');
})->with(['nfl', 'wnba']);
