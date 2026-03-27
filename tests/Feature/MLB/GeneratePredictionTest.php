<?php

use App\Actions\MLB\GeneratePrediction;
use App\Models\MLB\DepthChartEntry;
use App\Models\MLB\EloRating;
use App\Models\MLB\Game;
use App\Models\MLB\PitcherEloRating;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerInjury;
use App\Models\MLB\Team;
use App\Models\MLB\TeamMetric;
use App\Models\PredictionFeatureSnapshot;
use Illuminate\Support\Facades\Config;

uses()->group('mlb');

it('generates an mlb prediction with version metadata and snapshot data', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'New York',
        'name' => 'Yankees',
        'elo_rating' => 1540,
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Boston',
        'name' => 'Red Sox',
        'elo_rating' => 1495,
    ]);

    TeamMetric::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'wins' => 8,
        'losses' => 7,
        'offensive_rating' => 120.5,
        'pitching_rating' => 112.4,
        'defensive_rating' => 105.1,
        'runs_per_game' => 5.2,
        'runs_allowed_per_game' => 3.8,
        'run_differential_per_game' => 1.4,
        'home_runs_per_game' => 1.8,
        'batting_average' => 0.271,
        'on_base_percentage' => 0.345,
        'slugging_percentage' => 0.442,
        'ops' => 0.787,
        'team_era' => 3.62,
        'strikeouts_pitched_per_game' => 9.7,
        'whip' => 1.14,
        'strength_of_schedule' => 0.512,
        'recent_form_rating' => 0.61,
        'injury_adjusted_team_rating' => 1542.2,
        'rest_travel_fatigue' => 0.0,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'wins' => 9,
        'losses' => 6,
        'offensive_rating' => 109.1,
        'pitching_rating' => 103.2,
        'defensive_rating' => 99.3,
        'runs_per_game' => 4.4,
        'runs_allowed_per_game' => 4.3,
        'run_differential_per_game' => 0.1,
        'home_runs_per_game' => 1.2,
        'batting_average' => 0.252,
        'on_base_percentage' => 0.321,
        'slugging_percentage' => 0.401,
        'ops' => 0.722,
        'team_era' => 4.08,
        'strikeouts_pitched_per_game' => 8.4,
        'whip' => 1.28,
        'strength_of_schedule' => 0.501,
        'recent_form_rating' => 0.48,
        'injury_adjusted_team_rating' => 1492.0,
        'rest_travel_fatigue' => 0.0,
        'calculation_date' => now()->toDateString(),
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 13,
        'game_date' => '2026-03-26',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'probable_home_pitcher_espn_id' => '9001',
        'probable_away_pitcher_espn_id' => '9002',
        'odds_data' => [
            'home_team' => 'New York Yankees',
            'away_team' => 'Boston Red Sox',
            'bookmakers' => [[
                'markets' => [[
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'New York Yankees', 'point' => -1.5],
                        ['name' => 'Boston Red Sox', 'point' => 1.5],
                    ],
                ]],
            ]],
        ],
    ]);

    Game::factory()->create([
        'season' => 2026,
        'week' => 13,
        'game_date' => '2026-03-25',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 4,
        'away_score' => 3,
    ]);

    $homePitcher = Player::factory()->pitcher()->create([
        'team_id' => $homeTeam->id,
        'espn_id' => '9001',
    ]);
    $awayPitcher = Player::factory()->pitcher()->create([
        'team_id' => $awayTeam->id,
        'espn_id' => '9002',
    ]);

    PitcherEloRating::query()->create([
        'player_id' => $homePitcher->id,
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'date' => '2026-03-25',
        'elo_rating' => 1575,
        'elo_change' => 8,
        'games_started' => 1,
    ]);

    PitcherEloRating::query()->create([
        'player_id' => $awayPitcher->id,
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'date' => '2026-03-25',
        'elo_rating' => 1480,
        'elo_change' => -5,
        'games_started' => 1,
    ]);

    $prediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    expect($prediction)->not->toBeNull()
        ->and((float) $prediction->home_team_elo)->toBe(1540.0)
        ->and((float) $prediction->away_team_elo)->toBe(1495.0)
        ->and((float) $prediction->home_pitcher_elo)->toBe(1575.0)
        ->and((float) $prediction->away_pitcher_elo)->toBe(1480.0)
        ->and($prediction->model_version)->toBe('rules-v1')
        ->and($prediction->feature_version)->toBe('core-v1')
        ->and($prediction->blend_version)->toBe('baseline-v1')
        ->and((float) $prediction->vegas_spread)->toBe(-1.5)
        ->and($prediction->model_metadata)->toBeArray()
        ->and(data_get($prediction->model_metadata, 'pitcher_inputs.home_source'))->toBe('probable_starter')
        ->and(data_get($prediction->model_metadata, 'pitcher_inputs.away_source'))->toBe('probable_starter')
        ->and(data_get($prediction->model_metadata, 'season_context.sample_games'))->toBe(15);

    $snapshot = PredictionFeatureSnapshot::query()
        ->where('prediction_table', 'mlb_predictions')
        ->where('prediction_id', $prediction->id)
        ->first();

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->sport)->toBe('mlb')
        ->and(data_get($snapshot->market_context, 'vegas_spread'))->toBe(-1.5)
        ->and(data_get($snapshot->outputs, 'predicted_spread'))->not->toBeNull();
});

