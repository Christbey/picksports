<?php

use App\Actions\MLB\GeneratePrediction;
use App\Models\MLB\BullpenRating;
use App\Models\MLB\DepthChartEntry;
use App\Models\MLB\EloRating;
use App\Models\MLB\Game;
use App\Models\MLB\PitcherEloRating;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerInjury;
use App\Models\MLB\Team;
use App\Models\MLB\TeamMetric;
use App\Models\MLB\TeamStat;
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
        ->and($prediction->season)->toBe(2026)
        ->and($prediction->season_type)->toBe((string) config('mlb.season.types.regular', 2))
        ->and((float) $prediction->home_team_elo)->toBe(1540.0)
        ->and((float) $prediction->away_team_elo)->toBe(1495.0)
        ->and((float) $prediction->home_pitcher_elo)->toBe(1575.0)
        ->and((float) $prediction->away_pitcher_elo)->toBe(1480.0)
        ->and($prediction->model_version)->toBe('rules-v1')
        ->and($prediction->feature_version)->toBe('core-v3')
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
        'season_type' => '2',
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
    Config::set('mlb.prediction.home_field_advantage', 0);
    Config::set('mlb.elo.home_field_advantage', 0);
    Config::set('mlb.elo.team_weight', 1.0);
    Config::set('mlb.prediction.early_season.team_weight_start', 1.0);
    Config::set('mlb.prediction.early_season.context_scale_min', 0.0);
    Config::set('mlb.prediction.elo_diff_to_spread_divisor', 25.0);
    Config::set('mlb.prediction.spread_to_probability_coefficient', 2.0);
    Config::set('mlb.prediction.total_model.base_runs', 8.5);
    Config::set('mlb.prediction.total_model.average_elo_baseline', 1500.0);
    Config::set('mlb.prediction.total_model.average_elo_divisor', 50.0);
    Config::set('mlb.prediction.situational.advanced_ratings.enabled', false);
    Config::set('mlb.prediction.situational.starter_form.enabled', false);

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

it('applies mlb.prediction.home_field_advantage to spread independently of mlb.elo.home_field_advantage', function () {
    // Predictions use mlb.prediction.home_field_advantage; Elo updates use mlb.elo.home_field_advantage.
    // Setting the prediction key to 0 and the Elo key to 100 should produce a spread of 0 — proving
    // the prediction generator does NOT read the Elo HFA key once the prediction key is set.
    Config::set('mlb.prediction.home_field_advantage', 0);
    Config::set('mlb.elo.home_field_advantage', 100);
    Config::set('mlb.elo.team_weight', 1.0);
    Config::set('mlb.prediction.early_season.team_weight_start', 1.0);
    Config::set('mlb.prediction.early_season.context_scale_min', 0.0);
    Config::set('mlb.prediction.elo_diff_to_spread_divisor', 25.0);
    Config::set('mlb.prediction.situational.advanced_ratings.enabled', false);
    Config::set('mlb.prediction.situational.starter_form.enabled', false);

    $homeTeam = Team::factory()->create(['elo_rating' => 1500]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1500]);

    TeamMetric::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'season_type' => '2',
        'wins' => 10, 'losses' => 5,
        'recent_form_rating' => 0.0,
        'injury_adjusted_team_rating' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);
    TeamMetric::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'season_type' => '2',
        'wins' => 10, 'losses' => 5,
        'recent_form_rating' => 0.0,
        'injury_adjusted_team_rating' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    // Prior completed game so the historical metric path is satisfied.
    Game::factory()->create([
        'season' => 2026, 'week' => 13, 'game_date' => '2026-03-25',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id, 'away_team_id' => $awayTeam->id,
        'home_score' => 4, 'away_score' => 3,
    ]);
    $game = Game::factory()->create([
        'season' => 2026, 'week' => 13, 'game_date' => '2026-03-26',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id, 'away_team_id' => $awayTeam->id,
    ]);

    EloRating::query()->create([
        'team_id' => $homeTeam->id, 'season' => 2026, 'date' => '2026-03-25',
        'elo_rating' => 1500, 'elo_change' => 0,
    ]);
    EloRating::query()->create([
        'team_id' => $awayTeam->id, 'season' => 2026, 'date' => '2026-03-25',
        'elo_rating' => 1500, 'elo_change' => 0,
    ]);

    $prediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    expect($prediction)->not->toBeNull()
        ->and((float) $prediction->predicted_spread)->toBe(0.0);
});

it('falls back to mlb.elo.home_field_advantage when the prediction key is unset', function () {
    // Removing the prediction key entirely should cause the generator to read the legacy Elo key.
    Config::set('mlb.prediction.home_field_advantage', null);
    Config::set('mlb.elo.home_field_advantage', 50);
    Config::set('mlb.elo.team_weight', 1.0);
    Config::set('mlb.prediction.early_season.team_weight_start', 1.0);
    Config::set('mlb.prediction.early_season.context_scale_min', 0.0);
    Config::set('mlb.prediction.elo_diff_to_spread_divisor', 25.0);
    Config::set('mlb.prediction.situational.advanced_ratings.enabled', false);
    Config::set('mlb.prediction.situational.starter_form.enabled', false);

    $homeTeam = Team::factory()->create(['elo_rating' => 1500]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1500]);

    TeamMetric::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2026, 'season_type' => '2',
        'wins' => 10, 'losses' => 5,
        'recent_form_rating' => 0.0,
        'injury_adjusted_team_rating' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);
    TeamMetric::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2026, 'season_type' => '2',
        'wins' => 10, 'losses' => 5,
        'recent_form_rating' => 0.0,
        'injury_adjusted_team_rating' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    Game::factory()->create([
        'season' => 2026, 'week' => 13, 'game_date' => '2026-03-25',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id, 'away_team_id' => $awayTeam->id,
        'home_score' => 4, 'away_score' => 3,
    ]);
    $game = Game::factory()->create([
        'season' => 2026, 'week' => 13, 'game_date' => '2026-03-26',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id, 'away_team_id' => $awayTeam->id,
    ]);
    EloRating::query()->create([
        'team_id' => $homeTeam->id, 'season' => 2026, 'date' => '2026-03-25',
        'elo_rating' => 1500, 'elo_change' => 0,
    ]);
    EloRating::query()->create([
        'team_id' => $awayTeam->id, 'season' => 2026, 'date' => '2026-03-25',
        'elo_rating' => 1500, 'elo_change' => 0,
    ]);

    $prediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    // HFA 50 / divisor 25 = 2.0 runs home advantage.
    expect((float) $prediction->predicted_spread)->toBe(2.0);
});

