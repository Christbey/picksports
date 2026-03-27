<?php

use App\Actions\NBA\CalculateBettingValue;
use App\Actions\NBA\GeneratePrediction;
use App\Models\NBA\DepthChartEntry;
use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerInjury;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;
use App\Models\NBA\TeamStat;
use App\Models\PredictionFeatureSnapshot;

uses()->group('nba', 'predictions');

beforeEach(function () {
    $this->homeTeam = Team::factory()->create(['elo_rating' => 1550]);
    $this->awayTeam = Team::factory()->create(['elo_rating' => 1450]);
});

it('generates prediction for an upcoming game', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->toBeInstanceOf(Prediction::class)
        ->game_id->toBe($game->id);

    expect((float) $prediction->home_elo)->toBe(1550.0);
    expect((float) $prediction->away_elo)->toBe(1450.0);
    expect((float) $prediction->predicted_spread)->toBeGreaterThan(0); // Home team favored
    expect((float) $prediction->win_probability)->toBeGreaterThan(0.5); // Home team more likely to win
    expect($prediction->model_version)->toBe('rules-v1')
        ->and($prediction->feature_version)->toBe('core-v1')
        ->and($prediction->blend_version)->toBe('baseline-v1');

    $snapshot = PredictionFeatureSnapshot::query()
        ->where('prediction_table', 'nba_predictions')
        ->where('prediction_id', $prediction->id)
        ->first();

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->sport)->toBe('nba')
        ->and($snapshot->outputs['predicted_spread'])->toBeNumeric()
        ->and($snapshot->outputs['blended_predicted_spread'])->toBeNumeric();
});

it('stores baseline and challenger win probability side by side when calibration is enabled', function () {
    $artifactPath = storage_path('app/ml/models/test_inline_nba_calibration_model.json');
    @mkdir(dirname($artifactPath), 0777, true);
    file_put_contents($artifactPath, json_encode([
        'model_type' => 'nba_win_probability_platt_calibration',
        'alpha' => 1.0,
        'beta' => -0.4,
    ], JSON_PRETTY_PRINT));

    config()->set('nba.prediction.win_probability_calibration.enabled', true);
    config()->set('nba.prediction.win_probability_calibration.apply_to_live_output', false);
    config()->set('nba.prediction.win_probability_calibration.artifact_path', $artifactPath);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $prediction = app(GeneratePrediction::class)->execute($game);

    $snapshot = PredictionFeatureSnapshot::query()
        ->where('prediction_table', 'nba_predictions')
        ->where('prediction_id', $prediction->id)
        ->first();

    expect($prediction)->not->toBeNull()
        ->and(data_get($prediction->model_metadata, 'win_probability_calibration.enabled'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'win_probability_calibration.active_source'))->toBe('baseline')
        ->and(data_get($prediction->model_metadata, 'win_probability_calibration.reason'))->toBe('calibrated')
        ->and((float) data_get($prediction->model_metadata, 'win_probability_calibration.baseline_win_probability'))->toBe((float) $prediction->win_probability)
        ->and((float) data_get($prediction->model_metadata, 'win_probability_calibration.calibrated_win_probability'))->not->toBe((float) $prediction->win_probability)
        ->and($snapshot)->not->toBeNull()
        ->and($snapshot->outputs)->toHaveKeys([
            'baseline_win_probability',
            'calibrated_win_probability',
            'active_win_probability_source',
        ])
        ->and($snapshot->outputs['active_win_probability_source'])->toBe('baseline');
});

