<?php

use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Models\CFB\PredictionCalibration;
use App\Models\CFB\Team;
use App\Models\PredictionFeatureSnapshot;
use Illuminate\Support\Facades\Artisan;

uses()->group('cfb', 'commands', 'calibration');

it('learns bounded adaptive cfb calibration from graded predictions', function () {
    config([
        'cfb.predictions.adaptive_calibration.min_component_games' => 2,
        'cfb.predictions.adaptive_calibration.component_learning_rate' => 0.15,
        'cfb.predictions.adaptive_calibration.max_component_multiplier_delta' => 0.25,
    ]);

    [$homeTeam, $awayTeam] = cfbAdaptiveCalibrationTeams();
    $previous = PredictionCalibration::query()->create([
        'season' => 2026,
        'training_from_week' => 0,
        'training_through_week' => 0,
        'games_count' => 2,
        'min_games' => 2,
        'learning_rate' => 0.250,
        'parameters' => ['week_buckets' => [], 'preseason_component_multipliers' => []],
        'metrics' => [],
        'is_active' => true,
        'generated_at' => now()->subDay(),
    ]);

    cfbAdaptiveCalibrationPrediction($homeTeam, $awayTeam, predictedSpread: 0.0, predictedTotal: 50.0, homeScore: 31, awayScore: 21);
    cfbAdaptiveCalibrationPrediction($homeTeam, $awayTeam, predictedSpread: 2.0, predictedTotal: 52.0, homeScore: 35, awayScore: 27);

    $exitCode = Artisan::call('cfb:update-adaptive-calibration', [
        '--season' => 2026,
        '--min-games' => 2,
        '--learning-rate' => 0.25,
        '--json' => true,
    ]);
    $report = json_decode(Artisan::output(), true);

    $calibration = PredictionCalibration::query()->latest('id')->firstOrFail();

    expect($exitCode)->toBe(0)
        ->and($previous->fresh()->is_active)->toBeFalse()
        ->and($calibration->is_active)->toBeTrue()
        ->and($calibration->games_count)->toBe(2)
        ->and((float) data_get($calibration->parameters, 'week_buckets.week_0_1.spread_adjustment'))->toBe(2.0)
        ->and((float) data_get($calibration->parameters, 'week_buckets.week_0_1.total_adjustment'))->toBe(1.5)
        ->and((float) data_get($calibration->parameters, 'preseason_component_multipliers.week_0_1.transfer_portal'))->toBe(1.25)
        ->and($report['calibration_id'])->toBe($calibration->id);
});

function cfbAdaptiveCalibrationTeams(): array
{
    return [
        Team::factory()->create([
            'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        ]),
        Team::factory()->create([
            'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        ]),
    ];
}

function cfbAdaptiveCalibrationPrediction(
    Team $homeTeam,
    Team $awayTeam,
    float $predictedSpread,
    float $predictedTotal,
    int $homeScore,
    int $awayScore,
): Prediction {
    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 0,
        'season_type' => 'regular',
        'game_date' => '2026-08-29',
        'game_time' => '19:00:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_score' => $homeScore,
        'away_score' => $awayScore,
    ]);
    $actualMargin = $homeScore - $awayScore;
    $actualTotal = $homeScore + $awayScore;

    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'home_elo' => 1500,
        'away_elo' => 1500,
        'predicted_spread' => $predictedSpread,
        'predicted_total' => $predictedTotal,
        'win_probability' => 0.55,
        'confidence_score' => 55.0,
        'actual_spread' => $actualMargin,
        'actual_total' => $actualTotal,
        'spread_error' => abs($actualMargin - $predictedSpread),
        'total_error' => abs($actualTotal - $predictedTotal),
        'winner_correct' => true,
        'graded_at' => now(),
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v1',
        'blend_version' => 'baseline-v1',
    ]);

    PredictionFeatureSnapshot::query()->create([
        'sport' => 'cfb',
        'prediction_table' => 'cfb_predictions',
        'prediction_id' => $prediction->id,
        'game_id' => $game->id,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v1',
        'blend_version' => 'baseline-v1',
        'features' => [],
        'outputs' => [],
        'model_metadata' => [
            'cfb_preseason_layer' => [
                'components' => [
                    'transfer_portal' => [
                        'spread_adjustment' => 1.0,
                    ],
                ],
            ],
        ],
        'generated_at' => now(),
    ]);

    return $prediction;
}