it('applies a park factor to predicted_total when the venue is in mlb.prediction.park_factors', function () {
    Config::set('mlb.prediction.home_field_advantage', 0);
    Config::set('mlb.elo.home_field_advantage', 0);
    Config::set('mlb.elo.team_weight', 1.0);
    Config::set('mlb.prediction.early_season.team_weight_start', 1.0);
    Config::set('mlb.prediction.early_season.context_scale_min', 0.0);
    Config::set('mlb.prediction.elo_diff_to_spread_divisor', 25.0);
    Config::set('mlb.prediction.spread_to_probability_coefficient', 10.0);
    Config::set('mlb.prediction.total_model.base_runs', 9.0);
    Config::set('mlb.prediction.total_model.average_elo_baseline', 1500.0);
    Config::set('mlb.prediction.total_model.average_elo_divisor', 50.0);
    Config::set('mlb.prediction.situational.advanced_ratings.enabled', false);
    Config::set('mlb.prediction.situational.starter_form.enabled', false);
    Config::set('mlb.prediction.park_factors', ['Coors Field' => 1.5, 'Generic Park' => -0.7]);

    $homeTeam = Team::factory()->create(['elo_rating' => 1500]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1500]);
    $today = now()->toDateString();

    foreach ([$homeTeam, $awayTeam] as $team) {
        TeamMetric::query()->create([
            'team_id' => $team->id, 'season' => 2026, 'season_type' => '2',
            'wins' => 10, 'losses' => 5, 'recent_form_rating' => 0.0,
            'injury_adjusted_team_rating' => 1500.0, 'calculation_date' => $today,
        ]);
        EloRating::query()->create([
            'team_id' => $team->id, 'season' => 2026, 'date' => '2026-03-25',
            'elo_rating' => 1500, 'elo_change' => 0,
        ]);
    }

    Game::factory()->create([
        'season' => 2026, 'week' => 13, 'game_date' => '2026-03-25',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id, 'away_team_id' => $awayTeam->id,
        'home_score' => 4, 'away_score' => 3,
    ]);

    $coorsGame = Game::factory()->create([
        'season' => 2026, 'week' => 13, 'game_date' => '2026-03-26',
        'status' => 'STATUS_SCHEDULED', 'venue_name' => 'Coors Field',
        'home_team_id' => $homeTeam->id, 'away_team_id' => $awayTeam->id,
    ]);
    $neutralGame = Game::factory()->create([
        'season' => 2026, 'week' => 13, 'game_date' => '2026-03-26',
        'status' => 'STATUS_SCHEDULED', 'venue_name' => 'Unknown Park',
        'home_team_id' => $homeTeam->id, 'away_team_id' => $awayTeam->id,
    ]);

    $coorsPred = app(GeneratePrediction::class)->execute($coorsGame->fresh(['homeTeam', 'awayTeam']));
    $neutralPred = app(GeneratePrediction::class)->execute($neutralGame->fresh(['homeTeam', 'awayTeam']));

    expect((float) $coorsPred->predicted_total - (float) $neutralPred->predicted_total)->toBe(1.5);
    expect((float) data_get($coorsPred->model_metadata, 'park_context.total_adjustment'))->toBe(1.5);
    expect(data_get($coorsPred->model_metadata, 'park_context.venue_name'))->toBe('Coors Field');
    expect(data_get($coorsPred->model_metadata, 'park_context.run_environment'))->toBe('hitter_friendly');
    expect(data_get($coorsPred->model_metadata, 'park_context.runs_signal'))->toBe('park_runs_boost');
    expect(data_get($coorsPred->model_metadata, 'park_context.home_run_signal'))->toBe('park_home_run_boost');
    expect(data_get($coorsPred->model_metadata, 'park_context.win_signal'))->toBe('ballpark_can_amplify_matchup_variance');
    expect(data_get($coorsPred->model_metadata, 'park_context.weather_signal'))->toBe('weather_not_available');
    expect((float) data_get($neutralPred->model_metadata, 'park_context.total_adjustment'))->toBe(0.0);
    expect(data_get($neutralPred->model_metadata, 'park_context.venue_name'))->toBe('Unknown Park');
    expect(data_get($neutralPred->model_metadata, 'park_context.run_environment'))->toBe('neutral');
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
        ->and(data_get($prediction->model_metadata, 'pitcher_inputs.away_source'))->toBe('team_recent_average')
        ->and(data_get($prediction->model_metadata, 'historical_context.available'))->toBeTrue()
        ->and(abs((float) data_get($prediction->model_metadata, 'historical_context.spread_adjustment')))->toBeGreaterThan(0.0)
        ->and(abs((float) data_get($prediction->model_metadata, 'historical_context.total_adjustment')))->toBeGreaterThanOrEqual(0.0);
});