it('can promote calibrated win probability to the live output behind config', function () {
    $artifactPath = storage_path('app/ml/models/test_inline_nba_calibration_model_apply.json');
    @mkdir(dirname($artifactPath), 0777, true);
    file_put_contents($artifactPath, json_encode([
        'model_type' => 'nba_win_probability_platt_calibration',
        'alpha' => 1.0,
        'beta' => -0.5,
    ], JSON_PRETTY_PRINT));

    config()->set('nba.prediction.win_probability_calibration.enabled', true);
    config()->set('nba.prediction.win_probability_calibration.apply_to_live_output', true);
    config()->set('nba.prediction.win_probability_calibration.artifact_path', $artifactPath);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $prediction = app(GeneratePrediction::class)->execute($game);

    expect($prediction)->not->toBeNull()
        ->and(data_get($prediction->model_metadata, 'win_probability_calibration.active_source'))->toBe('calibrated')
        ->and(round((float) data_get($prediction->model_metadata, 'win_probability_calibration.calibrated_win_probability'), 3))->toBe((float) $prediction->win_probability)
        ->and((float) data_get($prediction->model_metadata, 'win_probability_calibration.baseline_win_probability'))->not->toBe((float) $prediction->win_probability);
});

it('does not generate prediction for completed game', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 110,
        'away_score' => 100,
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->toBeNull();
});

it('uses team metrics when available', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    TeamMetric::create([
        'team_id' => $this->homeTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 115.0,
        'defensive_efficiency' => 105.0,
        'net_rating' => 10.0,
        'tempo' => 100.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->awayTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 108.0,
        'defensive_efficiency' => 112.0,
        'net_rating' => -4.0,
        'tempo' => 98.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->not->toBeNull();
    expect((float) $prediction->home_off_eff)->toBe(115.0);
    expect((float) $prediction->home_def_eff)->toBe(105.0);
    expect((float) $prediction->away_off_eff)->toBe(108.0);
    expect((float) $prediction->away_def_eff)->toBe(112.0);
    expect((float) $prediction->predicted_total)->toBeGreaterThan(200); // Should be realistic NBA total
});

it('uses default metrics when team metrics unavailable', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->not->toBeNull();
    expect((float) $prediction->home_off_eff)->toBe(110.0); // League average
    expect((float) $prediction->home_def_eff)->toBe(110.0);
    expect((float) $prediction->away_off_eff)->toBe(110.0);
    expect((float) $prediction->away_def_eff)->toBe(110.0);
});

it('favors home team with home court advantage', function () {
    // Create evenly matched teams
    $homeTeam = Team::factory()->create(['elo_rating' => 1500]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1500]);

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    // Even teams, but home should be favored due to home court advantage
    expect($prediction)->not->toBeNull()
        ->predicted_spread->toBeGreaterThan(0) // Positive = home favored
        ->win_probability->toBeGreaterThan(0.5); // Home team more likely to win
});

it('updates existing prediction instead of creating duplicate', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $action = new GeneratePrediction;

    // First prediction
    $prediction1 = $action->execute($game);
    expect(Prediction::count())->toBe(1);
    $firstHomeElo = (float) $prediction1->home_elo;

    // Update team Elo
    $this->homeTeam->update(['elo_rating' => 1600]);
    $game->refresh(); // Refresh to get updated team relationship

    // Second prediction should update, not create new
    $prediction2 = $action->execute($game);

    expect(Prediction::count())->toBe(1);
    expect($prediction2->id)->toBe($prediction1->id);
    expect((float) $prediction2->home_elo)->toBe(1600.0);
    expect((float) $prediction2->home_elo)->not->toBe($firstHomeElo);
});

