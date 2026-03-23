<?php

use App\Actions\CBB\CalculateBettingValue;
use App\Actions\CBB\GeneratePrediction;
use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use App\Models\CBB\Team;
use App\Models\CBB\TeamMetric;
use App\Models\CBB\TeamStat;

uses()->group('cbb', 'predictions');

beforeEach(function () {
    $this->homeTeam = Team::factory()->create([
        'elo_rating' => 1550,
        'school' => 'Kansas',
        'mascot' => 'Jayhawks',
    ]);
    $this->awayTeam = Team::factory()->create([
        'elo_rating' => 1450,
        'school' => 'Baylor',
        'mascot' => 'Bears',
    ]);
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
    expect((float) $prediction->predicted_spread)->toBeGreaterThan(0);
    expect((float) $prediction->win_probability)->toBeGreaterThan(0.5);
});

it('calculates confidence from win probability', function () {
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

    // Mismatched game should have higher confidence
    expect((float) $prediction1->confidence_score)->toBeGreaterThan((float) $prediction2->confidence_score);

    // Confidence should be between 50 and 100
    expect((float) $prediction1->confidence_score)->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(100);
    expect((float) $prediction2->confidence_score)->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(100);

    // Confidence should equal max(wp, 1-wp) * 100
    $wp1 = (float) $prediction1->win_probability;
    $expectedConfidence1 = round(max($wp1, 1 - $wp1) * 100, 2);
    expect((float) $prediction1->confidence_score)->toBe($expectedConfidence1);

    $wp2 = (float) $prediction2->win_probability;
    $expectedConfidence2 = round(max($wp2, 1 - $wp2) * 100, 2);
    expect((float) $prediction2->confidence_score)->toBe($expectedConfidence2);
});

it('does not generate prediction for completed game', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 80,
        'away_score' => 70,
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
        'tempo' => 70.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->awayTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 108.0,
        'defensive_efficiency' => 112.0,
        'net_rating' => -4.0,
        'tempo' => 68.0,
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
});

it('raises predicted total when recent scoring factors improve', function () {
    TeamMetric::create([
        'team_id' => $this->homeTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 102.0,
        'defensive_efficiency' => 100.0,
        'net_rating' => 2.0,
        'tempo' => 69.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->awayTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 101.0,
        'defensive_efficiency' => 101.0,
        'net_rating' => 0.0,
        'tempo' => 68.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    $baselineGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2025,
        'game_date' => now()->addDay(),
    ]);

    $boostedGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'game_date' => now()->addDays(2),
    ]);

    foreach ([$this->homeTeam, $this->awayTeam] as $team) {
        for ($i = 0; $i < 4; $i++) {
            $opponent = Team::factory()->create();
            $game = Game::factory()->create([
                'home_team_id' => $team->id,
                'away_team_id' => $opponent->id,
                'status' => 'STATUS_FINAL',
                'season' => 2026,
                'game_date' => now()->subDays(8 - $i),
                'home_score' => 88,
                'away_score' => 74,
            ]);

            TeamStat::factory()->create([
                'team_id' => $team->id,
                'game_id' => $game->id,
                'team_type' => 'home',
                'points' => 88,
                'field_goals_made' => 31,
                'field_goals_attempted' => 57,
                'three_point_made' => 10,
                'three_point_attempted' => 22,
                'free_throws_made' => 16,
                'free_throws_attempted' => 20,
                'offensive_rebounds' => 12,
                'defensive_rebounds' => 24,
                'rebounds' => 36,
                'turnovers' => 8,
                'possessions' => 70,
            ]);

            TeamStat::factory()->create([
                'team_id' => $opponent->id,
                'game_id' => $game->id,
                'team_type' => 'away',
                'points' => 74,
                'field_goals_made' => 25,
                'field_goals_attempted' => 59,
                'three_point_made' => 6,
                'three_point_attempted' => 21,
                'free_throws_made' => 18,
                'free_throws_attempted' => 24,
                'offensive_rebounds' => 11,
                'defensive_rebounds' => 19,
                'rebounds' => 30,
                'turnovers' => 12,
                'possessions' => 70,
            ]);
        }
    }

    $action = new GeneratePrediction;
    $baselinePrediction = $action->execute($baselineGame);
    $boostedPrediction = $action->execute($boostedGame);

    expect($baselinePrediction)->not->toBeNull();
    expect($boostedPrediction)->not->toBeNull();
    expect((float) $boostedPrediction->predicted_total)->toBeGreaterThan((float) $baselinePrediction->predicted_total);
});