it('applies historical mlb priors more aggressively early than late in the season', function () {
    $homeTeam = Team::factory()->create(['elo_rating' => 1500]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1500]);

    TeamMetric::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2025,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'wins' => 95,
        'losses' => 67,
        'runs_per_game' => 5.3,
        'runs_allowed_per_game' => 3.9,
        'run_differential_per_game' => 1.4,
        'ops' => 0.815,
        'whip' => 1.12,
        'offensive_rating' => 115.0,
        'pitching_rating' => 109.0,
        'defensive_rating' => 104.0,
        'batting_average' => 0.266,
        'on_base_percentage' => 0.338,
        'slugging_percentage' => 0.447,
        'home_runs_per_game' => 1.4,
        'team_era' => 3.71,
        'strikeouts_pitched_per_game' => 9.1,
        'strength_of_schedule' => 1501,
        'recent_form_rating' => 0.1,
        'injury_adjusted_team_rating' => 1510,
        'rest_travel_fatigue' => 0.0,
        'calculation_date' => '2025-10-01',
    ]);

    TeamMetric::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2025,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'wins' => 68,
        'losses' => 94,
        'runs_per_game' => 4.0,
        'runs_allowed_per_game' => 5.2,
        'run_differential_per_game' => -1.2,
        'ops' => 0.691,
        'whip' => 1.36,
        'offensive_rating' => 98.0,
        'pitching_rating' => 94.0,
        'defensive_rating' => 96.0,
        'batting_average' => 0.238,
        'on_base_percentage' => 0.307,
        'slugging_percentage' => 0.384,
        'home_runs_per_game' => 1.0,
        'team_era' => 4.81,
        'strikeouts_pitched_per_game' => 7.8,
        'strength_of_schedule' => 1498,
        'recent_form_rating' => -0.2,
        'injury_adjusted_team_rating' => 1490,
        'rest_travel_fatigue' => 0.0,
        'calculation_date' => '2025-10-01',
    ]);

    TeamMetric::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'wins' => 2,
        'losses' => 1,
        'runs_per_game' => 4.6,
        'runs_allowed_per_game' => 4.2,
        'run_differential_per_game' => 0.4,
        'ops' => 0.742,
        'whip' => 1.25,
        'offensive_rating' => 107.0,
        'pitching_rating' => 101.0,
        'defensive_rating' => 100.0,
        'batting_average' => 0.251,
        'on_base_percentage' => 0.322,
        'slugging_percentage' => 0.420,
        'home_runs_per_game' => 1.2,
        'team_era' => 4.08,
        'strikeouts_pitched_per_game' => 8.6,
        'strength_of_schedule' => 1499,
        'recent_form_rating' => 0.0,
        'injury_adjusted_team_rating' => 1500,
        'rest_travel_fatigue' => 0.0,
        'calculation_date' => '2026-03-28',
    ]);

    TeamMetric::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'wins' => 1,
        'losses' => 2,
        'runs_per_game' => 4.1,
        'runs_allowed_per_game' => 4.8,
        'run_differential_per_game' => -0.7,
        'ops' => 0.704,
        'whip' => 1.31,
        'offensive_rating' => 101.0,
        'pitching_rating' => 97.0,
        'defensive_rating' => 98.0,
        'batting_average' => 0.244,
        'on_base_percentage' => 0.314,
        'slugging_percentage' => 0.394,
        'home_runs_per_game' => 1.0,
        'team_era' => 4.55,
        'strikeouts_pitched_per_game' => 8.2,
        'strength_of_schedule' => 1502,
        'recent_form_rating' => 0.0,
        'injury_adjusted_team_rating' => 1500,
        'rest_travel_fatigue' => 0.0,
        'calculation_date' => '2026-03-28',
    ]);

    $earlyGame = Game::factory()->create([
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'week' => 13,
        'game_date' => '2026-03-29',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $earlyPrediction = app(GeneratePrediction::class)->execute($earlyGame->fresh(['homeTeam', 'awayTeam']));

    TeamMetric::query()->where('team_id', $homeTeam->id)->where('season', 2026)->update([
        'wins' => 28,
        'losses' => 18,
    ]);
    TeamMetric::query()->where('team_id', $awayTeam->id)->where('season', 2026)->update([
        'wins' => 27,
        'losses' => 19,
    ]);

    Game::factory()->create([
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'week' => 17,
        'game_date' => '2026-04-15',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 5,
        'away_score' => 3,
    ]);

    $lateGame = Game::factory()->create([
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'week' => 20,
        'game_date' => '2026-05-20',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $latePrediction = app(GeneratePrediction::class)->execute($lateGame->fresh(['homeTeam', 'awayTeam']));

    expect($earlyPrediction)->not->toBeNull()
        ->and($latePrediction)->not->toBeNull()
        ->and((float) data_get($earlyPrediction->model_metadata, 'season_context.historical_context_weight'))
        ->toBeGreaterThan((float) data_get($latePrediction->model_metadata, 'season_context.historical_context_weight'))
        ->and(abs((float) data_get($earlyPrediction->model_metadata, 'historical_context.spread_adjustment')))
        ->toBeGreaterThan(abs((float) data_get($latePrediction->model_metadata, 'historical_context.spread_adjustment')));
});

it('applies bullpen fatigue against the more taxed team', function () {
    $homeTeam = Team::factory()->create(['elo_rating' => 1500]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1500]);

    TeamMetric::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'wins' => 10,
        'losses' => 8,
        'runs_per_game' => 4.7,
        'runs_allowed_per_game' => 4.4,
        'run_differential_per_game' => 0.3,
        'ops' => 0.735,
        'whip' => 1.24,
        'offensive_rating' => 108.0,
        'pitching_rating' => 101.0,
        'defensive_rating' => 100.0,
        'batting_average' => 0.252,
        'on_base_percentage' => 0.323,
        'slugging_percentage' => 0.412,
        'home_runs_per_game' => 1.2,
        'team_era' => 4.12,
        'strikeouts_pitched_per_game' => 8.8,
        'strength_of_schedule' => 1498,
        'recent_form_rating' => 0.0,
        'injury_adjusted_team_rating' => 1500,
        'rest_travel_fatigue' => 0.0,
        'calculation_date' => '2026-04-01',
    ]);
    TeamMetric::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'wins' => 10,
        'losses' => 8,
        'runs_per_game' => 4.7,
        'runs_allowed_per_game' => 4.4,
        'run_differential_per_game' => 0.3,
        'ops' => 0.735,
        'whip' => 1.24,
        'offensive_rating' => 108.0,
        'pitching_rating' => 101.0,
        'defensive_rating' => 100.0,
        'batting_average' => 0.252,
        'on_base_percentage' => 0.323,
        'slugging_percentage' => 0.412,
        'home_runs_per_game' => 1.2,
        'team_era' => 4.12,
        'strikeouts_pitched_per_game' => 8.8,
        'strength_of_schedule' => 1498,
        'recent_form_rating' => 0.0,
        'injury_adjusted_team_rating' => 1500,
        'rest_travel_fatigue' => 0.0,
        'calculation_date' => '2026-04-01',
    ]);

    foreach ([['2026-04-01', 7], ['2026-04-02', 6], ['2026-04-03', 6]] as [$date, $pitchersUsed]) {
        $game = Game::factory()->create([
            'season' => 2026,
            'season_type' => (string) config('mlb.season.types.regular', 2),
            'game_date' => $date,
            'status' => 'STATUS_FINAL',
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
        ]);

        TeamStat::factory()->create([
            'team_id' => $homeTeam->id,
            'game_id' => $game->id,
            'pitchers_used' => $pitchersUsed,
            'total_pitches' => 155,
        ]);
        TeamStat::factory()->create([
            'team_id' => $awayTeam->id,
            'game_id' => $game->id,
            'pitchers_used' => 3,
            'total_pitches' => 125,
        ]);
    }

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'week' => 14,
        'game_date' => '2026-04-04',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $prediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    expect($prediction)->not->toBeNull()
        ->and((float) data_get($prediction->model_metadata, 'situational_context.bullpen.home_fatigue'))->toBeGreaterThan(0.0)
        ->and((float) data_get($prediction->model_metadata, 'situational_context.bullpen.home_fatigue'))
        ->toBeGreaterThan((float) data_get($prediction->model_metadata, 'situational_context.bullpen.away_fatigue'))
        ->and((float) data_get($prediction->model_metadata, 'situational_context.bullpen.spread_adjustment'))->toBeLessThan(0.0)
        ->and((float) data_get($prediction->model_metadata, 'situational_context.bullpen.total_adjustment'))->toBeGreaterThan(0.0);
});