it('falls back to default pitcher elo when no recent pitcher ratings exist', function () {
    $homeTeam = Team::factory()->create(['elo_rating' => 1500]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1500]);

    $game = Game::factory()->create([
        'season' => 2026,
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'odds_data' => null,
    ]);

    $prediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    expect($prediction)->not->toBeNull()
        ->and((float) $prediction->home_pitcher_elo)->toBe(1500.0)
        ->and((float) $prediction->away_pitcher_elo)->toBe(1500.0)
        ->and($prediction->vegas_spread)->toBeNull();
});

it('uses configurable mlb spread, total, and win probability tuning values', function () {
    Config::set('mlb.elo.home_field_advantage', 0);
    Config::set('mlb.elo.team_weight', 1.0);
    Config::set('mlb.prediction.early_season.team_weight_start', 1.0);
    Config::set('mlb.prediction.early_season.context_scale_min', 0.0);
    Config::set('mlb.prediction.elo_diff_to_spread_divisor', 25.0);
    Config::set('mlb.prediction.spread_to_probability_coefficient', 2.0);
    Config::set('mlb.prediction.total_model.base_runs', 8.5);
    Config::set('mlb.prediction.total_model.average_elo_baseline', 1500.0);
    Config::set('mlb.prediction.total_model.average_elo_divisor', 50.0);

    $homeTeam = Team::factory()->create(['elo_rating' => 1550]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1500]);

    TeamMetric::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'season_type' => '2',
        'wins' => 10,
        'losses' => 5,
        'recent_form_rating' => 0.0,
        'injury_adjusted_team_rating' => 1550.0,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'season_type' => '2',
        'wins' => 10,
        'losses' => 5,
        'recent_form_rating' => 0.0,
        'injury_adjusted_team_rating' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    Game::factory()->create([
        'season' => 2026,
        'week' => 13,
        'game_date' => '2026-03-25',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 4,
        'away_score' => 3,
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 13,
        'game_date' => '2026-03-26',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    EloRating::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'date' => '2026-03-25',
        'elo_rating' => 1550,
        'elo_change' => 5,
    ]);

    EloRating::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'date' => '2026-03-25',
        'elo_rating' => 1500,
        'elo_change' => -5,
    ]);

    $prediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    expect($prediction)->not->toBeNull()
        ->and((float) $prediction->predicted_spread)->toBe(2.0)
        ->and((float) $prediction->predicted_total)->toBe(9.0)
        ->and((float) $prediction->win_probability)->toBe(0.731);
});