it('lowers predicted total when recent shooting profile is poor', function () {
    TeamMetric::create([
        'team_id' => $this->homeTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 104.0,
        'defensive_efficiency' => 102.0,
        'net_rating' => 2.0,
        'tempo' => 69.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->awayTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 103.0,
        'defensive_efficiency' => 101.0,
        'net_rating' => 2.0,
        'tempo' => 69.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    $baselineGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2025,
        'game_date' => now()->addDay(),
    ]);

    $sluggishGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'game_date' => now()->addDays(2),
    ]);

    foreach ([$this->homeTeam, $this->awayTeam] as $team) {
        for ($i = 0; $i < 4; $i++) {
            $opponent = Team::factory()->create();
            $game = Game::factory()->create([
                'home_team_id' => $team->id,
                'away_team_id' => $opponent->id,
                'status' => 'STATUS_FINAL',
                'season' => 2026,
                'game_date' => now()->subDays(8 - $i),
                'home_score' => 62,
                'away_score' => 68,
            ]);

            TeamStat::factory()->create([
                'team_id' => $team->id,
                'game_id' => $game->id,
                'team_type' => 'home',
                'points' => 62,
                'field_goals_made' => 21,
                'field_goals_attempted' => 60,
                'three_point_made' => 4,
                'three_point_attempted' => 20,
                'free_throws_made' => 16,
                'free_throws_attempted' => 22,
                'offensive_rebounds' => 7,
                'defensive_rebounds' => 19,
                'rebounds' => 26,
                'turnovers' => 18,
                'possessions' => 70,
            ]);

            TeamStat::factory()->create([
                'team_id' => $opponent->id,
                'game_id' => $game->id,
                'team_type' => 'away',
                'points' => 68,
                'field_goals_made' => 24,
                'field_goals_attempted' => 56,
                'three_point_made' => 7,
                'three_point_attempted' => 19,
                'free_throws_made' => 13,
                'free_throws_attempted' => 17,
                'offensive_rebounds' => 10,
                'defensive_rebounds' => 24,
                'rebounds' => 34,
                'turnovers' => 10,
                'possessions' => 70,
            ]);
        }
    }

    $action = new GeneratePrediction;
    $baselinePrediction = $action->execute($baselineGame);
    $sluggishPrediction = $action->execute($sluggishGame);

    expect($baselinePrediction)->not->toBeNull();
    expect($sluggishPrediction)->not->toBeNull();
    expect((float) $sluggishPrediction->predicted_total)->toBeLessThan((float) $baselinePrediction->predicted_total);
});

