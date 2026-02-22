<?php

use App\Actions\WCBB\GeneratePrediction;
use App\Models\WCBB\Game;
use App\Models\WCBB\Prediction;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamMetric;
use App\Models\WCBB\TeamStat;

uses()->group('wcbb', 'predictions');

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
    $strongHome = Team::factory()->create(['elo_rating' => 1700]);
    $weakAway = Team::factory()->create(['elo_rating' => 1300]);

    $game1 = Game::factory()->create([
        'home_team_id' => $strongHome->id,
        'away_team_id' => $weakAway->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

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

    expect((float) $prediction1->confidence_score)->toBeGreaterThan((float) $prediction2->confidence_score);

    expect((float) $prediction1->confidence_score)->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(100);
    expect((float) $prediction2->confidence_score)->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(100);

    $wp1 = (float) $prediction1->win_probability;
    $expectedConfidence1 = round(max($wp1, 1 - $wp1) * 100, 2);
    expect((float) $prediction1->confidence_score)->toBe($expectedConfidence1);
});

it('does not generate prediction for completed game', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 75,
        'away_score' => 65,
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
        'offensive_efficiency' => 110.0,
        'defensive_efficiency' => 100.0,
        'net_rating' => 10.0,
        'tempo' => 70.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->awayTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 105.0,
        'defensive_efficiency' => 108.0,
        'net_rating' => -3.0,
        'tempo' => 68.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->not->toBeNull();
    expect((float) $prediction->home_off_eff)->toBe(110.0);
    expect((float) $prediction->home_def_eff)->toBe(100.0);
    expect((float) $prediction->away_off_eff)->toBe(105.0);
    expect((float) $prediction->away_def_eff)->toBe(108.0);
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
        'offensive_efficiency' => 110.0,
        'defensive_efficiency' => 100.0,
        'net_rating' => 10.0,
        'tempo' => 70.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->awayTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 105.0,
        'defensive_efficiency' => 108.0,
        'net_rating' => -3.0,
        'tempo' => 68.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction->elo_spread_component)->not->toBeNull();
    expect($prediction->efficiency_spread_component)->not->toBeNull();
    expect($prediction->form_spread_component)->not->toBeNull();

    // ELO component: (1550 + 35 - 1450) / 30 ≈ 4.5
    expect((float) $prediction->elo_spread_component)->toBeGreaterThan(0);

    // Efficiency component: home +10 net, away -3 → should be positive
    expect((float) $prediction->efficiency_spread_component)->toBeGreaterThan(0);
});

it('incorporates recent form from completed games', function () {
    for ($i = 0; $i < 5; $i++) {
        $opponent = Team::factory()->create();
        $completedGame = Game::factory()->create([
            'home_team_id' => $this->homeTeam->id,
            'away_team_id' => $opponent->id,
            'status' => 'STATUS_FINAL',
            'season' => 2026,
            'game_date' => now()->subDays(10 - $i),
            'home_score' => 80,
            'away_score' => 65,
        ]);

        TeamStat::factory()->create([
            'team_id' => $this->homeTeam->id,
            'game_id' => $completedGame->id,
            'team_type' => 'home',
            'points' => 80,
            'possessions' => 70,
            'turnovers' => 12,
            'rebounds' => 40,
        ]);

        TeamStat::factory()->create([
            'team_id' => $opponent->id,
            'game_id' => $completedGame->id,
            'team_type' => 'away',
            'points' => 65,
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
    expect((float) $prediction->home_recent_form)->toBeGreaterThan(0);
    expect((float) $prediction->form_spread_component)->not->toBeNull();
});

it('applies rest day advantage when home team is rested', function () {
    $opponent = Team::factory()->create();

    Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $opponent->id,
        'status' => 'STATUS_FINAL',
        'season' => 2026,
        'game_date' => now()->subDays(3),
    ]);

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

    $evenGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2025,
        'game_date' => now()->addDays(2),
    ]);

    $evenPrediction = $action->execute($evenGame);

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
            'turnovers' => 10,
            'rebounds' => 45,
        ]);

        TeamStat::factory()->create([
            'team_id' => $opponent->id,
            'game_id' => $completedGame->id,
            'turnovers' => 18,
            'rebounds' => 33,
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
    expect((float) $prediction->turnover_diff_adj)->toBeGreaterThan(0);
    expect((float) $prediction->rebound_margin_adj)->toBeGreaterThan(0);
});

it('ensemble weights sum to one', function () {
    $config = config('wcbb.prediction');
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

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->not->toBeNull();
    expect((float) $prediction->form_spread_component)->toBe(
        config('wcbb.prediction.home_court_points')
    );
});

it('updates existing prediction instead of creating duplicate', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $action = new GeneratePrediction;

    $prediction1 = $action->execute($game);
    expect(Prediction::count())->toBe(1);

    $this->homeTeam->update(['elo_rating' => 1600]);
    $game->refresh();

    $prediction2 = $action->execute($game);

    expect(Prediction::count())->toBe(1);
    expect($prediction2->id)->toBe($prediction1->id);
    expect((float) $prediction2->home_elo)->toBe(1600.0);
});