it('prefers probable starter elo over team recent average pitcher elo', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'Seattle',
        'name' => 'Mariners',
        'elo_rating' => 1510,
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Texas',
        'name' => 'Rangers',
        'elo_rating' => 1505,
    ]);

    $homeProbablePitcher = Player::factory()->pitcher()->create([
        'team_id' => $homeTeam->id,
        'espn_id' => '81001',
    ]);
    $awayProbablePitcher = Player::factory()->pitcher()->create([
        'team_id' => $awayTeam->id,
        'espn_id' => '81002',
    ]);

    $otherHomePitcher = Player::factory()->pitcher()->create([
        'team_id' => $homeTeam->id,
    ]);
    $otherAwayPitcher = Player::factory()->pitcher()->create([
        'team_id' => $awayTeam->id,
    ]);

    PitcherEloRating::query()->create([
        'player_id' => $homeProbablePitcher->id,
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'date' => '2026-03-25',
        'elo_rating' => 1620,
        'elo_change' => 10,
        'games_started' => 2,
    ]);
    PitcherEloRating::query()->create([
        'player_id' => $awayProbablePitcher->id,
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'date' => '2026-03-25',
        'elo_rating' => 1460,
        'elo_change' => -4,
        'games_started' => 2,
    ]);
    PitcherEloRating::query()->create([
        'player_id' => $otherHomePitcher->id,
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'date' => '2026-03-24',
        'elo_rating' => 1490,
        'elo_change' => 1,
        'games_started' => 3,
    ]);
    PitcherEloRating::query()->create([
        'player_id' => $otherAwayPitcher->id,
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'date' => '2026-03-24',
        'elo_rating' => 1525,
        'elo_change' => 1,
        'games_started' => 3,
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 13,
        'game_date' => '2026-03-26',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'probable_home_pitcher_espn_id' => '81001',
        'probable_away_pitcher_espn_id' => '81002',
    ]);

    Game::factory()->create([
        'season' => 2026,
        'week' => 13,
        'game_date' => '2026-03-25',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 5,
        'away_score' => 4,
    ]);

    $prediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    expect($prediction)->not->toBeNull()
        ->and((float) $prediction->home_pitcher_elo)->toBe(1620.0)
        ->and((float) $prediction->away_pitcher_elo)->toBe(1460.0)
        ->and(data_get($prediction->model_metadata, 'pitcher_inputs.home_source'))->toBe('probable_starter')
        ->and(data_get($prediction->model_metadata, 'pitcher_inputs.away_source'))->toBe('probable_starter');
});

it('leans more on pitchers and dampens context early in the mlb season', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'Chicago',
        'name' => 'Cubs',
        'elo_rating' => 1500,
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Milwaukee',
        'name' => 'Brewers',
        'elo_rating' => 1500,
    ]);

    $homeProbablePitcher = Player::factory()->pitcher()->create([
        'team_id' => $homeTeam->id,
        'espn_id' => '91001',
    ]);
    $awayProbablePitcher = Player::factory()->pitcher()->create([
        'team_id' => $awayTeam->id,
        'espn_id' => '91002',
    ]);

    PitcherEloRating::query()->create([
        'player_id' => $homeProbablePitcher->id,
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'date' => '2026-03-25',
        'elo_rating' => 1650,
        'elo_change' => 12,
        'games_started' => 1,
    ]);
    PitcherEloRating::query()->create([
        'player_id' => $awayProbablePitcher->id,
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'date' => '2026-03-25',
        'elo_rating' => 1350,
        'elo_change' => -10,
        'games_started' => 1,
    ]);

    $createMetricSet = function (int $wins, int $losses) use ($homeTeam, $awayTeam): void {
        TeamMetric::query()->updateOrCreate(
            ['team_id' => $homeTeam->id, 'season' => 2026],
            [
                'wins' => $wins,
                'losses' => $losses,
                'offensive_rating' => 110,
                'pitching_rating' => 105,
                'defensive_rating' => 100,
                'runs_per_game' => 5.1,
                'runs_allowed_per_game' => 4.1,
                'run_differential_per_game' => 1.0,
                'home_runs_per_game' => 1.4,
                'batting_average' => 0.265,
                'on_base_percentage' => 0.336,
                'slugging_percentage' => 0.430,
                'ops' => 0.766,
                'team_era' => 3.65,
                'strikeouts_pitched_per_game' => 8.9,
                'whip' => 1.15,
                'strength_of_schedule' => 0.510,
                'recent_form_rating' => 0.75,
                'injury_adjusted_team_rating' => 1510,
                'rest_travel_fatigue' => 0.05,
                'calculation_date' => now()->toDateString(),
            ]
        );

        TeamMetric::query()->updateOrCreate(
            ['team_id' => $awayTeam->id, 'season' => 2026],
            [
                'wins' => $wins,
                'losses' => $losses,
                'offensive_rating' => 106,
                'pitching_rating' => 102,
                'defensive_rating' => 99,
                'runs_per_game' => 4.3,
                'runs_allowed_per_game' => 4.5,
                'run_differential_per_game' => -0.2,
                'home_runs_per_game' => 1.1,
                'batting_average' => 0.248,
                'on_base_percentage' => 0.318,
                'slugging_percentage' => 0.401,
                'ops' => 0.719,
                'team_era' => 4.15,
                'strikeouts_pitched_per_game' => 8.3,
                'whip' => 1.27,
                'strength_of_schedule' => 0.505,
                'recent_form_rating' => 0.20,
                'injury_adjusted_team_rating' => 1490,
                'rest_travel_fatigue' => 0.15,
                'calculation_date' => now()->toDateString(),
            ]
        );
    };

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 13,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-03-26',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'probable_home_pitcher_espn_id' => '91001',
        'probable_away_pitcher_espn_id' => '91002',
    ]);

    Game::factory()->create([
        'season' => 2026,
        'week' => 13,
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-03-25',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 4,
        'away_score' => 2,
    ]);

    $createMetricSet(2, 1);
    $earlyPrediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $createMetricSet(20, 15);
    $latePrediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    expect($earlyPrediction)->not->toBeNull()
        ->and($latePrediction)->not->toBeNull()
        ->and(data_get($earlyPrediction->model_metadata, 'season_context.sample_games'))->toBe(3)
        ->and(data_get($latePrediction->model_metadata, 'season_context.sample_games'))->toBe(35)
        ->and(data_get($earlyPrediction->model_metadata, 'season_context.team_weight'))->toBeLessThan(data_get($latePrediction->model_metadata, 'season_context.team_weight'))
        ->and(data_get($earlyPrediction->model_metadata, 'season_context.pitcher_weight'))->toBeGreaterThan(data_get($latePrediction->model_metadata, 'season_context.pitcher_weight'))
        ->and(data_get($earlyPrediction->model_metadata, 'season_context.context_weight_scale'))->toBeLessThan(1.0)
        ->and((float) $earlyPrediction->home_combined_elo)->toBeGreaterThan((float) $latePrediction->home_combined_elo);
});

