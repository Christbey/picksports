<?php

use Illuminate\Support\Facades\Artisan;

it('trains a spread residual challenger model from split snapshot csv files', function () {
    $inputDir = storage_path('app/ml/train-model-splits');
    $outputPath = storage_path('app/ml/models/test_nba_spread_residual_model.json');

    @mkdir($inputDir, 0777, true);
    @mkdir(dirname($outputPath), 0777, true);
    @unlink($outputPath);

    $header = 'prediction_id,game_date,feature_elo_diff,feature_recent_form_diff,feature_rest_day_diff,feature_injury_spread_adj,feature_market_home_spread,feature_model_predicted_spread,feature_confidence_score,target_home_margin';

    file_put_contents($inputDir.'/nba_snapshot_train.csv', implode("\n", [
        $header,
        '1,2026-02-01,10,2,1,0.0,4,6,70,7.5',
        '2,2026-02-02,20,3,0,0.0,5,7,72,8.8',
        '3,2026-02-03,-5,-1,-1,0.0,-2,-1,60,-1.6',
        '4,2026-02-04,-10,-2,0,0.0,-3,-2,58,-2.8',
    ]));

    file_put_contents($inputDir.'/nba_snapshot_validation.csv', implode("\n", [
        $header,
        '5,2026-02-05,15,2,1,0.0,4.5,6.5,71,8.1',
        '6,2026-02-06,-8,-1,0,0.0,-2.5,-1.5,59,-2.2',
    ]));

    file_put_contents($inputDir.'/nba_snapshot_test.csv', implode("\n", [
        $header,
        '7,2026-02-07,12,1,1,0.0,4.2,6.2,69,7.6',
        '8,2026-02-08,-12,-2,-1,0.0,-3.2,-2.2,57,-3.2',
    ]));

    Artisan::call('nba:train-spread-residual-model', [
        '--input-dir' => $inputDir,
        '--output' => $outputPath,
        '--ridge' => 0.1,
    ]);

    $artifact = json_decode(file_get_contents($outputPath), true);

    expect($artifact)->toBeArray()
        ->and($artifact['model_type'])->toBe('nba_spread_residual_ridge')
        ->and($artifact['feature_columns'])->toContain('feature_elo_diff')
        ->and($artifact['coefficients'])->toHaveKey('feature_market_home_spread')
        ->and($artifact['metrics']['validation']['count'])->toBe(2)
        ->and($artifact['metrics']['test']['count'])->toBe(2)
        ->and($artifact['metrics']['validation']['challenger_mae'])->toBeLessThanOrEqual($artifact['metrics']['validation']['baseline_mae']);
});
