<?php

use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use Illuminate\Support\Facades\Artisan;

uses()->group('mlb', 'commands');

it('writes an mlb calibration report with market metrics', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'New York',
        'name' => 'Mets',
        'abbreviation' => 'NYM',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Atlanta',
        'name' => 'Braves',
        'abbreviation' => 'ATL',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-03-31',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 6,
        'away_score' => 4,
        'odds_data' => [
            'bookmakers' => [[
                'markets' => [[
                    'key' => 'totals',
                    'outcomes' => [
                        ['name' => 'Over', 'point' => 8.5],
                        ['name' => 'Under', 'point' => 8.5],
                    ],
                ]],
            ]],
        ],
    ]);

    Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => 1.5,
        'predicted_total' => 9.2,
        'win_probability' => 0.59,
        'confidence_score' => 59.0,
        'vegas_spread' => -1.5,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
        'model_metadata' => [
            'market_context' => [
                'market_total' => 8.5,
            ],
        ],
        'actual_spread' => 2.0,
        'actual_total' => 10.0,
        'spread_error' => 0.5,
        'total_error' => 0.8,
        'winner_correct' => true,
        'graded_at' => '2026-04-01 01:00:00',
    ]);

    $output = storage_path('app/ml/reports/mlb_calibration_report_test.json');
    @unlink($output);

    Artisan::call('mlb:report-calibration', [
        '--season' => 2026,
        '--output' => $output,
    ]);

    $report = json_decode(file_get_contents($output), true);

    expect($report)->toBeArray()
        ->and($report['report_type'])->toBe('mlb_prediction_calibration')
        ->and($report['season'])->toBe(2026)
        ->and($report['summary']['count'])->toBe(1)
        ->and((float) $report['summary']['winner_accuracy'])->toBe(100.0)
        ->and((float) $report['summary']['spread_mae'])->toBe(0.5)
        ->and((float) $report['summary']['total_mae'])->toBe(0.8)
        // vegas_spread is Vegas convention (-1.5 = home favored by 1.5); actual_margin = +2, predicted_spread = +1.5.
        // market_spread_mae compares actual_margin vs the Vegas-implied home margin (= -vegas_spread).
        // |2 - 1.5| = 0.5; predicted - implied = 1.5 - 1.5 = 0.0
        ->and((float) $report['summary']['market_spread_mae'])->toBe(0.5)
        ->and((float) $report['summary']['market_total_mae'])->toBe(1.5)
        ->and((float) $report['summary']['spread_bias_vs_market'])->toBe(0.0)
        ->and((float) $report['summary']['total_bias_vs_market'])->toBe(0.7)
        ->and($report['confidence_buckets'])->toHaveCount(1)
        ->and($report['public_recommendation_buckets'])->toBeArray()
        ->and($report['candidate_recommendation_buckets'])->toBeArray()
        ->and($report['recommendation_buckets'])->toBe($report['candidate_recommendation_buckets']);
});

it('explains when strict pregame calibration excludes every graded row', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'New York',
        'name' => 'Mets',
        'abbreviation' => 'NYM',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Atlanta',
        'name' => 'Braves',
        'abbreviation' => 'ATL',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-03-31',
        'game_time' => '19:10:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 6,
        'away_score' => 4,
    ]);

    Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => 1.5,
        'predicted_total' => 9.2,
        'win_probability' => 0.59,
        'confidence_score' => 59.0,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
        'actual_spread' => 2.0,
        'actual_total' => 10.0,
        'spread_error' => 0.5,
        'total_error' => 0.8,
        'winner_correct' => true,
        'graded_at' => '2026-04-01 01:00:00',
        'created_at' => '2026-03-31 12:00:00',
    ]);

    Artisan::call('mlb:report-calibration', [
        '--season' => 2026,
        '--strict-pregame' => true,
    ]);

    $output = Artisan::output();

    expect($output)->toContain('No strict-pregame eligible MLB predictions found')
        ->and($output)->toContain('Graded candidate rows inspected: 1')
        ->and($output)->toContain('missing_pregame_safe_market_context');
});