it('uses prior-season elo, metrics, and pitcher history for opening day instead of spring training data', function () {
    $homeTeam = Team::factory()->create([
        'elo_rating' => 1610,
    ]);
    $awayTeam = Team::factory()->create([
        'elo_rating' => 1390,
    ]);

    TeamMetric::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2025,
        'wins' => 92,
        'losses' => 70,
        'offensive_rating' => 111.0,
        'pitching_rating' => 108.0,
        'defensive_rating' => 103.0,
        'runs_per_game' => 4.9,
        'runs_allowed_per_game' => 4.1,
        'run_differential_per_game' => 0.8,
        'home_runs_per_game' => 1.3,
        'batting_average' => 0.258,
        'on_base_percentage' => 0.331,
        'slugging_percentage' => 0.419,
        'ops' => 0.75,
        'team_era' => 3.84,
        'strikeouts_pitched_per_game' => 8.7,
        'whip' => 1.18,
        'strength_of_schedule' => 1502,
        'recent_form_rating' => 0.2,
        'injury_adjusted_team_rating' => 1518,
        'rest_travel_fatigue' => 0.0,
        'calculation_date' => '2025-10-01',
    ]);

    TeamMetric::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2025,
        'wins' => 84,
        'losses' => 78,
        'offensive_rating' => 106.0,
        'pitching_rating' => 104.0,
        'defensive_rating' => 101.0,
        'runs_per_game' => 4.4,
        'runs_allowed_per_game' => 4.3,
        'run_differential_per_game' => 0.1,
        'home_runs_per_game' => 1.1,
        'batting_average' => 0.249,
        'on_base_percentage' => 0.319,
        'slugging_percentage' => 0.401,
        'ops' => 0.72,
        'team_era' => 4.02,
        'strikeouts_pitched_per_game' => 8.5,
        'whip' => 1.24,
        'strength_of_schedule' => 1496,
        'recent_form_rating' => -0.1,
        'injury_adjusted_team_rating' => 1491,
        'rest_travel_fatigue' => 0.0,
        'calculation_date' => '2025-10-01',
    ]);

    // Contaminated current-season rows that should be ignored before opening day.
    TeamMetric::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'wins' => 18,
        'losses' => 12,
        'offensive_rating' => 130.0,
        'pitching_rating' => 90.0,
        'defensive_rating' => 95.0,
        'runs_per_game' => 6.1,
        'runs_allowed_per_game' => 5.8,
        'run_differential_per_game' => 0.3,
        'home_runs_per_game' => 2.0,
        'batting_average' => 0.295,
        'on_base_percentage' => 0.372,
        'slugging_percentage' => 0.481,
        'ops' => 0.853,
        'team_era' => 5.11,
        'strikeouts_pitched_per_game' => 7.1,
        'whip' => 1.42,
        'strength_of_schedule' => 1470,
        'recent_form_rating' => 1.1,
        'injury_adjusted_team_rating' => 1601,
        'rest_travel_fatigue' => 0.0,
        'calculation_date' => '2026-03-24',
    ]);

    TeamMetric::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'wins' => 19,
        'losses' => 9,
        'offensive_rating' => 128.0,
        'pitching_rating' => 92.0,
        'defensive_rating' => 94.0,
        'runs_per_game' => 5.9,
        'runs_allowed_per_game' => 5.4,
        'run_differential_per_game' => 0.5,
        'home_runs_per_game' => 1.9,
        'batting_average' => 0.289,
        'on_base_percentage' => 0.361,
        'slugging_percentage' => 0.472,
        'ops' => 0.833,
        'team_era' => 4.94,
        'strikeouts_pitched_per_game' => 7.4,
        'whip' => 1.39,
        'strength_of_schedule' => 1465,
        'recent_form_rating' => 1.0,
        'injury_adjusted_team_rating' => 1595,
        'rest_travel_fatigue' => 0.0,
        'calculation_date' => '2026-03-24',
    ]);

    EloRating::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2025,
        'game_id' => null,
        'date' => '2025-10-01',
        'elo_rating' => 1525,
        'elo_change' => 3,
    ]);

    EloRating::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2025,
        'game_id' => null,
        'date' => '2025-10-01',
        'elo_rating' => 1495,
        'elo_change' => -2,
    ]);

    // Spring-training pitcher history that should be ignored.
    PitcherEloRating::query()->create([
        'player_id' => Player::factory()->pitcher()->create(['team_id' => $homeTeam->id])->id,
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'date' => '2026-03-20',
        'elo_rating' => 1630,
        'elo_change' => 5,
        'games_started' => 1,
    ]);
    PitcherEloRating::query()->create([
        'player_id' => Player::factory()->pitcher()->create(['team_id' => $awayTeam->id])->id,
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'date' => '2026-03-20',
        'elo_rating' => 1375,
        'elo_change' => -6,
        'games_started' => 1,
    ]);

    // Prior-season pitcher history that should be used for opening-day fallback.
    PitcherEloRating::query()->create([
        'player_id' => Player::factory()->pitcher()->create(['team_id' => $homeTeam->id])->id,
        'team_id' => $homeTeam->id,
        'season' => 2025,
        'date' => '2025-09-28',
        'elo_rating' => 1510,
        'elo_change' => 2,
        'games_started' => 32,
    ]);
    PitcherEloRating::query()->create([
        'player_id' => Player::factory()->pitcher()->create(['team_id' => $homeTeam->id])->id,
        'team_id' => $homeTeam->id,
        'season' => 2025,
        'date' => '2025-09-22',
        'elo_rating' => 1490,
        'elo_change' => -1,
        'games_started' => 31,
    ]);
    PitcherEloRating::query()->create([
        'player_id' => Player::factory()->pitcher()->create(['team_id' => $awayTeam->id])->id,
        'team_id' => $awayTeam->id,
        'season' => 2025,
        'date' => '2025-09-28',
        'elo_rating' => 1505,
        'elo_change' => 1,
        'games_started' => 31,
    ]);
    PitcherEloRating::query()->create([
        'player_id' => Player::factory()->pitcher()->create(['team_id' => $awayTeam->id])->id,
        'team_id' => $awayTeam->id,
        'season' => 2025,
        'date' => '2025-09-21',
        'elo_rating' => 1495,
        'elo_change' => -2,
        'games_started' => 30,
    ]);

    Game::factory()->create([
        'season' => 2026,
        'week' => 1,
        'season_type' => config('mlb.season.types.regular'),
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-03-20',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 8,
        'away_score' => 3,
    ]);

    $openingDayGame = Game::factory()->create([
        'season' => 2026,
        'week' => 13,
        'season_type' => config('mlb.season.types.regular'),
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-03-25',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $prediction = app(GeneratePrediction::class)->execute($openingDayGame->fresh(['homeTeam', 'awayTeam']));

    expect($prediction)->not->toBeNull()
        ->and((float) $prediction->home_team_elo)->toBe(1525.0)
        ->and((float) $prediction->away_team_elo)->toBe(1495.0)
        ->and((float) $prediction->home_pitcher_elo)->toBe(1500.0)
        ->and((float) $prediction->away_pitcher_elo)->toBe(1500.0)
        ->and(data_get($prediction->model_metadata, 'season_context.sample_games'))->toBe(0)
        ->and(data_get($prediction->model_metadata, 'pitcher_inputs.home_source'))->toBe('team_recent_average')
        ->and(data_get($prediction->model_metadata, 'pitcher_inputs.away_source'))->toBe('team_recent_average');
});

