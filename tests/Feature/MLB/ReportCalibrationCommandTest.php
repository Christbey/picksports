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
        'feature_version' => 'core-v2',
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
        ->and((float) $report['summary']['market_spread_mae'])->toBe(3.5)
        ->and((float) $report['summary']['market_total_mae'])->toBe(1.5)
        ->and((float) $report['summary']['spread_bias_vs_market'])->toBe(3.0)
        ->and((float) $report['summary']['total_bias_vs_market'])->toBe(0.7)
        ->and($report['confidence_buckets'])->toHaveCount(1);
});
