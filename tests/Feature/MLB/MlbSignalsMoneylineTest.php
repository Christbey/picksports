<?php

use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses()->group('mlb', 'signals');

it('treats mlb as moneyline ready when h2h exists without run line or totals', function () {
    Carbon::setTestNow('2026-05-23 10:00:00');

    config([
        'mlb.season.default' => 2026,
        'mlb.signals.bet_filter.moneyline_enabled' => true,
        'mlb.signals.bet_filter.run_line_enabled' => false,
        'mlb.signals.bet_filter.total_enabled' => false,
    ]);

    Permission::findOrCreate('view-mlb-predictions', 'web');
    $user = User::factory()->create();
    $user->givePermissionTo('view-mlb-predictions');
    Sanctum::actingAs($user);

    $homeTeam = Team::factory()->create([
        'location' => 'St. Louis',
        'name' => 'Cardinals',
        'abbreviation' => 'STL',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Chicago',
        'name' => 'Cubs',
        'abbreviation' => 'CHC',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'game_date' => '2026-05-24',
        'game_time' => '18:15:00',
        'status' => config('mlb.statuses.scheduled'),
        'venue_name' => 'Coors Field',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'short_name' => 'CHC @ STL',
        'odds_data' => [
            'bookmakers' => [[
                'key' => 'draftkings',
                'markets' => [[
                    'key' => 'h2h',
                    'outcomes' => [
                        ['name' => 'St. Louis Cardinals', 'price' => 110],
                        ['name' => 'Chicago Cubs', 'price' => -120],
                    ],
                ]],
            ]],
        ],
        'odds_updated_at' => now(),
    ]);

    $liveGame = Game::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'game_date' => '2026-05-24',
        'game_time' => '16:05:00',
        'status' => config('mlb.statuses.in_progress'),
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'short_name' => 'CHC @ STL',
        'odds_data' => [
            'bookmakers' => [[
                'key' => 'draftkings',
                'markets' => [[
                    'key' => 'h2h',
                    'outcomes' => [
                        ['name' => 'St. Louis Cardinals', 'price' => 650],
                        ['name' => 'Chicago Cubs', 'price' => -1340],
                    ],
                ]],
            ]],
        ],
        'odds_updated_at' => now(),
    ]);

    Prediction::query()->create([
        'game_id' => $game->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular'),
        'home_team_elo' => 1510,
        'away_team_elo' => 1490,
        'home_pitcher_elo' => 1525,
        'away_pitcher_elo' => 1480,
        'home_combined_elo' => 1520,
        'away_combined_elo' => 1485,
        'predicted_spread' => 1.6,
        'predicted_total' => 8.2,
        'win_probability' => 0.62,
        'confidence_score' => 64,
        'vegas_spread' => null,
        'model_version' => 'test',
        'feature_version' => 'test',
        'blend_version' => 'test',
        'model_metadata' => [
            'pitcher_inputs' => [
                'home_source' => 'probable_starter',
                'away_source' => 'probable_starter',
                'home_confidence' => 1.0,
                'away_confidence' => 1.0,
            ],
            'park_context' => [
                'venue_name' => 'Coors Field',
                'total_adjustment' => 0.9,
                'run_environment' => 'hitter_friendly',
                'runs_signal' => 'park_runs_boost',
                'home_run_signal' => 'park_home_run_boost',
                'win_signal' => 'ballpark_can_amplify_matchup_variance',
                'weather_signal' => 'weather_not_available',
            ],
        ],
    ]);

    Prediction::query()->create([
        'game_id' => $liveGame->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular'),
        'home_team_elo' => 1510,
        'away_team_elo' => 1490,
        'home_pitcher_elo' => 1525,
        'away_pitcher_elo' => 1480,
        'home_combined_elo' => 1520,
        'away_combined_elo' => 1485,
        'predicted_spread' => 1.6,
        'predicted_total' => 8.2,
        'win_probability' => 0.62,
        'confidence_score' => 64,
        'vegas_spread' => null,
        'model_version' => 'test',
        'feature_version' => 'test',
        'blend_version' => 'test',
        'model_metadata' => [
            'pitcher_inputs' => [
                'home_source' => 'probable_starter',
                'away_source' => 'probable_starter',
                'home_confidence' => 1.0,
                'away_confidence' => 1.0,
            ],
        ],
    ]);

    $response = $this->getJson('/api/v1/mlb/signals?season=2026&as_of_date=2026-05-23');

    $response->assertOk()
        ->assertJsonPath('data.odds_health.status', 'moneyline_ready')
        ->assertJsonPath('data.odds_health.primary_market', 'moneyline')
        ->assertJsonPath('data.odds_health.moneyline_ready', true)
        ->assertJsonPath('data.odds_health.moneyline_coverage', 100)
        ->assertJsonPath('data.odds_health.run_line_coverage', 0)
        ->assertJsonPath('data.odds_health.total_coverage', 0)
        ->assertJsonPath('data.bet_filter.mode', 'moneyline_first')
        ->assertJsonPath('data.moneyline_readiness.candidate_count', 1)
        ->assertJsonPath('data.moneyline_readiness.priced_count', 1)
        ->assertJsonPath('data.moneyline_readiness.usable_count', 1)
        ->assertJsonPath('data.recommended_bets.0.type', 'moneyline')
        ->assertJsonPath('data.recommended_bets.0.classification', 'bet')
        ->assertJsonPath('data.ballpark.0.venue_name', 'Coors Field')
        ->assertJsonPath('data.ballpark.0.run_environment', 'hitter_friendly')
        ->assertJsonPath('data.ballpark.0.runs_signal', 'park_runs_boost')
        ->assertJsonPath('data.ballpark.0.home_run_signal', 'park_home_run_boost')
        ->assertJsonPath('data.ballpark.0.win_signal', 'ballpark_can_amplify_matchup_variance')
        ->assertJsonPath('data.ballpark.0.weather_signal', 'weather_not_available');

    Carbon::setTestNow();
});