it('derives mlb win probability from final spread rather than raw elo gap', function () {
    $homeTeam = Team::factory()->create([
        'elo_rating' => 1459,
    ]);
    $awayTeam = Team::factory()->create([
        'elo_rating' => 1573,
    ]);

    TeamMetric::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2025,
        'wins' => 81,
        'losses' => 81,
        'offensive_rating' => 104,
        'pitching_rating' => 101,
        'defensive_rating' => 100,
        'runs_per_game' => 4.4,
        'runs_allowed_per_game' => 4.4,
        'run_differential_per_game' => 0,
        'home_runs_per_game' => 1.1,
        'batting_average' => 0.249,
        'on_base_percentage' => 0.319,
        'slugging_percentage' => 0.398,
        'ops' => 0.717,
        'team_era' => 4.12,
        'strikeouts_pitched_per_game' => 8.5,
        'whip' => 1.27,
        'strength_of_schedule' => 1500,
        'recent_form_rating' => 0,
        'injury_adjusted_team_rating' => 1459,
        'rest_travel_fatigue' => 0,
        'calculation_date' => '2025-09-28',
    ]);

    TeamMetric::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2025,
        'wins' => 95,
        'losses' => 67,
        'offensive_rating' => 112,
        'pitching_rating' => 108,
        'defensive_rating' => 104,
        'runs_per_game' => 5.3,
        'runs_allowed_per_game' => 3.9,
        'run_differential_per_game' => 1.4,
        'home_runs_per_game' => 1.4,
        'batting_average' => 0.266,
        'on_base_percentage' => 0.339,
        'slugging_percentage' => 0.432,
        'ops' => 0.771,
        'team_era' => 3.61,
        'strikeouts_pitched_per_game' => 9.1,
        'whip' => 1.16,
        'strength_of_schedule' => 1502,
        'recent_form_rating' => 0.3,
        'injury_adjusted_team_rating' => 1573,
        'rest_travel_fatigue' => 0,
        'calculation_date' => '2025-09-28',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 13,
        'season_type' => config('mlb.season.types.regular'),
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-03-25',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $prediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    expect($prediction)->not->toBeNull()
        ->and(abs((float) $prediction->predicted_spread))->toBeLessThan(1.0)
        ->and((float) $prediction->win_probability)->toBeGreaterThan(0.4)
        ->and((float) $prediction->win_probability)->toBeLessThan(0.6);
});

