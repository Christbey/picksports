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

    $prediction = Prediction::query()->where('game_id', $scheduledGame->id)->firstOrFail();

    expect(data_get($prediction->model_metadata, 'signal_context'))->toBeArray()
        ->and(data_get($prediction->model_metadata, 'feature_context.uses_team_ats_context'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'feature_context.uses_rolling_four_factors'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'feature_context.uses_rest_fatigue_context'))->toBeTrue();
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

test('wnba prediction calibration regresses extreme outputs without hard caps', function () {
    Queue::fake([GeneratePredictionNarrative::class]);

    config()->set('wnba.prediction.metric_spread_weight', 0.35);
    config()->set('wnba.prediction.metric_spread_min_games', 8);
    config()->set('wnba.prediction.spread_output_regression_weight', 0.08);
    config()->set('wnba.prediction.spread_to_probability_coefficient', 6.5);

    $home = Team::factory()->create([
        'location' => 'Minnesota',
        'name' => 'Lynx',
        'abbreviation' => 'MINX',
        'elo_rating' => 1900,
    ]);
    $away = Team::factory()->create([
        'location' => 'Chicago',
        'name' => 'Sky',
        'abbreviation' => 'CHIX',
        'elo_rating' => 1200,
    ]);

    foreach ([[$home, 118.0, 88.0, 30.0, 10.0], [$away, 88.0, 118.0, -30.0, 10.0]] as [$team, $offEff, $defEff, $recent, $injuryTotalAdjustment]) {
        TeamMetric::query()->create([
            'team_id' => $team->id,
            'season' => 2026,
            'wins' => 12,
            'losses' => 2,
            'offensive_efficiency' => $offEff,
            'defensive_efficiency' => $defEff,
            'net_rating' => $offEff - $defEff,
            'tempo' => 90.0,
            'strength_of_schedule' => 0.0,
            'recent_form_rating' => $recent,
            'injury_adjusted_team_rating' => $team->elo_rating,
            'injury_total_adjustment' => $injuryTotalAdjustment,
            'rest_travel_fatigue' => 0.0,
            'calculation_date' => now()->toDateString(),
        ]);
    }

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'season_type' => 2,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => now()->addDay()->toDateString(),
        'home_score' => null,
        'away_score' => null,
    ]);

    app(GeneratePrediction::class)->execute($game);

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect((float) $prediction->predicted_spread)->toBeGreaterThan(9.0)
        ->and((float) $prediction->predicted_total)->toBeGreaterThan(180.0)
        ->and((float) $prediction->confidence_score)->toBeGreaterThan(81.0)
        ->and(data_get($prediction->model_metadata, 'calibration.spread_calibration.max_predicted_spread'))->toBeNull()
        ->and(data_get($prediction->model_metadata, 'calibration.total_calibration.max_predicted_total'))->toBeNull()
        ->and(data_get($prediction->model_metadata, 'calibration.final_output_calibration.output_cap_applied'))->toBeFalse()
        ->and(data_get($prediction->model_metadata, 'season_context.sample_games'))->toBe(14)
        ->and(data_get($prediction->model_metadata, 'feature_context.uses_net_rating_spread_blend'))->toBeTrue();
});