it('emits mlb underperformance diagnostics as json', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'New York',
        'name' => 'Mets',
        'abbreviation' => 'NYM',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Atlanta',
        'name' => 'Braves',
        'abbreviation' => 'ATL',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-05-10',
        'game_time' => '19:10:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 6,
        'away_score' => 4,
        'odds_data' => [
            'home_team' => 'New York Mets',
            'away_team' => 'Atlanta Braves',
            'bookmakers' => [[
                'markets' => [
                    [
                        'key' => 'h2h',
                        'outcomes' => [
                            ['name' => 'New York Mets', 'price' => -120],
                            ['name' => 'Atlanta Braves', 'price' => 105],
                        ],
                    ],
                    [
                        'key' => 'totals',
                        'outcomes' => [
                            ['name' => 'Over', 'point' => 8.5],
                            ['name' => 'Under', 'point' => 8.5],
                        ],
                    ],
                ],
            ]],
        ],
    ]);

    Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => 1.5,
        'predicted_total' => 9.2,
        'win_probability' => 0.59,
        'confidence_score' => 59.0,
        'vegas_spread' => -1.5,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
        'model_metadata' => [
            'market_context' => ['market_total' => 8.5],
            'pitcher_inputs' => [
                'home_source' => 'probable_starter',
                'away_source' => 'team_recent_average',
            ],
            'park_context' => ['total_adjustment' => 0.4],
            'actual_weather' => ['total_adjustment' => 0.1],
        ],
        'actual_spread' => 2.0,
        'actual_total' => 10.0,
        'spread_error' => 0.5,
        'total_error' => 0.8,
        'winner_correct' => true,
        'graded_at' => '2026-05-11 01:00:00',
        'created_at' => '2026-05-10 12:00:00',
    ]);

    Artisan::call('mlb:report-calibration', [
        '--season' => 2026,
        '--diagnostics' => true,
        '--compare-market' => true,
        '--json' => true,
    ]);

    $report = json_decode(Artisan::output(), true);

    expect($report['diagnostics'])->toBeArray()
        ->and($report['diagnostics']['baselines'])->toHaveCount(3)
        ->and($report['diagnostics']['winner_breakdowns']['by_pick_side'][0]['label'])->toBe('home')
        ->and($report['diagnostics']['pitcher_source_breakdowns'][0]['label'])->toBe('team_recent_average_fallback')
        ->and($report['diagnostics']['bug_checks'])->not->toBeEmpty();
});

it('does not flag rounded pickem probabilities as winner inversions', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'New York',
        'name' => 'Mets',
        'abbreviation' => 'NYM',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Atlanta',
        'name' => 'Braves',
        'abbreviation' => 'ATL',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-05-10',
        'game_time' => '19:10:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 3,
        'away_score' => 4,
    ]);

    Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => -0.1,
        'predicted_total' => 8.5,
        'win_probability' => 0.5,
        'confidence_score' => 50.2,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
        'model_metadata' => [],
        'actual_spread' => -1.0,
        'actual_total' => 7.0,
        'spread_error' => 0.9,
        'total_error' => 1.5,
        'winner_correct' => true,
        'graded_at' => '2026-05-11 01:00:00',
        'created_at' => '2026-05-10 12:00:00',
    ]);

    Artisan::call('mlb:report-calibration', [
        '--season' => 2026,
        '--diagnostics' => true,
        '--json' => true,
    ]);

    $report = json_decode(Artisan::output(), true);
    $winnerInversion = collect($report['diagnostics']['bug_checks'])
        ->firstWhere('check', 'Winner inversion');

    expect($report['diagnostics']['winner_breakdowns']['by_pick_side'][0]['label'])->toBe('away')
        ->and($winnerInversion['result'])->toBe('pass')
        ->and($winnerInversion['evidence'])->toContain('0 row(s)');
});

it('fails mlb recommendation readiness when promotion evidence is insufficient', function () {
    config([
        'mlb.signals.recommendation_readiness.min_candidate_rows' => 1,
        'mlb.signals.recommendation_readiness.min_candidate_accuracy' => 52.5,
    ]);

    $homeTeam = Team::factory()->create([
        'location' => 'New York',
        'name' => 'Mets',
        'abbreviation' => 'NYM',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Atlanta',
        'name' => 'Braves',
        'abbreviation' => 'ATL',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-05-10',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 4,
        'away_score' => 6,
        'odds_data' => [
            'home_team' => 'New York Mets',
            'away_team' => 'Atlanta Braves',
            'bookmakers' => [[
                'markets' => [[
                    'key' => 'h2h',
                    'outcomes' => [
                        ['name' => 'New York Mets', 'price' => 120],
                        ['name' => 'Atlanta Braves', 'price' => -130],
                    ],
                ]],
            ]],
        ],
        'odds_updated_at' => '2026-05-10 12:00:00',
    ]);

    Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => 1.7,
        'predicted_total' => 9.2,
        'win_probability' => 0.59,
        'confidence_score' => 59.0,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
        'model_metadata' => [
            'pitcher_inputs' => [
                'home_source' => 'probable_starter',
                'away_source' => 'probable_starter',
                'home_confidence' => 1.0,
                'away_confidence' => 1.0,
            ],
        ],
        'actual_spread' => -2.0,
        'actual_total' => 10.0,
        'spread_error' => 3.7,
        'total_error' => 0.8,
        'winner_correct' => false,
        'graded_at' => '2026-05-11 01:00:00',
    ]);

    Artisan::call('mlb:validate-recommendation-readiness', [
        '--season' => 2026,
        '--min-rows' => 10,
        '--json' => true,
    ]);

    $report = json_decode(Artisan::output(), true);

    expect($report['status'])->toBe('fail')
        ->and($report['ready'])->toBeFalse()
        ->and($report['summary']['rows'])->toBe(1)
        ->and($report['summary']['candidate_rows'])->toBe(0)
        ->and($report['block_reasons'])->toContain('graded_sample_below_minimum')
        ->and($report['block_reasons'])->toContain('candidate_sample_below_minimum');
});