it('applies handedness proxy using roster balance against probable starter throwing hand', function () {
    $homeTeam = Team::factory()->create(['elo_rating' => 1500]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1500]);

    TeamMetric::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'wins' => 8,
        'losses' => 8,
        'runs_per_game' => 4.5,
        'runs_allowed_per_game' => 4.5,
        'run_differential_per_game' => 0.0,
        'ops' => 0.720,
        'whip' => 1.25,
        'offensive_rating' => 105.0,
        'pitching_rating' => 100.0,
        'defensive_rating' => 100.0,
        'batting_average' => 0.248,
        'on_base_percentage' => 0.319,
        'slugging_percentage' => 0.401,
        'home_runs_per_game' => 1.1,
        'team_era' => 4.20,
        'strikeouts_pitched_per_game' => 8.5,
        'strength_of_schedule' => 1500,
        'recent_form_rating' => 0.0,
        'injury_adjusted_team_rating' => 1500,
        'rest_travel_fatigue' => 0.0,
        'calculation_date' => '2026-04-01',
    ]);
    TeamMetric::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'wins' => 8,
        'losses' => 8,
        'runs_per_game' => 4.5,
        'runs_allowed_per_game' => 4.5,
        'run_differential_per_game' => 0.0,
        'ops' => 0.720,
        'whip' => 1.25,
        'offensive_rating' => 105.0,
        'pitching_rating' => 100.0,
        'defensive_rating' => 100.0,
        'batting_average' => 0.248,
        'on_base_percentage' => 0.319,
        'slugging_percentage' => 0.401,
        'home_runs_per_game' => 1.1,
        'team_era' => 4.20,
        'strikeouts_pitched_per_game' => 8.5,
        'strength_of_schedule' => 1500,
        'recent_form_rating' => 0.0,
        'injury_adjusted_team_rating' => 1500,
        'rest_travel_fatigue' => 0.0,
        'calculation_date' => '2026-04-01',
    ]);

    Player::factory()->count(6)->create([
        'team_id' => $homeTeam->id,
        'position' => 'OF',
        'batting_hand' => 'L',
    ]);
    Player::factory()->count(2)->create([
        'team_id' => $homeTeam->id,
        'position' => 'IF',
        'batting_hand' => 'S',
    ]);
    Player::factory()->count(6)->create([
        'team_id' => $awayTeam->id,
        'position' => 'OF',
        'batting_hand' => 'R',
    ]);
    Player::factory()->count(2)->create([
        'team_id' => $awayTeam->id,
        'position' => 'IF',
        'batting_hand' => 'L',
    ]);

    Player::factory()->pitcher()->create([
        'team_id' => $awayTeam->id,
        'espn_id' => 'hand-away',
        'throwing_hand' => 'R',
    ]);
    Player::factory()->pitcher()->create([
        'team_id' => $homeTeam->id,
        'espn_id' => 'hand-home',
        'throwing_hand' => 'L',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'week' => 14,
        'game_date' => '2026-04-04',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'probable_home_pitcher_espn_id' => 'hand-home',
        'probable_away_pitcher_espn_id' => 'hand-away',
    ]);

    $prediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    expect($prediction)->not->toBeNull()
        ->and((float) data_get($prediction->model_metadata, 'situational_context.handedness.home_edge'))->toBeGreaterThan(0.0)
        ->and((float) data_get($prediction->model_metadata, 'situational_context.handedness.away_edge'))->toBeGreaterThan(0.0)
        ->and((float) data_get($prediction->model_metadata, 'situational_context.handedness.spread_adjustment'))->toBeGreaterThan(0.0);
});