it('keeps vegas spread null when draftkings only has moneyline but still captures market total', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'Los Angeles',
        'name' => 'Dodgers',
        'elo_rating' => 1560,
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'San Diego',
        'name' => 'Padres',
        'elo_rating' => 1510,
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'odds_data' => [
            'home_team' => 'Los Angeles Dodgers',
            'away_team' => 'San Diego Padres',
            'bookmakers' => [[
                'markets' => [
                    [
                        'key' => 'h2h',
                        'outcomes' => [
                            ['name' => 'Los Angeles Dodgers', 'price' => -160],
                            ['name' => 'San Diego Padres', 'price' => 140],
                        ],
                    ],
                    [
                        'key' => 'totals',
                        'outcomes' => [
                            ['name' => 'Over', 'point' => 8.5, 'price' => -110],
                            ['name' => 'Under', 'point' => 8.5, 'price' => -110],
                        ],
                    ],
                ],
            ]],
        ],
    ]);

    $prediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    expect($prediction)->not->toBeNull()
        ->and($prediction->vegas_spread)->toBeNull()
        ->and(data_get($prediction->model_metadata, 'market_context.market_total'))->toBe(8.5)
        ->and(data_get($prediction->model_metadata, 'market_context.has_h2h'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'market_context.has_spreads'))->toBeFalse()
        ->and(data_get($prediction->model_metadata, 'market_context.has_totals'))->toBeTrue();

    $snapshot = PredictionFeatureSnapshot::query()
        ->where('prediction_table', 'mlb_predictions')
        ->where('prediction_id', $prediction->id)
        ->first();

    expect($snapshot)->not->toBeNull()
        ->and(data_get($snapshot->market_context, 'market_total'))->toBe(8.5)
        ->and(data_get($snapshot->outputs, 'market_total'))->toBe(8.5);
});

