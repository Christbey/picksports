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

it('does not blend home and away venue scoring splits into a neutral site total', function (string $sport) {
    [$game, $home, $away, $action] = calculationReviewGame($sport);
    $namespace = strtoupper($sport);
    $metricClass = "App\\Models\\{$namespace}\\TeamMetric";
    foreach ([$home, $away] as $team) {
        $attributes = [
            'team_id' => $team->id, 'season' => 2027,
            'offensive_efficiency' => 100, 'defensive_efficiency' => 100,
            'net_rating' => 0, 'tempo' => $sport === 'nba' ? 100 : 70,
            'calculation_date' => now(),
        ];
        if ($sport === 'nba') {
            $attributes['season_type'] = '2';
        }
        if ($sport !== 'nba') {
            $attributes += [
                'home_offensive_efficiency' => 140, 'home_defensive_efficiency' => 140,
                'away_offensive_efficiency' => 140, 'away_defensive_efficiency' => 140,
            ];
        }
        $metricClass::factory()->create($attributes);
    }
    $game->neutral_site = true;
    config(["{$sport}.prediction.total_venue_efficiency_weight" => 0]);
    $baseline = (new ReflectionMethod($action, 'makePredictionData'))->invoke(app($action), $game);
    config(["{$sport}.prediction.total_venue_efficiency_weight" => 0.9]);
    $weighted = (new ReflectionMethod($action, 'makePredictionData'))->invoke(app($action), $game);
    expect($weighted['predicted_total'])->toBe($baseline['predicted_total'])
        ->and($weighted['model_metadata']['total_model']['venue_home_score_component'])->toBeNull()
        ->and($weighted['model_metadata']['total_model']['venue_away_score_component'])->toBeNull();
    $game->neutral_site = false;
    $homeVenue = (new ReflectionMethod($action, 'makePredictionData'))->invoke(app($action), $game);
    config(["{$sport}.prediction.total_venue_efficiency_weight" => 0]);
    $withoutVenue = (new ReflectionMethod($action, 'makePredictionData'))->invoke(app($action), $game);
    expect($homeVenue['predicted_total'])->not->toBe($withoutVenue['predicted_total']);
})->with(['nba', 'cbb', 'wcbb']);

it('does not let later completed games change an earlier forecast raw stat inputs', function (string $sport) {
    [$game, $home, $away, $action] = calculationReviewGame($sport);
    $namespace = strtoupper($sport);
    $gameClass = "App\\Models\\{$namespace}\\Game";
    $statClass = "App\\Models\\{$namespace}\\TeamStat";
    $prior = $gameClass::factory()->create([
        'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2027, 'status' => 'STATUS_FINAL', 'game_date' => '2026-10-19',
    ]);
    foreach ([[$home, 110], [$away, 100]] as [$team, $points]) {
        $statClass::factory()->create([
            'game_id' => $prior->id, 'team_id' => $team->id, 'points' => $points, 'possessions' => 100,
        ]);
    }
    $baseline = (new ReflectionMethod($action, 'makePredictionData'))->invoke(app($action), $game);
    $later = $gameClass::factory()->create([
        'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2027, 'season_type' => 2, 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-23',
    ]);
    $statClass::factory()->create([
        'game_id' => $later->id, 'team_id' => $home->id,
        'points' => 140, 'possessions' => 100, 'turnovers' => 1, 'rebounds' => 60,
    ]);
    $statClass::factory()->create([
        'game_id' => $later->id, 'team_id' => $away->id,
        'points' => 80, 'possessions' => 100, 'turnovers' => 30, 'rebounds' => 20,
    ]);
    $after = (new ReflectionMethod($action, 'makePredictionData'))->invoke(app($action), $game);
    expect($after['predicted_spread'])->toBe($baseline['predicted_spread'])
        ->and($after['predicted_total'])->toBe($baseline['predicted_total']);
})->with(['nba', 'cbb', 'wcbb']);

it('does not classify a neutral site history row as a home venue sample', function (string $sport) {
    [$game, $home, $away, $action] = calculationReviewGame($sport);
    $namespace = strtoupper($sport);
    $gameClass = "App\\Models\\{$namespace}\\Game";
    $statClass = "App\\Models\\{$namespace}\\TeamStat";
    $method = new ReflectionMethod($action, 'getVenueEfficiency');
    $before = $method->invoke(app($action), $home, 2027, 'home', $game);
    $past = $gameClass::factory()->create([
        'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2027, 'status' => 'STATUS_FINAL', 'neutral_site' => true,
        'game_date' => '2026-10-19',
    ]);
    $statClass::factory()->create([
        'game_id' => $past->id, 'team_id' => $home->id, 'points' => 140, 'possessions' => 100,
    ]);
    $statClass::factory()->create([
        'game_id' => $past->id, 'team_id' => $away->id, 'points' => 80, 'possessions' => 100,
    ]);
    expect($method->invoke(app($action), $home, 2027, 'home', $game))->toBe($before);
    $form = (new ReflectionMethod($action, 'getRecentForm'))->invoke(app($action), $home, 2027, $game);
    expect($form['net_rating'])->toBe(60.0);
    $past->update(['neutral_site' => false]);
    expect($method->invoke(app($action), $home, 2027, 'home', $game)['off'])->toBe(140.0);
})->with(['nba', 'cbb', 'wcbb']);