it('applies advanced team quality ratings from current ops and run prevention metrics', function () {
    $homeTeam = Team::factory()->create(['elo_rating' => 1500]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1500]);

    TeamMetric::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'season_type' => '2',
        'wins' => 12,
        'losses' => 8,
        'runs_per_game' => 5.4,
        'runs_allowed_per_game' => 3.9,
        'run_differential_per_game' => 1.5,
        'ops' => 0.805,
        'whip' => 1.10,
        'team_era' => 3.42,
        'recent_form_rating' => 0.0,
        'injury_adjusted_team_rating' => 1500.0,
        'calculation_date' => '2026-04-20',
    ]);

    TeamMetric::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'season_type' => '2',
        'wins' => 10,
        'losses' => 10,
        'runs_per_game' => 4.1,
        'runs_allowed_per_game' => 4.8,
        'run_differential_per_game' => -0.7,
        'ops' => 0.690,
        'whip' => 1.36,
        'team_era' => 4.82,
        'recent_form_rating' => 0.0,
        'injury_adjusted_team_rating' => 1500.0,
        'calculation_date' => '2026-04-20',
    ]);

    foreach (['2026-04-18', '2026-04-19'] as $date) {
        Game::factory()->create([
            'season' => 2026,
            'season_type' => '2',
            'game_date' => $date,
            'status' => 'STATUS_FINAL',
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'home_score' => 4,
            'away_score' => 3,
        ]);
    }

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => '2',
        'game_date' => '2026-04-21',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $prediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    expect($prediction)->not->toBeNull()
        ->and((float) data_get($prediction->model_metadata, 'situational_context.advanced_ratings.home_offense_score'))
        ->toBeGreaterThan((float) data_get($prediction->model_metadata, 'situational_context.advanced_ratings.away_offense_score'))
        ->and((float) data_get($prediction->model_metadata, 'situational_context.advanced_ratings.home_prevention_score'))
        ->toBeGreaterThan((float) data_get($prediction->model_metadata, 'situational_context.advanced_ratings.away_prevention_score'))
        ->and((float) data_get($prediction->model_metadata, 'situational_context.advanced_ratings.spread_adjustment'))
        ->toBeGreaterThan(0.0);
});

it('applies probable starter recent form trend from pitcher elo history', function () {
    $homeTeam = Team::factory()->create(['elo_rating' => 1500]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1500]);

    TeamMetric::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'season_type' => '2',
        'wins' => 12,
        'losses' => 9,
        'ops' => 0.720,
        'whip' => 1.25,
        'team_era' => 4.10,
        'runs_per_game' => 4.5,
        'runs_allowed_per_game' => 4.4,
        'recent_form_rating' => 0.0,
        'injury_adjusted_team_rating' => 1500.0,
        'calculation_date' => '2026-05-01',
    ]);
    TeamMetric::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'season_type' => '2',
        'wins' => 12,
        'losses' => 9,
        'ops' => 0.720,
        'whip' => 1.25,
        'team_era' => 4.10,
        'runs_per_game' => 4.5,
        'runs_allowed_per_game' => 4.4,
        'recent_form_rating' => 0.0,
        'injury_adjusted_team_rating' => 1500.0,
        'calculation_date' => '2026-05-01',
    ]);

    $homePitcher = Player::factory()->pitcher()->create([
        'team_id' => $homeTeam->id,
        'espn_id' => '66101',
    ]);
    $awayPitcher = Player::factory()->pitcher()->create([
        'team_id' => $awayTeam->id,
        'espn_id' => '66102',
    ]);

    foreach ([1500, 1510, 1540, 1580] as $index => $rating) {
        PitcherEloRating::query()->create([
            'player_id' => $homePitcher->id,
            'team_id' => $homeTeam->id,
            'season' => 2026,
            'date' => now()->subDays(5 - $index)->toDateString(),
            'elo_rating' => $rating,
            'elo_change' => 3,
            'games_started' => $index + 1,
        ]);
    }

    foreach ([1540, 1520, 1500, 1480] as $index => $rating) {
        PitcherEloRating::query()->create([
            'player_id' => $awayPitcher->id,
            'team_id' => $awayTeam->id,
            'season' => 2026,
            'date' => now()->subDays(5 - $index)->toDateString(),
            'elo_rating' => $rating,
            'elo_change' => -3,
            'games_started' => $index + 1,
        ]);
    }

    Game::factory()->create([
        'season' => 2026,
        'season_type' => '2',
        'game_date' => now()->subDay()->toDateString(),
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 4,
        'away_score' => 2,
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => '2',
        'game_date' => now()->toDateString(),
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'probable_home_pitcher_espn_id' => '66101',
        'probable_away_pitcher_espn_id' => '66102',
    ]);

    $prediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    expect($prediction)->not->toBeNull()
        ->and((float) data_get($prediction->model_metadata, 'situational_context.starter_form.home_score'))->toBeGreaterThan(0.0)
        ->and((float) data_get($prediction->model_metadata, 'situational_context.starter_form.away_score'))->toBeLessThan(0.0)
        ->and((float) data_get($prediction->model_metadata, 'situational_context.starter_form.spread_adjustment'))->toBeGreaterThan(0.0);
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
        ->and(abs((float) $prediction->predicted_spread))->toBeLessThan(1.5)
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
        'season_type' => '2',
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
        'game_date' => now()->addDay()->toDateString(),
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

