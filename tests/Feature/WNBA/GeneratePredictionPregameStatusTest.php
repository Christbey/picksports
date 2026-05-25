<?php

use App\Actions\WNBA\GeneratePrediction;
use App\Jobs\Predictions\GeneratePredictionNarrative;
use App\Models\WNBA\Game;
use App\Models\WNBA\Prediction;
use App\Models\WNBA\Team;
use App\Models\WNBA\TeamMetric;
use Illuminate\Support\Facades\Queue;

function createWnbaPredictionInputs(string $status = 'STATUS_SCHEDULED'): Game
{
    static $gameNumber = 0;
    $gameNumber++;

    $home = Team::factory()->create([
        'location' => 'Las Vegas',
        'name' => 'Aces',
        'abbreviation' => 'LV'.$gameNumber,
        'elo_rating' => 1560,
    ]);
    $away = Team::factory()->create([
        'location' => 'New York',
        'name' => 'Liberty',
        'abbreviation' => 'NY'.$gameNumber,
        'elo_rating' => 1510,
    ]);

    foreach ([[$home, 104.0, 96.0], [$away, 101.0, 98.0]] as [$team, $offEff, $defEff]) {
        TeamMetric::query()->create([
            'team_id' => $team->id,
            'season' => 2026,
            'wins' => 3,
            'losses' => 1,
            'offensive_efficiency' => $offEff,
            'defensive_efficiency' => $defEff,
            'net_rating' => $offEff - $defEff,
            'tempo' => 84.0,
            'strength_of_schedule' => 0.0,
            'recent_form_rating' => 2.0,
            'injury_adjusted_team_rating' => $team->elo_rating,
            'injury_total_adjustment' => 0.0,
            'rest_travel_fatigue' => 0.0,
            'calculation_date' => now()->toDateString(),
        ]);
    }

    return Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'season_type' => 2,
        'status' => $status,
        'game_date' => now()->addDay()->toDateString(),
        'home_score' => null,
        'away_score' => null,
    ]);
}

test('wnba prediction action only creates pregame predictions', function () {
    Queue::fake([GeneratePredictionNarrative::class]);

    $scheduledGame = createWnbaPredictionInputs();
    $inProgressGame = createWnbaPredictionInputs('STATUS_IN_PROGRESS');

    $action = app(GeneratePrediction::class);

    expect($action->execute($scheduledGame))->not->toBeNull()
        ->and($action->execute($inProgressGame))->toBeNull()
        ->and(Prediction::query()->where('game_id', $scheduledGame->id)->exists())->toBeTrue()
        ->and(Prediction::query()->where('game_id', $inProgressGame->id)->exists())->toBeFalse();
});

test('wnba generate predictions command skips in-progress games', function () {
    Queue::fake([GeneratePredictionNarrative::class]);

    $scheduledGame = createWnbaPredictionInputs();
    $inProgressGame = createWnbaPredictionInputs('STATUS_IN_PROGRESS');

    $this->artisan('wnba:generate-predictions', ['--season' => 2026])
        ->expectsOutputToContain('Generating predictions for 1 games')
        ->assertSuccessful();

    expect(Prediction::query()->where('game_id', $scheduledGame->id)->exists())->toBeTrue()
        ->and(Prediction::query()->where('game_id', $inProgressGame->id)->exists())->toBeFalse();
});
