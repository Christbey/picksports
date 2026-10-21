<?php

use App\Models\NBA\Game;
use App\Models\NBA\TeamMetric;
use App\Models\NBA\TeamStat;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'America/Chicago'));
});

function calculationReviewGame(string $sport): array
{
    $ns = strtoupper($sport);
    $tc = "App\\Models\\{$ns}\\Team";
    $gc = "App\\Models\\{$ns}\\Game";
    $ac = "App\\Actions\\{$ns}\\GeneratePrediction";
    $home = $tc::factory()->create($sport === 'nba'
        ? ['location' => 'Alpha', 'name' => 'Home', 'elo_rating' => 1500]
        : ['school' => 'Alpha', 'mascot' => 'Home', 'elo_rating' => 1500]);
    $away = $tc::factory()->create($sport === 'nba'
        ? ['location' => 'Beta', 'name' => 'Away', 'elo_rating' => 1500]
        : ['school' => 'Beta', 'mascot' => 'Away', 'elo_rating' => 1500]);
    $game = $gc::factory()->create([
        'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2027, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-10-21', 'game_time' => '23:00:00', 'odds_data' => null,
    ]);

    return [$game, $home, $away, $ac];
}

it('moves toward a home favorite when blending the bookmaker spread', function (string $sport) {
    [$game, $home, $away, $ac] = calculationReviewGame($sport);
    $baseline = (new ReflectionMethod($ac, 'makePredictionData'))->invoke(app($ac), $game);
    $homeName = $sport === 'nba' ? $home->location.' '.$home->name : $home->school.' '.$home->mascot;
    $awayName = $sport === 'nba' ? $away->location.' '.$away->name : $away->school.' '.$away->mascot;
    $game->odds_data = ['bookmakers' => [['key' => 'test', 'markets' => [[
        'key' => 'spreads', 'outcomes' => [
            ['name' => $homeName, 'point' => -10, 'price' => -110],
            ['name' => $awayName, 'point' => 10, 'price' => -110],
        ],
    ]]]]];
    $market = (new ReflectionMethod($ac, 'makePredictionData'))->invoke(app($ac), $game);
    expect($market['predicted_spread'])->toBeGreaterThan($baseline['predicted_spread']);
})->with(['nba', 'cbb', 'wcbb']);

it('removes home advantage when only the neutral site flag changes', function (string $sport) {
    [$game, $home, $away, $ac] = calculationReviewGame($sport);
    $game->neutral_site = false;
    $homeCourt = (new ReflectionMethod($ac, 'makePredictionData'))->invoke(app($ac), $game);
    $game->neutral_site = true;
    $neutralCourt = (new ReflectionMethod($ac, 'makePredictionData'))->invoke(app($ac), $game);
    expect($neutralCourt['predicted_spread'])->toBeLessThan($homeCourt['predicted_spread']);
})->with(['nba', 'cbb', 'wcbb']);

it('keeps completed recent form after the future season schedule is imported', function () {
    [$game, $home, $away, $ac] = calculationReviewGame('nba');
    $past = Game::factory()->create([
        'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2027, 'status' => 'STATUS_FINAL', 'game_date' => '2026-10-19',
    ]);
    TeamStat::factory()->create([
        'game_id' => $past->id, 'team_id' => $home->id,
        'points' => 120, 'possessions' => 100,
    ]);
    TeamStat::factory()->create([
        'game_id' => $past->id, 'team_id' => $away->id,
        'points' => 100, 'possessions' => 100,
    ]);
    for ($i = 1; $i <= 10; $i++) {
        Game::factory()->create([
            'home_team_id' => $home->id, 'away_team_id' => $away->id,
            'season' => 2027, 'status' => 'STATUS_SCHEDULED',
            'game_date' => now()->addDays(10 + $i)->toDateString(),
        ]);
    }
    $method = new ReflectionMethod($ac, 'getRecentForm');
    $form = $method->invoke(app($ac), $home, 2027, $game);
    expect($form['net_rating'])->toBe(20.0);
});

it('applies the persisted NBA total injury adjustment only once', function () {
    [$game, $home, $away, $ac] = calculationReviewGame('nba');
    foreach (['recent_spread_weight', 'fatigue_spread_weight', 'injury_spread_weight', 'recent_total_weight', 'fatigue_total_weight', 'injury_total_weight'] as $key) {
        config(['nba.prediction.'.$key => 0.0]);
    }
    config(['nba.prediction.total_calibration' => [
        'range_anchor' => 220, 'range_scale' => 1, 'base_adjustment' => 0,
        'high_total_threshold' => 10000, 'high_market_total_threshold' => 10000,
    ]]);
    $metrics = [];
    foreach ([$home, $away] as $team) {
        $metrics[] = TeamMetric::factory()->create([
            'team_id' => $team->id, 'season' => 2027, 'season_type' => '2',
            'offensive_efficiency' => 110, 'defensive_efficiency' => 110,
            'net_rating' => 0, 'tempo' => 100, 'calculation_date' => now(),
            'injury_total_adjustment' => 0, 'injury_adjusted_team_rating' => 1500,
        ]);
    }
    $healthy = (new ReflectionMethod($ac, 'makePredictionData'))->invoke(app($ac), $game);
    $metrics[0]->update(['injury_total_adjustment' => -2]);
    $metrics[1]->update(['injury_total_adjustment' => -3]);
    $injured = (new ReflectionMethod($ac, 'makePredictionData'))->invoke(app($ac), $game);
    expect($injured['predicted_total'] - $healthy['predicted_total'])->toBe(-5.0);
});

it('persists the source neutral site flag for basketball games', function (string $sport) {
    [$game] = calculationReviewGame($sport);
    $game->update(['neutral_site' => true]);
    expect($game->fresh()->neutral_site)->toBeTrue();
    $game->update(['neutral_site' => false]);
    expect($game->fresh()->neutral_site)->toBeFalse();
})->with(['nba', 'cbb', 'wcbb']);