it('uses raw total injury adjustments for mlb when persisted spread context exists without persisted total context', function () {
    Config::set('mlb.prediction.situational.advanced_ratings.enabled', false);
    Config::set('mlb.prediction.situational.starter_form.enabled', false);
    Config::set('mlb.prediction.situational.bullpen_quality.enabled', false);

    $homeTeam = Team::factory()->create(['elo_rating' => 1510]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1495]);

    TeamMetric::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'season_type' => '2',
        'wins' => 8,
        'losses' => 7,
        'recent_form_rating' => 0.2,
        'injury_adjusted_team_rating' => 1512.0,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'season_type' => '2',
        'wins' => 8,
        'losses' => 7,
        'recent_form_rating' => 0.1,
        'injury_adjusted_team_rating' => 1494.0,
        'calculation_date' => now()->toDateString(),
    ]);

    Game::factory()->create([
        'season' => 2026,
        'season_type' => '2',
        'game_date' => now()->subDay(),
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 4,
        'away_score' => 3,
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => '2',
        'game_date' => now()->addDay(),
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $baselinePrediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $player = Player::factory()->create(['team_id' => $homeTeam->id]);
    PlayerInjury::query()->create([
        'player_id' => $player->id,
        'team_id' => $homeTeam->id,
        'injury_key' => 'mlb-total-raw',
        'espn_injury_id' => 'inj-mlb-total-raw',
        'status' => 'Out',
        'detail' => 'Hamstring',
        'type' => 'Leg',
        'injury_date' => now()->toDateString(),
        'source_updated_at' => now(),
        'is_active' => true,
    ]);

    $injuryPrediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    expect($injuryPrediction)->not->toBeNull()
        ->and((float) $injuryPrediction->predicted_spread)->toBe((float) $baselinePrediction->predicted_spread)
        ->and((float) $injuryPrediction->predicted_total)->toBeLessThan((float) $baselinePrediction->predicted_total)
        ->and(data_get($injuryPrediction->model_metadata, 'injury_model_source'))->toBe('mixed')
        ->and(data_get($injuryPrediction->model_metadata, 'injury_total_model_source'))->toBe('raw_player_status')
        ->and((float) data_get($injuryPrediction->model_metadata, 'depth_chart_injuries.total_adjustment'))->toBeLessThan(0.0);
});

it('uses persisted total injury adjustments for mlb when available on team metrics', function () {
    Config::set('mlb.prediction.situational.advanced_ratings.enabled', false);
    Config::set('mlb.prediction.situational.starter_form.enabled', false);
    Config::set('mlb.prediction.situational.bullpen_quality.enabled', false);

    $homeTeam = Team::factory()->create(['elo_rating' => 1510]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1495]);

    TeamMetric::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'season_type' => '2',
        'wins' => 8,
        'losses' => 7,
        'recent_form_rating' => 0.2,
        'injury_adjusted_team_rating' => 1512.0,
        'injury_total_adjustment' => -0.8,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'season_type' => '2',
        'wins' => 8,
        'losses' => 7,
        'recent_form_rating' => 0.1,
        'injury_adjusted_team_rating' => 1494.0,
        'injury_total_adjustment' => 0.0,
        'calculation_date' => now()->toDateString(),
    ]);

    Game::factory()->create([
        'season' => 2026,
        'season_type' => '2',
        'game_date' => now()->subDay(),
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 4,
        'away_score' => 3,
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => '2',
        'game_date' => now()->addDay(),
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $baselinePrediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $player = Player::factory()->create(['team_id' => $homeTeam->id]);
    PlayerInjury::query()->create([
        'player_id' => $player->id,
        'team_id' => $homeTeam->id,
        'injury_key' => 'mlb-total-persisted',
        'espn_injury_id' => 'inj-mlb-total-persisted',
        'status' => 'Out',
        'detail' => 'Hamstring',
        'type' => 'Leg',
        'injury_date' => now()->toDateString(),
        'source_updated_at' => now(),
        'is_active' => true,
    ]);

    $injuryPrediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    expect($injuryPrediction)->not->toBeNull()
        ->and((float) $injuryPrediction->predicted_spread)->toBe((float) $baselinePrediction->predicted_spread)
        ->and((float) $injuryPrediction->predicted_total)->toBe((float) $baselinePrediction->predicted_total)
        ->and(data_get($injuryPrediction->model_metadata, 'injury_model_source'))->toBe('persisted_team_rating')
        ->and(data_get($injuryPrediction->model_metadata, 'injury_total_model_source'))->toBe('persisted_team_rating')
        ->and((float) data_get($injuryPrediction->model_metadata, 'depth_chart_injuries.total_adjustment'))->toBe(-0.8);
});