it('applies extra injury penalty when a probable starter is unavailable', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'Houston',
        'name' => 'Astros',
        'elo_rating' => 1520,
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Toronto',
        'name' => 'Blue Jays',
        'elo_rating' => 1500,
    ]);

    TeamMetric::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'wins' => 6,
        'losses' => 4,
        'offensive_rating' => 112,
        'pitching_rating' => 108,
        'defensive_rating' => 101,
        'runs_per_game' => 5.0,
        'runs_allowed_per_game' => 4.0,
        'run_differential_per_game' => 1.0,
        'home_runs_per_game' => 1.4,
        'batting_average' => 0.267,
        'on_base_percentage' => 0.338,
        'slugging_percentage' => 0.431,
        'ops' => 0.769,
        'team_era' => 3.72,
        'strikeouts_pitched_per_game' => 9.1,
        'whip' => 1.16,
        'strength_of_schedule' => 0.508,
        'recent_form_rating' => 0.55,
        'injury_adjusted_team_rating' => 1520,
        'rest_travel_fatigue' => 0.05,
        'calculation_date' => now()->toDateString(),
    ]);
    TeamMetric::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'wins' => 5,
        'losses' => 5,
        'offensive_rating' => 108,
        'pitching_rating' => 103,
        'defensive_rating' => 99,
        'runs_per_game' => 4.6,
        'runs_allowed_per_game' => 4.4,
        'run_differential_per_game' => 0.2,
        'home_runs_per_game' => 1.2,
        'batting_average' => 0.255,
        'on_base_percentage' => 0.324,
        'slugging_percentage' => 0.410,
        'ops' => 0.734,
        'team_era' => 4.02,
        'strikeouts_pitched_per_game' => 8.5,
        'whip' => 1.24,
        'strength_of_schedule' => 0.503,
        'recent_form_rating' => 0.49,
        'injury_adjusted_team_rating' => 1500,
        'rest_travel_fatigue' => 0.06,
        'calculation_date' => now()->toDateString(),
    ]);

    $homePitcher = Player::factory()->pitcher()->create([
        'team_id' => $homeTeam->id,
        'espn_id' => '99001',
    ]);
    $awayPitcher = Player::factory()->pitcher()->create([
        'team_id' => $awayTeam->id,
        'espn_id' => '99002',
    ]);

    PitcherEloRating::query()->create([
        'player_id' => $homePitcher->id,
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'date' => now()->toDateString(),
        'elo_rating' => 1600,
        'elo_change' => 6,
        'games_started' => 2,
    ]);
    PitcherEloRating::query()->create([
        'player_id' => $awayPitcher->id,
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'date' => now()->toDateString(),
        'elo_rating' => 1490,
        'elo_change' => -3,
        'games_started' => 2,
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'probable_home_pitcher_espn_id' => '99001',
        'probable_away_pitcher_espn_id' => '99002',
    ]);

    $healthyPrediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    PlayerInjury::query()->create([
        'player_id' => $homePitcher->id,
        'team_id' => $homeTeam->id,
        'injury_key' => '99001:out',
        'espn_injury_id' => 'inj-99001',
        'status' => 'Out',
        'detail' => 'Shoulder soreness',
        'type' => 'Shoulder',
        'injury_date' => now()->toDateString(),
        'source_updated_at' => now(),
        'is_active' => true,
    ]);

    $injuredPrediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    expect($healthyPrediction)->not->toBeNull()
        ->and($injuredPrediction)->not->toBeNull()
        ->and((float) $injuredPrediction->predicted_spread)->toBeLessThan((float) $healthyPrediction->predicted_spread)
        ->and((float) $injuredPrediction->predicted_total)->toBeGreaterThan((float) $healthyPrediction->predicted_total)
        ->and(data_get($injuredPrediction->model_metadata, 'pitcher_inputs.home_probable_pitcher_injury_status'))->toBe('Out')
        ->and((float) data_get($injuredPrediction->model_metadata, 'pitcher_inputs.probable_pitcher_spread_adjustment'))->toBeLessThan(0.0)
        ->and((float) data_get($injuredPrediction->model_metadata, 'pitcher_inputs.probable_pitcher_total_adjustment'))->toBeGreaterThan(0.0);
});