it('calculates higher win probability for bigger spread', function () {
    // Home team much better
    $strongHome = Team::factory()->create(['elo_rating' => 1700]);
    $weakAway = Team::factory()->create(['elo_rating' => 1400]);

    $game1 = Game::factory()->create([
        'home_team_id' => $strongHome->id,
        'away_team_id' => $weakAway->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    // Evenly matched teams
    $evenHome = Team::factory()->create(['elo_rating' => 1500]);
    $evenAway = Team::factory()->create(['elo_rating' => 1500]);

    $game2 = Game::factory()->create([
        'home_team_id' => $evenHome->id,
        'away_team_id' => $evenAway->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $action = new GeneratePrediction;

    $prediction1 = $action->execute($game1);
    $prediction2 = $action->execute($game2);

    // Bigger Elo diff should have bigger spread and higher win probability
    expect($prediction1->predicted_spread)->toBeGreaterThan($prediction2->predicted_spread)
        ->and($prediction1->win_probability)->toBeGreaterThan($prediction2->win_probability);
});

it('calculates confidence based on win probability', function () {
    // Big Elo gap → high win probability → high confidence
    $strongHome = Team::factory()->create(['elo_rating' => 1700]);
    $weakAway = Team::factory()->create(['elo_rating' => 1300]);

    $game1 = Game::factory()->create([
        'home_team_id' => $strongHome->id,
        'away_team_id' => $weakAway->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    // Even teams → ~50% win probability → lower confidence
    $evenHome = Team::factory()->create(['elo_rating' => 1500]);
    $evenAway = Team::factory()->create(['elo_rating' => 1500]);

    $game2 = Game::factory()->create([
        'home_team_id' => $evenHome->id,
        'away_team_id' => $evenAway->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $action = new GeneratePrediction;

    $prediction1 = $action->execute($game1);
    $prediction2 = $action->execute($game2);

    // Mismatched game should have higher confidence than even matchup
    expect((float) $prediction1->confidence_score)->toBeGreaterThan((float) $prediction2->confidence_score);

    // Confidence should be between 50 and 100
    expect((float) $prediction1->confidence_score)->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(100);
    expect((float) $prediction2->confidence_score)->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(100);

    // Confidence should equal max(wp, 1-wp) * 100
    $wp1 = (float) $prediction1->win_probability;
    $expectedConfidence1 = round(max($wp1, 1 - $wp1) * 100, 2);
    expect((float) $prediction1->confidence_score)->toBe($expectedConfidence1);
});

it('stores spread components in prediction metadata', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    TeamMetric::create([
        'team_id' => $this->homeTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 115.0,
        'defensive_efficiency' => 105.0,
        'net_rating' => 10.0,
        'tempo' => 100.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->awayTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 108.0,
        'defensive_efficiency' => 112.0,
        'net_rating' => -4.0,
        'tempo' => 98.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    // Spread components should be populated
    expect($prediction->elo_spread_component)->not->toBeNull();
    expect($prediction->efficiency_spread_component)->not->toBeNull();
    expect($prediction->form_spread_component)->not->toBeNull();

    // ELO component: home favored (1550 + 100 - 1450) / 28 ≈ 7.14
    expect((float) $prediction->elo_spread_component)->toBeGreaterThan(0);

    // Efficiency component: home has +10 net, away has -4 → should be positive
    expect((float) $prediction->efficiency_spread_component)->toBeGreaterThan(0);
});

it('incorporates recent form from completed games', function () {
    // Create some completed games with team stats for the home team
    $completedGames = collect();
    for ($i = 0; $i < 5; $i++) {
        $opponent = Team::factory()->create();
        $completedGame = Game::factory()->create([
            'home_team_id' => $this->homeTeam->id,
            'away_team_id' => $opponent->id,
            'status' => 'STATUS_FINAL',
            'season' => 2026,
            'game_date' => now()->subDays(10 - $i),
            'home_score' => 115,
            'away_score' => 100,
        ]);

        TeamStat::factory()->create([
            'team_id' => $this->homeTeam->id,
            'game_id' => $completedGame->id,
            'team_type' => 'home',
            'points' => 115,
            'possessions' => 100,
            'turnovers' => 12,
            'rebounds' => 45,
        ]);

        TeamStat::factory()->create([
            'team_id' => $opponent->id,
            'game_id' => $completedGame->id,
            'team_type' => 'away',
            'points' => 100,
            'possessions' => 100,
            'turnovers' => 15,
            'rebounds' => 40,
        ]);
    }

    $upcomingGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'game_date' => now()->addDay(),
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($upcomingGame);

    expect($prediction)->not->toBeNull();
    // Home team has strong recent form (115 off eff), so form component should be positive
    expect((float) $prediction->home_recent_form)->toBeGreaterThan(0);
    expect((float) $prediction->form_spread_component)->not->toBeNull();
});

it('applies rest day advantage when home team is rested', function () {
    $opponent = Team::factory()->create();

    // Home team last played 3 days ago
    $homeLastGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $opponent->id,
        'status' => 'STATUS_FINAL',
        'season' => 2026,
        'game_date' => now()->subDays(3),
    ]);

    // Away team last played yesterday (back-to-back)
    $awayLastGame = Game::factory()->create([
        'home_team_id' => $opponent->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_FINAL',
        'season' => 2026,
        'game_date' => now()->subDay(),
    ]);

    $upcomingGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'game_date' => now()->addDay(),
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($upcomingGame);

    expect($prediction)->not->toBeNull();
    expect($prediction->rest_days_home)->toBeGreaterThan($prediction->rest_days_away);

    // Compare with same matchup but no rest advantage
    $evenGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2025, // Different season so no prior games
        'game_date' => now()->addDays(2),
    ]);

    $evenPrediction = $action->execute($evenGame);

    // Rested home team should be at least as favorable after 1-decimal spread rounding.
    expect((float) $prediction->predicted_spread)->toBeGreaterThanOrEqual((float) $evenPrediction->predicted_spread);
});

it('blends vegas spread when odds data available', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'odds_data' => [
            'bookmakers' => [
                [
                    'key' => 'draftkings',
                    'title' => 'DraftKings',
                    'markets' => [
                        [
                            'key' => 'spreads',
                            'outcomes' => [
                                ['name' => $this->homeTeam->location, 'point' => -5.5, 'price' => -110],
                                ['name' => $this->awayTeam->location, 'point' => 5.5, 'price' => -110],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->not->toBeNull();
    expect($prediction->vegas_spread)->not->toBeNull();
    expect((float) $prediction->vegas_spread)->not->toBe(0.0);
    expect(data_get($prediction->model_metadata, 'market_context.has_spreads'))->toBeTrue();
});

it('does not infer vegas spread from moneyline-only odds data', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'odds_data' => [
            'bookmakers' => [
                [
                    'key' => 'draftkings',
                    'title' => 'DraftKings',
                    'markets' => [
                        [
                            'key' => 'h2h',
                            'outcomes' => [
                                ['name' => $this->homeTeam->location, 'price' => -200],
                                ['name' => $this->awayTeam->location, 'price' => 170],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $prediction = (new GeneratePrediction)->execute($game);

    expect($prediction)->not->toBeNull()
        ->and($prediction->vegas_spread)->toBeNull()
        ->and(data_get($prediction->model_metadata, 'market_context.bookmaker'))->toBe('draftkings')
        ->and(data_get($prediction->model_metadata, 'market_context.has_h2h'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'market_context.has_spreads'))->toBeFalse()
        ->and(data_get($prediction->model_metadata, 'market_context.has_totals'))->toBeFalse();
});

it('generates prediction without vegas spread when no odds data', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'odds_data' => null,
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->not->toBeNull();
    expect($prediction->vegas_spread)->toBeNull();
});

it('stores turnover and rebound adjustments', function () {
    // Create completed games with stats for both teams
    for ($i = 0; $i < 3; $i++) {
        $opponent = Team::factory()->create();

        $completedGame = Game::factory()->create([
            'home_team_id' => $this->homeTeam->id,
            'away_team_id' => $opponent->id,
            'status' => 'STATUS_FINAL',
            'season' => 2026,
            'game_date' => now()->subDays(5 - $i),
        ]);

        TeamStat::factory()->create([
            'team_id' => $this->homeTeam->id,
            'game_id' => $completedGame->id,
            'turnovers' => 10, // Low turnovers
            'rebounds' => 50, // High rebounds
        ]);

        TeamStat::factory()->create([
            'team_id' => $opponent->id,
            'game_id' => $completedGame->id,
            'turnovers' => 18, // High turnovers (forced by home team)
            'rebounds' => 38, // Low rebounds
        ]);
    }

    $upcomingGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'game_date' => now()->addDay(),
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($upcomingGame);

    expect($prediction)->not->toBeNull();
    // Home team has positive TO diff and rebound margin
    expect((float) $prediction->turnover_diff_adj)->toBeGreaterThan(0);
    expect((float) $prediction->rebound_margin_adj)->toBeGreaterThan(0);
});

it('ensemble weights sum to one', function () {
    $config = config('nba.prediction');
    $sum = $config['elo_weight'] + $config['efficiency_weight'] + $config['form_weight'];

    expect($sum)->toBe(1.0);
});

it('falls back gracefully when no recent form data exists', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    // No completed games exist — form should fall back to defaults
    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->not->toBeNull();
    // With no form data, form component uses default (0 net rating + home court)
    expect((float) $prediction->form_spread_component)->toBe(
        config('nba.prediction.home_court_points')
    );
});

it('falls back to prior season team metrics when current season metrics are unavailable', function () {
    config()->set('nba.prediction.use_previous_season_metrics_fallback', true);

    TeamMetric::create([
        'team_id' => $this->homeTeam->id,
        'season' => 2025,
        'offensive_efficiency' => 117.0,
        'defensive_efficiency' => 108.0,
        'net_rating' => 9.0,
        'tempo' => 99.0,
        'strength_of_schedule' => 1502.0,
        'calculation_date' => now()->subYear()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->awayTeam->id,
        'season' => 2025,
        'offensive_efficiency' => 109.0,
        'defensive_efficiency' => 113.0,
        'net_rating' => -4.0,
        'tempo' => 97.0,
        'strength_of_schedule' => 1498.0,
        'calculation_date' => now()->subYear()->toDateString(),
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $prediction = (new GeneratePrediction)->execute($game);

    expect($prediction)->not->toBeNull()
        ->and((float) $prediction->home_off_eff)->toBe(117.0)
        ->and((float) $prediction->away_def_eff)->toBe(113.0);
});

it('does not double count raw injury penalties when persisted injury-adjusted ratings exist', function () {
    config()->set('nba.prediction.injury_spread_weight', 0.03);
    config()->set('nba.prediction.injury_total_weight', 0.015);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    TeamMetric::create([
        'team_id' => $this->homeTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 114.0,
        'defensive_efficiency' => 108.0,
        'net_rating' => 6.0,
        'tempo' => 99.0,
        'strength_of_schedule' => 1500.0,
        'injury_adjusted_team_rating' => 1540.0,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->awayTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 110.0,
        'defensive_efficiency' => 111.0,
        'net_rating' => -1.0,
        'tempo' => 98.0,
        'strength_of_schedule' => 1500.0,
        'injury_adjusted_team_rating' => 1450.0,
        'calculation_date' => now()->toDateString(),
    ]);

    $baselinePrediction = (new GeneratePrediction)->execute($game);

    $player = Player::factory()->create(['team_id' => $this->homeTeam->id]);
    PlayerInjury::query()->create([
        'player_id' => $player->id,
        'team_id' => $this->homeTeam->id,
        'injury_key' => 'test-ankle',
        'status' => 'Out',
        'detail' => 'Test injury',
        'type' => 'Ankle',
        'injury_date' => now()->toDateString(),
        'is_active' => true,
    ]);

    $injuryPrediction = (new GeneratePrediction)->execute($game->fresh());

    expect($injuryPrediction)->not->toBeNull()
        ->and((float) $injuryPrediction->predicted_spread)->toBe((float) $baselinePrediction->predicted_spread)
        ->and((float) $injuryPrediction->predicted_total)->toBe((float) $baselinePrediction->predicted_total)
        ->and((float) $injuryPrediction->injury_spread_adj)->toBe(0.0)
        ->and((float) $injuryPrediction->injury_total_adj)->toBe(0.0)
        ->and(data_get($injuryPrediction->model_metadata, 'injury_model_source'))->toBe('persisted_team_rating')
        ->and($injuryPrediction->home_injuries_out)->toBe(1);
});

it('weights nba starter injuries more heavily than reserve injuries when depth chart data exists', function () {
    config()->set('nba.prediction.depth_chart.starter_multiplier', 2.0);
    config()->set('nba.prediction.depth_chart.rotation_multiplier', 1.0);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $starter = Player::factory()->create(['team_id' => $this->homeTeam->id, 'position' => 'G']);
    $reserve = Player::factory()->create(['team_id' => $this->homeTeam->id, 'position' => 'G']);

    DepthChartEntry::query()->create([
        'team_id' => $this->homeTeam->id,
        'player_id' => $starter->id,
        'season' => 2026,
        'position_slot_key' => 'pg',
        'position_code' => 'PG',
        'position_name' => 'Point Guard',
        'position_display_name' => 'Point Guard',
        'espn_athlete_id' => $starter->espn_id,
        'depth_rank' => 1,
        'is_starter' => true,
    ]);

    DepthChartEntry::query()->create([
        'team_id' => $this->homeTeam->id,
        'player_id' => $reserve->id,
        'season' => 2026,
        'position_slot_key' => 'pg',
        'position_code' => 'PG',
        'position_name' => 'Point Guard',
        'position_display_name' => 'Point Guard',
        'espn_athlete_id' => $reserve->espn_id,
        'depth_rank' => 3,
        'is_starter' => false,
    ]);

    PlayerInjury::query()->create([
        'player_id' => $reserve->id,
        'team_id' => $this->homeTeam->id,
        'injury_key' => 'reserve-out',
        'status' => 'Out',
        'detail' => 'Reserve test injury',
        'type' => 'Leg',
        'injury_date' => now()->toDateString(),
        'is_active' => true,
    ]);

    $reservePrediction = (new GeneratePrediction)->execute($game->fresh());

    PlayerInjury::query()->delete();

    PlayerInjury::query()->create([
        'player_id' => $starter->id,
        'team_id' => $this->homeTeam->id,
        'injury_key' => 'starter-out',
        'status' => 'Out',
        'detail' => 'Starter test injury',
        'type' => 'Leg',
        'injury_date' => now()->toDateString(),
        'is_active' => true,
    ]);

    $starterPrediction = (new GeneratePrediction)->execute($game->fresh());

    expect($reservePrediction)->not->toBeNull()
        ->and($starterPrediction)->not->toBeNull()
        ->and(abs((float) $starterPrediction->injury_spread_adj))->toBeGreaterThan(abs((float) $reservePrediction->injury_spread_adj))
        ->and((float) $starterPrediction->predicted_spread)->toBeLessThan((float) $reservePrediction->predicted_spread);
});

it('applies true epa blend metadata when enabled and metrics are available', function () {
    config()->set('nba.prediction.true_epa.enabled', true);
    config()->set('nba.prediction.true_epa.blend_weight', 1.0);
    config()->set('nba.prediction.true_epa.spread_points_per_epa', 20.0);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    TeamMetric::create([
        'team_id' => $this->homeTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 114.0,
        'defensive_efficiency' => 109.0,
        'net_rating' => 5.0,
        'tempo' => 100.0,
        'strength_of_schedule' => 1500.0,
        'offensive_true_epa_per_play' => 0.120,
        'defensive_true_epa_per_play' => -0.040,
        'net_true_epa_per_play' => 0.080,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->awayTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 109.0,
        'defensive_efficiency' => 112.0,
        'net_rating' => -3.0,
        'tempo' => 99.0,
        'strength_of_schedule' => 1500.0,
        'offensive_true_epa_per_play' => 0.030,
        'defensive_true_epa_per_play' => 0.020,
        'net_true_epa_per_play' => -0.020,
        'calculation_date' => now()->toDateString(),
    ]);

    $prediction = (new GeneratePrediction)->execute($game);

    expect($prediction)->not->toBeNull();
    expect(data_get($prediction->model_metadata, 'true_epa.true_epa_enabled'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'true_epa.true_epa_applied'))->toBeTrue()
        ->and((float) data_get($prediction->model_metadata, 'true_epa.true_epa_diff'))->toBe(0.1)
        ->and(data_get($prediction->model_metadata, 'true_epa.true_epa_total_reason'))->toBe('applied');
});

it('uses recent efficiency context in nba total projection and stores total metadata', function () {
    TeamMetric::create([
        'team_id' => $this->homeTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 112.0,
        'defensive_efficiency' => 110.0,
        'net_rating' => 2.0,
        'tempo' => 100.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->awayTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 111.0,
        'defensive_efficiency' => 112.0,
        'net_rating' => -1.0,
        'tempo' => 100.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->homeTeam->id,
        'season' => 2027,
        'offensive_efficiency' => 112.0,
        'defensive_efficiency' => 110.0,
        'net_rating' => 2.0,
        'tempo' => 100.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->awayTeam->id,
        'season' => 2027,
        'offensive_efficiency' => 111.0,
        'defensive_efficiency' => 112.0,
        'net_rating' => -1.0,
        'tempo' => 100.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    for ($i = 0; $i < 4; $i++) {
        $homeOpponent = Team::factory()->create();
        $homeGame = Game::factory()->create([
            'home_team_id' => $this->homeTeam->id,
            'away_team_id' => $homeOpponent->id,
            'status' => 'STATUS_FINAL',
            'season' => 2026,
            'game_date' => now()->subDays(10 - $i),
            'home_score' => 130,
            'away_score' => 118,
        ]);

        TeamStat::factory()->create([
            'team_id' => $this->homeTeam->id,
            'game_id' => $homeGame->id,
            'team_type' => 'home',
            'points' => 130,
            'possessions' => 100,
        ]);

        TeamStat::factory()->create([
            'team_id' => $homeOpponent->id,
            'game_id' => $homeGame->id,
            'team_type' => 'away',
            'points' => 118,
            'possessions' => 100,
        ]);

        $awayOpponent = Team::factory()->create();
        $awayGame = Game::factory()->create([
            'home_team_id' => $awayOpponent->id,
            'away_team_id' => $this->awayTeam->id,
            'status' => 'STATUS_FINAL',
            'season' => 2026,
            'game_date' => now()->subDays(10 - $i),
            'home_score' => 128,
            'away_score' => 122,
        ]);

        TeamStat::factory()->create([
            'team_id' => $awayOpponent->id,
            'game_id' => $awayGame->id,
            'team_type' => 'home',
            'points' => 128,
            'possessions' => 100,
        ]);

        TeamStat::factory()->create([
            'team_id' => $this->awayTeam->id,
            'game_id' => $awayGame->id,
            'team_type' => 'away',
            'points' => 122,
            'possessions' => 100,
        ]);
    }

    $hotGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'game_date' => now()->addDay(),
    ]);

    $controlGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2027,
        'game_date' => now()->addDays(2),
    ]);

    $action = new GeneratePrediction;
    $hotPrediction = $action->execute($hotGame);
    $controlPrediction = $action->execute($controlGame);

    expect($hotPrediction)->not->toBeNull()
        ->and($controlPrediction)->not->toBeNull()
        ->and((float) $hotPrediction->predicted_total)->toBeGreaterThan((float) $controlPrediction->predicted_total)
        ->and(data_get($hotPrediction->model_metadata, 'total_model.recent_home_score_component'))->not->toBeNull()
        ->and(data_get($hotPrediction->model_metadata, 'total_model.calibrated_total'))->toBeNumeric();
});

it('uses total-specific confidence for nba total recommendations', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'odds_data' => [
            'home_team' => $this->homeTeam->location,
            'bookmakers' => [
                [
                    'key' => 'draftkings',
                    'markets' => [
                        [
                            'key' => 'totals',
                            'outcomes' => [
                                ['name' => 'Over', 'point' => 230.0, 'price' => -110],
                                ['name' => 'Under', 'point' => 230.0, 'price' => -110],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    Prediction::create([
        'game_id' => $game->id,
        'home_elo' => 1500,
        'away_elo' => 1500,
        'home_off_eff' => 112.0,
        'home_def_eff' => 110.0,
        'away_off_eff' => 111.0,
        'away_def_eff' => 112.0,
        'predicted_spread' => 1.5,
        'predicted_total' => 240.0,
        'win_probability' => 0.60,
        'confidence_score' => 60.0,
    ]);

    $recommendations = (new CalculateBettingValue)->execute($game);
    $totalRec = collect($recommendations)->firstWhere('type', 'total');

    expect($totalRec)->not->toBeNull()
        ->and($totalRec['confidence'])->toBe(95.0)
        ->and($totalRec['side_confidence'])->toBe(60.0);
});