it('ignores injuries recorded after the historical game date when backfilling mlb predictions', function () {
    $homeTeam = Team::factory()->create(['elo_rating' => 1520]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1495]);

    TeamMetric::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2025,
        'season_type' => '2',
        'wins' => 30,
        'losses' => 25,
        'recent_form_rating' => 0.2,
        'injury_adjusted_team_rating' => 1522.0,
        'calculation_date' => '2025-06-14',
    ]);
    TeamMetric::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2025,
        'season_type' => '2',
        'wins' => 27,
        'losses' => 28,
        'recent_form_rating' => 0.1,
        'injury_adjusted_team_rating' => 1494.0,
        'calculation_date' => '2025-06-14',
    ]);

    $homePitcher = Player::factory()->pitcher()->create([
        'team_id' => $homeTeam->id,
        'espn_id' => '88001',
    ]);
    $awayPitcher = Player::factory()->pitcher()->create([
        'team_id' => $awayTeam->id,
        'espn_id' => '88002',
    ]);

    PitcherEloRating::query()->create([
        'player_id' => $homePitcher->id,
        'team_id' => $homeTeam->id,
        'season' => 2025,
        'date' => '2025-06-14',
        'elo_rating' => 1560,
        'elo_change' => 5,
        'games_started' => 10,
    ]);
    PitcherEloRating::query()->create([
        'player_id' => $awayPitcher->id,
        'team_id' => $awayTeam->id,
        'season' => 2025,
        'date' => '2025-06-14',
        'elo_rating' => 1485,
        'elo_change' => -2,
        'games_started' => 10,
    ]);

    Game::factory()->create([
        'season' => 2025,
        'season_type' => '2',
        'game_date' => '2025-06-14',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 5,
        'away_score' => 4,
    ]);

    $game = Game::factory()->create([
        'season' => 2025,
        'season_type' => '2',
        'game_date' => '2025-06-15',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 6,
        'away_score' => 3,
        'probable_home_pitcher_espn_id' => '88001',
        'probable_away_pitcher_espn_id' => '88002',
    ]);

    $baselinePrediction = app(GeneratePrediction::class)->executeHistorical($game->fresh(['homeTeam', 'awayTeam']));

    PlayerInjury::query()->create([
        'player_id' => $homePitcher->id,
        'team_id' => $homeTeam->id,
        'injury_key' => 'future-historical-injury',
        'espn_injury_id' => 'inj-future-historical',
        'status' => 'Out',
        'detail' => 'Shoulder soreness',
        'type' => 'Shoulder',
        'injury_date' => '2025-06-20',
        'return_date' => '2025-07-01',
        'source_updated_at' => '2025-06-20 12:00:00',
        'is_active' => true,
    ]);

    $historicalPrediction = app(GeneratePrediction::class)->executeHistorical($game->fresh(['homeTeam', 'awayTeam']));

    expect($baselinePrediction)->not->toBeNull()
        ->and($historicalPrediction)->not->toBeNull()
        ->and((float) $historicalPrediction->predicted_spread)->toBe((float) $baselinePrediction->predicted_spread)
        ->and((float) $historicalPrediction->predicted_total)->toBe((float) $baselinePrediction->predicted_total)
        ->and(data_get($historicalPrediction->model_metadata, 'pitcher_inputs.home_probable_pitcher_injury_status'))->toBeNull();
});

it('uses historical probable starter elo even if the pitcher is now on a different team', function () {
    $oldHomeTeam = Team::factory()->create(['elo_rating' => 1510]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1490]);
    $newTeam = Team::factory()->create(['elo_rating' => 1505]);

    TeamMetric::query()->create([
        'team_id' => $oldHomeTeam->id,
        'season' => 2025,
        'season_type' => '2',
        'wins' => 40,
        'losses' => 30,
        'recent_form_rating' => 0.25,
        'injury_adjusted_team_rating' => 1512.0,
        'calculation_date' => '2025-07-09',
    ]);
    TeamMetric::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2025,
        'season_type' => '2',
        'wins' => 38,
        'losses' => 32,
        'recent_form_rating' => 0.18,
        'injury_adjusted_team_rating' => 1489.0,
        'calculation_date' => '2025-07-09',
    ]);

    $movedPitcher = Player::factory()->pitcher()->create([
        'team_id' => $newTeam->id,
        'espn_id' => '77101',
        'throwing_hand' => 'R',
    ]);
    $awayPitcher = Player::factory()->pitcher()->create([
        'team_id' => $awayTeam->id,
        'espn_id' => '77102',
    ]);

    PitcherEloRating::query()->create([
        'player_id' => $movedPitcher->id,
        'team_id' => $oldHomeTeam->id,
        'season' => 2025,
        'date' => '2025-07-09',
        'elo_rating' => 1588,
        'elo_change' => 4,
        'games_started' => 15,
    ]);
    PitcherEloRating::query()->create([
        'player_id' => $awayPitcher->id,
        'team_id' => $awayTeam->id,
        'season' => 2025,
        'date' => '2025-07-09',
        'elo_rating' => 1491,
        'elo_change' => -1,
        'games_started' => 15,
    ]);

    Game::factory()->create([
        'season' => 2025,
        'season_type' => '2',
        'game_date' => '2025-07-09',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $oldHomeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 2,
        'away_score' => 1,
    ]);

    $game = Game::factory()->create([
        'season' => 2025,
        'season_type' => '2',
        'game_date' => '2025-07-10',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $oldHomeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 4,
        'away_score' => 2,
        'probable_home_pitcher_espn_id' => '77101',
        'probable_away_pitcher_espn_id' => '77102',
    ]);

    $prediction = app(GeneratePrediction::class)->executeHistorical($game->fresh(['homeTeam', 'awayTeam']));

    expect($prediction)->not->toBeNull()
        ->and((float) $prediction->home_pitcher_elo)->toBe(1588.0)
        ->and(data_get($prediction->model_metadata, 'pitcher_inputs.home_source'))->toBe('probable_starter');
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