it('uses mlb depth chart starter as pitcher elo fallback before team recent average', function () {
    $homeTeam = Team::factory()->create(['elo_rating' => 1510]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1505]);

    $homeStarter = Player::factory()->pitcher()->create([
        'team_id' => $homeTeam->id,
        'espn_id' => '77001',
    ]);
    $awayStarter = Player::factory()->pitcher()->create([
        'team_id' => $awayTeam->id,
        'espn_id' => '77002',
    ]);

    DepthChartEntry::query()->create([
        'team_id' => $homeTeam->id,
        'player_id' => $homeStarter->id,
        'season' => 2026,
        'position_slot_key' => 'sp',
        'position_code' => 'SP',
        'position_name' => 'Starting Pitcher',
        'position_display_name' => 'Starting Pitcher',
        'espn_athlete_id' => '77001',
        'depth_rank' => 1,
        'is_starter' => true,
    ]);

    DepthChartEntry::query()->create([
        'team_id' => $awayTeam->id,
        'player_id' => $awayStarter->id,
        'season' => 2026,
        'position_slot_key' => 'sp',
        'position_code' => 'SP',
        'position_name' => 'Starting Pitcher',
        'position_display_name' => 'Starting Pitcher',
        'espn_athlete_id' => '77002',
        'depth_rank' => 1,
        'is_starter' => true,
    ]);

    PitcherEloRating::query()->create([
        'player_id' => $homeStarter->id,
        'team_id' => $homeTeam->id,
        'season' => 2025,
        'date' => '2025-09-28',
        'elo_rating' => 1588,
        'elo_change' => 5,
        'games_started' => 25,
    ]);

    PitcherEloRating::query()->create([
        'player_id' => $awayStarter->id,
        'team_id' => $awayTeam->id,
        'season' => 2025,
        'date' => '2025-09-28',
        'elo_rating' => 1472,
        'elo_change' => -2,
        'games_started' => 24,
    ]);

    PitcherEloRating::query()->create([
        'player_id' => Player::factory()->pitcher()->create(['team_id' => $homeTeam->id])->id,
        'team_id' => $homeTeam->id,
        'season' => 2025,
        'date' => '2025-09-20',
        'elo_rating' => 1500,
        'elo_change' => 0,
        'games_started' => 10,
    ]);

    PitcherEloRating::query()->create([
        'player_id' => Player::factory()->pitcher()->create(['team_id' => $awayTeam->id])->id,
        'team_id' => $awayTeam->id,
        'season' => 2025,
        'date' => '2025-09-20',
        'elo_rating' => 1502,
        'elo_change' => 0,
        'games_started' => 10,
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 13,
        'season_type' => config('mlb.season.types.regular'),
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-03-25',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'probable_home_pitcher_espn_id' => null,
        'probable_away_pitcher_espn_id' => null,
    ]);

    $prediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    expect($prediction)->not->toBeNull()
        ->and((float) $prediction->home_pitcher_elo)->toBe(1588.0)
        ->and((float) $prediction->away_pitcher_elo)->toBe(1472.0)
        ->and(data_get($prediction->model_metadata, 'pitcher_inputs.home_source'))->toBe('depth_chart_starter')
        ->and(data_get($prediction->model_metadata, 'pitcher_inputs.away_source'))->toBe('depth_chart_starter');
});
