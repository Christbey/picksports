<?php

use App\Actions\CBB\GeneratePrediction;
use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use App\Models\CBB\Team;
use App\Models\CBB\TeamMetric;
use App\Models\CBB\TeamStat;

uses()->group('cbb', 'predictions');

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