it('applies persisted bullpen quality ratings in the mlb situational context', function () {
    Config::set('mlb.prediction.historical_priors.enabled', false);
    Config::set('mlb.prediction.situational.bullpen.spread_weight', 0.0);
    Config::set('mlb.prediction.situational.bullpen.total_weight', 0.0);
    Config::set('mlb.prediction.situational.handedness.spread_weight', 0.0);
    Config::set('mlb.prediction.situational.handedness.total_weight', 0.0);
    Config::set('mlb.prediction.situational.advanced_ratings.enabled', false);
    Config::set('mlb.prediction.situational.starter_form.enabled', false);
    Config::set('mlb.prediction.situational.bullpen_quality.enabled', false);
    Config::set('mlb.prediction.situational.bullpen_quality.total_weight', 0.30);
    Config::set('mlb.prediction.situational.bullpen_quality.score_divisor', 10.0);

    $homeTeam = Team::factory()->create(['elo_rating' => 1510]);
    $awayTeam = Team::factory()->create(['elo_rating' => 1510]);

    foreach ([$homeTeam, $awayTeam] as $team) {
        TeamMetric::query()->create([
            'team_id' => $team->id,
            'season' => 2026,
            'season_type' => (string) config('mlb.season.types.regular'),
            'wins' => 5,
            'losses' => 5,
            'offensive_rating' => 110,
            'pitching_rating' => 105,
            'defensive_rating' => 100,
            'runs_per_game' => 4.4,
            'runs_allowed_per_game' => 4.4,
            'run_differential_per_game' => 0.0,
            'home_runs_per_game' => 1.2,
            'batting_average' => 0.250,
            'on_base_percentage' => 0.320,
            'slugging_percentage' => 0.400,
            'ops' => 0.720,
            'team_era' => 4.10,
            'strikeouts_pitched_per_game' => 8.8,
            'whip' => 1.28,
            'strength_of_schedule' => 0.500,
            'recent_form_rating' => 0.0,
            'injury_adjusted_team_rating' => 1500,
            'injury_total_adjustment' => 0.0,
            'rest_travel_fatigue' => 0.0,
            'calculation_date' => now()->toDateString(),
        ]);
    }

    $homePitcher = Player::factory()->pitcher()->create([
        'team_id' => $homeTeam->id,
        'espn_id' => '99101',
    ]);
    $awayPitcher = Player::factory()->pitcher()->create([
        'team_id' => $awayTeam->id,
        'espn_id' => '99102',
    ]);

    PitcherEloRating::query()->create([
        'player_id' => $homePitcher->id,
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'date' => '2026-04-02',
        'elo_rating' => 1500,
        'elo_change' => 0,
        'games_started' => 1,
    ]);
    PitcherEloRating::query()->create([
        'player_id' => $awayPitcher->id,
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'date' => '2026-04-02',
        'elo_rating' => 1500,
        'elo_change' => 0,
        'games_started' => 1,
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-04-03',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'probable_home_pitcher_espn_id' => '99101',
        'probable_away_pitcher_espn_id' => '99102',
    ]);

    $baselinePrediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    BullpenRating::query()->create([
        'team_id' => $homeTeam->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular'),
        'as_of_date' => '2026-04-03',
        'games_sampled' => 6,
        'weighted_usage' => 0.75,
        'weighted_era' => 2.900,
        'weighted_whip' => 1.020,
        'strikeouts_per_nine' => 10.400,
        'walks_per_nine' => 2.600,
        'home_runs_per_nine' => 0.700,
        'recent_form_score' => 1.100,
        'workload_penalty' => 0.300,
        'rating_score' => 116.000,
        'rating_rank' => 2,
        'calculation_date' => now()->toDateString(),
    ]);
    BullpenRating::query()->create([
        'team_id' => $awayTeam->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular'),
        'as_of_date' => '2026-04-03',
        'games_sampled' => 6,
        'weighted_usage' => 0.72,
        'weighted_era' => 4.800,
        'weighted_whip' => 1.410,
        'strikeouts_per_nine' => 7.400,
        'walks_per_nine' => 4.100,
        'home_runs_per_nine' => 1.500,
        'recent_form_score' => -0.600,
        'workload_penalty' => 0.900,
        'rating_score' => 88.000,
        'rating_rank' => 25,
        'calculation_date' => now()->toDateString(),
    ]);

    Config::set('mlb.prediction.situational.bullpen_quality.enabled', true);

    $bullpenPrediction = app(GeneratePrediction::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    expect($baselinePrediction)->not->toBeNull()
        ->and($bullpenPrediction)->not->toBeNull()
        ->and((float) $bullpenPrediction->predicted_spread)->toBeGreaterThan((float) $baselinePrediction->predicted_spread)
        ->and(data_get($bullpenPrediction->model_metadata, 'situational_context.bullpen_quality.home_source'))->toBe('persisted')
        ->and(data_get($bullpenPrediction->model_metadata, 'situational_context.bullpen_quality.away_source'))->toBe('persisted')
        ->and((float) data_get($bullpenPrediction->model_metadata, 'situational_context.bullpen_quality.home_rating'))->toBe(116.0)
        ->and((float) data_get($bullpenPrediction->model_metadata, 'situational_context.bullpen_quality.away_rating'))->toBe(88.0)
        ->and((float) data_get($bullpenPrediction->model_metadata, 'situational_context.bullpen_quality.spread_adjustment'))->toBeGreaterThan(0.0)
        ->and((float) data_get($bullpenPrediction->model_metadata, 'situational_context.bullpen_quality.total_adjustment'))->toBeLessThan(0.0);
});