it('stores total model metadata for cbb totals', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    TeamMetric::create([
        'team_id' => $this->homeTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 108.0,
        'defensive_efficiency' => 101.0,
        'net_rating' => 7.0,
        'tempo' => 70.0,
        'rolling_offensive_efficiency' => 111.0,
        'rolling_defensive_efficiency' => 99.0,
        'rolling_tempo' => 71.0,
        'home_offensive_efficiency' => 112.0,
        'home_defensive_efficiency' => 98.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->awayTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 104.0,
        'defensive_efficiency' => 103.0,
        'net_rating' => 1.0,
        'tempo' => 68.0,
        'rolling_offensive_efficiency' => 106.0,
        'rolling_defensive_efficiency' => 104.0,
        'rolling_tempo' => 69.0,
        'away_offensive_efficiency' => 105.0,
        'away_defensive_efficiency' => 104.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    $prediction = (new GeneratePrediction)->execute($game);

    expect($prediction)->not->toBeNull();
    expect(data_get($prediction->model_metadata, 'total_model.season_home_score_component'))->not->toBeNull()
        ->and(data_get($prediction->model_metadata, 'total_model.recent_pace'))->not->toBeNull()
        ->and(data_get($prediction->model_metadata, 'total_model.recent_factor_profile.home'))->toBeArray();
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
        'tempo' => 70.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->awayTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 108.0,
        'defensive_efficiency' => 112.0,
        'net_rating' => -4.0,
        'tempo' => 68.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    // Spread components should be populated
    expect($prediction->elo_spread_component)->not->toBeNull();
    expect($prediction->efficiency_spread_component)->not->toBeNull();
    expect($prediction->form_spread_component)->not->toBeNull();

    // ELO component: home favored (1550 + 35 - 1450) / 30 ≈ 4.5
    expect((float) $prediction->elo_spread_component)->toBeGreaterThan(0);

    // Efficiency component: home has +10 net, away has -4 → should be positive
    expect((float) $prediction->efficiency_spread_component)->toBeGreaterThan(0);
});

it('incorporates recent form from completed games', function () {
    // Create some completed games with team stats for the home team
    for ($i = 0; $i < 5; $i++) {
        $opponent = Team::factory()->create();
        $completedGame = Game::factory()->create([
            'home_team_id' => $this->homeTeam->id,
            'away_team_id' => $opponent->id,
            'status' => 'STATUS_FINAL',
            'season' => 2026,
            'game_date' => now()->subDays(10 - $i),
            'home_score' => 85,
            'away_score' => 70,
        ]);

        TeamStat::factory()->create([
            'team_id' => $this->homeTeam->id,
            'game_id' => $completedGame->id,
            'team_type' => 'home',
            'points' => 85,
            'possessions' => 70,
            'turnovers' => 12,
            'rebounds' => 40,
        ]);

        TeamStat::factory()->create([
            'team_id' => $opponent->id,
            'game_id' => $completedGame->id,
            'team_type' => 'away',
            'points' => 70,
            'possessions' => 70,
            'turnovers' => 15,
            'rebounds' => 35,
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
    // Home team has strong recent form (85/70*100 = 121.4 off eff), so form component should be positive
    expect((float) $prediction->home_recent_form)->toBeGreaterThan(0);
    expect((float) $prediction->form_spread_component)->not->toBeNull();
});

it('applies rest day advantage when home team is rested', function () {
    $opponent = Team::factory()->create();

    // Home team last played 3 days ago
    Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $opponent->id,
        'status' => 'STATUS_FINAL',
        'season' => 2026,
        'game_date' => now()->subDays(3),
    ]);

    // Away team last played yesterday (back-to-back)
    Game::factory()->create([
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
                            'key' => 'h2h',
                            'outcomes' => [
                                ['name' => $this->homeTeam->school, 'price' => -200],
                                ['name' => $this->awayTeam->school, 'price' => 170],
                            ],
                        ],
                        [
                            'key' => 'spreads',
                            'outcomes' => [
                                ['name' => $this->homeTeam->school, 'point' => -4.5, 'price' => -110],
                                ['name' => $this->awayTeam->school, 'point' => 4.5, 'price' => -110],
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
            'rebounds' => 45, // High rebounds
        ]);

        TeamStat::factory()->create([
            'team_id' => $opponent->id,
            'game_id' => $completedGame->id,
            'turnovers' => 18, // High turnovers (forced by home team)
            'rebounds' => 33, // Low rebounds
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
    $config = config('cbb.prediction');
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
        config('cbb.prediction.home_court_points')
    );
});

it('applies true epa blend metadata when enabled and metrics are available', function () {
    config()->set('cbb.prediction.true_epa.enabled', true);
    config()->set('cbb.prediction.true_epa.blend_weight', 1.0);
    config()->set('cbb.prediction.true_epa.spread_points_per_epa', 15.0);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    TeamMetric::create([
        'team_id' => $this->homeTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 109.0,
        'defensive_efficiency' => 99.0,
        'net_rating' => 10.0,
        'tempo' => 70.0,
        'strength_of_schedule' => 1500.0,
        'offensive_true_epa_per_play' => 0.100,
        'defensive_true_epa_per_play' => -0.020,
        'net_true_epa_per_play' => 0.070,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->awayTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 103.0,
        'defensive_efficiency' => 102.0,
        'net_rating' => 1.0,
        'tempo' => 69.0,
        'strength_of_schedule' => 1500.0,
        'offensive_true_epa_per_play' => 0.030,
        'defensive_true_epa_per_play' => 0.010,
        'net_true_epa_per_play' => -0.010,
        'calculation_date' => now()->toDateString(),
    ]);

    $prediction = (new GeneratePrediction)->execute($game);

    expect($prediction)->not->toBeNull();
    expect(data_get($prediction->model_metadata, 'true_epa.true_epa_enabled'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'true_epa.true_epa_applied'))->toBeTrue()
        ->and((float) data_get($prediction->model_metadata, 'true_epa.true_epa_diff'))->toBe(0.08)
        ->and(data_get($prediction->model_metadata, 'true_epa.true_epa_total_reason'))->toBe('applied');
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

    // Update team Elo
    $this->homeTeam->update(['elo_rating' => 1600]);
    $game->refresh();

    // Second prediction should update, not create new
    $prediction2 = $action->execute($game);

    expect(Prediction::count())->toBe(1);
    expect($prediction2->id)->toBe($prediction1->id);
    expect((float) $prediction2->home_elo)->toBe(1600.0);
});

it('uses total-specific confidence for cbb over under recommendations', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'odds_data' => [
            'bookmakers' => [
                [
                    'markets' => [
                        [
                            'key' => 'totals',
                            'outcomes' => [
                                ['name' => 'Over', 'point' => 141.5, 'price' => -110],
                                ['name' => 'Under', 'point' => 141.5, 'price' => -110],
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
        'home_off_eff' => 100,
        'home_def_eff' => 100,
        'away_off_eff' => 100,
        'away_def_eff' => 100,
        'predicted_spread' => 1.5,
        'predicted_total' => 148.0,
        'win_probability' => 0.52,
        'confidence_score' => 52.0,
    ]);

    $recommendations = app(CalculateBettingValue::class)->execute($game->fresh('prediction'));
    $totalRec = collect($recommendations)->firstWhere('type', 'total');

    expect($totalRec)->not->toBeNull();
    expect($totalRec['confidence'])->not->toBe(52.0)
        ->and($totalRec['side_confidence'])->toBe(52.0)
        ->and($totalRec['recommendation'])->toBe('Bet Over');
});

it('filters out small-edge away spread recommendations for cbb', function () {
    config()->set('cbb.betting.edge_thresholds.spread', 2.0);
    config()->set('cbb.betting.edge_thresholds.spread_away', 4.0);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'odds_data' => [
            'home_team' => $this->homeTeam->school,
            'away_team' => $this->awayTeam->school,
            'bookmakers' => [
                [
                    'markets' => [
                        [
                            'key' => 'spreads',
                            'outcomes' => [
                                ['name' => $this->homeTeam->school, 'point' => -8.5, 'price' => -110],
                                ['name' => $this->awayTeam->school, 'point' => 8.5, 'price' => -110],
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
        'home_off_eff' => 100,
        'home_def_eff' => 100,
        'away_off_eff' => 100,
        'away_def_eff' => 100,
        'predicted_spread' => 5.9,
        'predicted_total' => 148.0,
        'win_probability' => 0.62,
        'confidence_score' => 62.0,
    ]);

    $recommendations = app(CalculateBettingValue::class)->execute($game->fresh('prediction'));
    $spreadRec = collect($recommendations ?? [])->firstWhere('type', 'spread');

    expect($spreadRec)->toBeNull();
});

it('keeps home spread recommendations at the base cbb threshold', function () {
    config()->set('cbb.betting.edge_thresholds.spread', 2.0);
    config()->set('cbb.betting.edge_thresholds.spread_away', 4.0);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'odds_data' => [
            'home_team' => $this->homeTeam->school,
            'away_team' => $this->awayTeam->school,
            'bookmakers' => [
                [
                    'markets' => [
                        [
                            'key' => 'spreads',
                            'outcomes' => [
                                ['name' => $this->homeTeam->school, 'point' => -3.5, 'price' => -110],
                                ['name' => $this->awayTeam->school, 'point' => 3.5, 'price' => -110],
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
        'home_off_eff' => 100,
        'home_def_eff' => 100,
        'away_off_eff' => 100,
        'away_def_eff' => 100,
        'predicted_spread' => 5.9,
        'predicted_total' => 148.0,
        'win_probability' => 0.62,
        'confidence_score' => 62.0,
    ]);

    $recommendations = app(CalculateBettingValue::class)->execute($game->fresh('prediction'));
    $spreadRec = collect($recommendations ?? [])->firstWhere('type', 'spread');

    expect($spreadRec)->not->toBeNull()
        ->and($spreadRec['recommendation'])->toContain($this->homeTeam->school);
});

it('filters out sub-threshold home spread recommendations for cbb', function () {
    config()->set('cbb.betting.edge_thresholds.spread', 2.0);
    config()->set('cbb.betting.edge_thresholds.spread_away', 4.0);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'odds_data' => [
            'home_team' => $this->homeTeam->school,
            'away_team' => $this->awayTeam->school,
            'bookmakers' => [
                [
                    'markets' => [
                        [
                            'key' => 'spreads',
                            'outcomes' => [
                                ['name' => $this->homeTeam->school, 'point' => -3.5, 'price' => -110],
                                ['name' => $this->awayTeam->school, 'point' => 3.5, 'price' => -110],
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
        'home_off_eff' => 100,
        'home_def_eff' => 100,
        'away_off_eff' => 100,
        'away_def_eff' => 100,
        'predicted_spread' => 5.4,
        'predicted_total' => 148.0,
        'win_probability' => 0.60,
        'confidence_score' => 60.0,
    ]);

    $recommendations = app(CalculateBettingValue::class)->execute($game->fresh('prediction'));
    $spreadRec = collect($recommendations ?? [])->firstWhere('type', 'spread');

    expect($spreadRec)->toBeNull();
});

it('filters out extreme tournament under outliers for cbb', function () {
    config()->set('cbb.betting.edge_thresholds.total', 2.25);
    config()->set('cbb.betting.filters.tournament_under_min_edge', 4.5);
    config()->set('cbb.betting.filters.tournament_under_market_total_floor', 145.0);
    config()->set('cbb.betting.filters.tournament_under_skip_edge', 18.0);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'tournament_round' => 'round_of_32',
        'home_seed' => 1,
        'away_seed' => 9,
        'odds_data' => [
            'bookmakers' => [
                [
                    'markets' => [
                        [
                            'key' => 'totals',
                            'outcomes' => [
                                ['name' => 'Over', 'point' => 161.5, 'price' => -110],
                                ['name' => 'Under', 'point' => 161.5, 'price' => -110],
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
        'home_off_eff' => 110,
        'home_def_eff' => 100,
        'away_off_eff' => 103,
        'away_def_eff' => 104,
        'predicted_spread' => 6.0,
        'predicted_total' => 139.5,
        'win_probability' => 0.76,
        'confidence_score' => 76.0,
    ]);

    $recommendations = app(CalculateBettingValue::class)->execute($game->fresh('prediction'));
    $totalRec = collect($recommendations ?? [])->firstWhere('type', 'total');

    expect($totalRec)->toBeNull();
});

it('requires a stronger tournament under edge for cbb', function () {
    config()->set('cbb.betting.edge_thresholds.total', 2.25);
    config()->set('cbb.betting.filters.tournament_under_min_edge', 4.5);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'tournament_round' => 'round_of_32',
        'home_seed' => 3,
        'away_seed' => 6,
        'odds_data' => [
            'bookmakers' => [
                [
                    'markets' => [
                        [
                            'key' => 'totals',
                            'outcomes' => [
                                ['name' => 'Over', 'point' => 151.5, 'price' => -110],
                                ['name' => 'Under', 'point' => 151.5, 'price' => -110],
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
        'home_off_eff' => 109,
        'home_def_eff' => 102,
        'away_off_eff' => 107,
        'away_def_eff' => 104,
        'predicted_spread' => 1.0,
        'predicted_total' => 148.0,
        'win_probability' => 0.58,
        'confidence_score' => 58.0,
    ]);

    $recommendations = app(CalculateBettingValue::class)->execute($game->fresh('prediction'));
    $totalRec = collect($recommendations ?? [])->firstWhere('type', 'total');

    expect($totalRec)->toBeNull();
});

it('filters out extreme high-total over outliers for cbb', function () {
    config()->set('cbb.betting.edge_thresholds.total', 2.25);
    config()->set('cbb.betting.filters.high_total_over_market_floor', 145.0);
    config()->set('cbb.betting.filters.high_total_over_min_edge', 4.5);
    config()->set('cbb.betting.filters.high_total_over_skip_edge', 16.5);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'odds_data' => [
            'bookmakers' => [
                [
                    'markets' => [
                        [
                            'key' => 'totals',
                            'outcomes' => [
                                ['name' => 'Over', 'point' => 145.5, 'price' => -110],
                                ['name' => 'Under', 'point' => 145.5, 'price' => -110],
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
        'home_off_eff' => 112,
        'home_def_eff' => 102,
        'away_off_eff' => 111,
        'away_def_eff' => 103,
        'predicted_spread' => 1.0,
        'predicted_total' => 162.6,
        'win_probability' => 0.55,
        'confidence_score' => 55.0,
    ]);

    $recommendations = app(CalculateBettingValue::class)->execute($game->fresh('prediction'));
    $totalRec = collect($recommendations ?? [])->firstWhere('type', 'total');

    expect($totalRec)->toBeNull();
});

it('reduces confidence for high-total over recommendations for cbb', function () {
    config()->set('cbb.betting.edge_thresholds.total', 2.25);
    config()->set('cbb.betting.filters.high_total_over_market_floor', 145.0);
    config()->set('cbb.betting.filters.high_total_over_min_edge', 4.5);
    config()->set('cbb.betting.filters.high_total_over_skip_edge', 20.0);
    config()->set('cbb.betting.filters.high_total_over_confidence_penalty', 8.0);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'odds_data' => [
            'bookmakers' => [
                [
                    'markets' => [
                        [
                            'key' => 'totals',
                            'outcomes' => [
                                ['name' => 'Over', 'point' => 145.5, 'price' => -110],
                                ['name' => 'Under', 'point' => 145.5, 'price' => -110],
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
        'home_off_eff' => 108,
        'home_def_eff' => 101,
        'away_off_eff' => 109,
        'away_def_eff' => 102,
        'predicted_spread' => 1.0,
        'predicted_total' => 155.6,
        'win_probability' => 0.54,
        'confidence_score' => 54.0,
    ]);

    $recommendations = app(CalculateBettingValue::class)->execute($game->fresh('prediction'));
    $totalRec = collect($recommendations ?? [])->firstWhere('type', 'total');

    expect($totalRec)->not->toBeNull()
        ->and($totalRec['recommendation'])->toBe('Bet Over')
        ->and($totalRec['confidence'])->toBeLessThan(95.0);
});

it('filters out giant-dog tournament spread recommendations unless the edge is strong enough', function () {
    config()->set('cbb.betting.edge_thresholds.spread', 2.0);
    config()->set('cbb.betting.edge_thresholds.spread_away', 4.0);
    config()->set('cbb.betting.filters.big_dog_line_threshold', 15.0);
    config()->set('cbb.betting.filters.big_dog_min_edge', 6.0);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'tournament_round' => 'round_of_32',
        'home_seed' => 2,
        'away_seed' => 10,
        'odds_data' => [
            'home_team' => $this->homeTeam->school,
            'away_team' => $this->awayTeam->school,
            'bookmakers' => [
                [
                    'markets' => [
                        [
                            'key' => 'spreads',
                            'outcomes' => [
                                ['name' => $this->homeTeam->school, 'point' => -18.5, 'price' => -110],
                                ['name' => $this->awayTeam->school, 'point' => 18.5, 'price' => -110],
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
        'home_off_eff' => 111,
        'home_def_eff' => 101,
        'away_off_eff' => 101,
        'away_def_eff' => 109,
        'predicted_spread' => 14.2,
        'predicted_total' => 146.0,
        'win_probability' => 0.82,
        'confidence_score' => 82.0,
    ]);

    $recommendations = app(CalculateBettingValue::class)->execute($game->fresh('prediction'));
    $spreadRec = collect($recommendations ?? [])->firstWhere('type', 'spread');

    expect($spreadRec)->toBeNull();
});
