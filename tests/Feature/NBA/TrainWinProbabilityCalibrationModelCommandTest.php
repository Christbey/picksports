<?php

use Illuminate\Support\Facades\Artisan;

it('trains a win probability calibration challenger model from split snapshot csv files', function () {
    $inputDir = storage_path('app/ml/train-calibration-splits');
    $outputPath = storage_path('app/ml/models/test_nba_win_probability_calibration_model.json');

    @mkdir($inputDir, 0777, true);
    @mkdir(dirname($outputPath), 0777, true);
    @unlink($outputPath);

    $header = 'prediction_id,game_date,feature_model_win_probability,target_home_win';

    file_put_contents($inputDir.'/nba_snapshot_train.csv', implode("\n", [
        $header,
        '1,2026-02-01,0.85,1',
        '2,2026-02-02,0.80,0',
        '3,2026-02-03,0.78,0',
        '4,2026-02-04,0.82,1',
        '5,2026-02-05,0.76,0',
        '6,2026-02-06,0.81,1',
    ]));

    file_put_contents($inputDir.'/nba_snapshot_validation.csv', implode("\n", [
        $header,
        '7,2026-02-07,0.84,0',
        '8,2026-02-08,0.79,1',
        '9,2026-02-09,0.77,0',
        '10,2026-02-10,0.83,1',
    ]));

    file_put_contents($inputDir.'/nba_snapshot_test.csv', implode("\n", [
        $header,
        '11,2026-02-11,0.82,0',
        '12,2026-02-12,0.78,1',
        '13,2026-02-13,0.80,0',
        '14,2026-02-14,0.81,1',
    ]));

    Artisan::call('nba:train-win-probability-calibration-model', [
        '--input-dir' => $inputDir,
        '--output' => $outputPath,
        '--learning-rate' => 0.05,
        '--iterations' => 4000,
    ]);

    $artifact = json_decode(file_get_contents($outputPath), true);

    expect($artifact)->toBeArray()
        ->and($artifact['model_type'])->toBe('nba_win_probability_platt_calibration')
        ->and($artifact['metrics']['validation']['count'])->toBe(4)
        ->and($artifact['metrics']['test']['count'])->toBe(4)
        ->and($artifact['metrics']['validation']['challenger_brier'])->toBeLessThan($artifact['metrics']['validation']['baseline_brier']);
});
