<?php

use App\Actions\NBA\GeneratePrediction;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;
use App\Models\NBA\TeamStat;

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

    // Rested home team should have more favorable spread
    expect((float) $prediction->predicted_spread)->toBeGreaterThan((float) $evenPrediction->predicted_spread);
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
                                ['name' => $this->homeTeam->location, 'price' => -200],
                                ['name' => $this->awayTeam->location, 'price' => 170],
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
