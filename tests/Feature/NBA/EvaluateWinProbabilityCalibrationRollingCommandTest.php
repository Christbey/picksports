<?php

use Illuminate\Support\Facades\Artisan;

it('runs rolling-window evaluation for the win probability calibration challenger model', function () {
    $inputPath = storage_path('app/ml/test_nba_rolling_calibration_dataset.csv');
    $outputPath = storage_path('app/ml/reports/test_nba_win_probability_calibration_rolling.json');

    @mkdir(dirname($inputPath), 0777, true);
    @mkdir(dirname($outputPath), 0777, true);
    @unlink($inputPath);
    @unlink($outputPath);

    $header = 'prediction_id,game_date,feature_model_win_probability,target_home_win';
    $rows = [
        '1,2026-02-01,0.80,1',
        '2,2026-02-02,0.78,0',
        '3,2026-02-03,0.81,1',
        '4,2026-02-04,0.79,0',
        '5,2026-02-05,0.82,1',
        '6,2026-02-06,0.77,0',
        '7,2026-02-07,0.83,1',
        '8,2026-02-08,0.76,0',
    ];

    file_put_contents($inputPath, implode("\n", [$header, ...$rows]));

    Artisan::call('nba:evaluate-win-probability-calibration-rolling', [
        '--input' => $inputPath,
        '--output' => $outputPath,
        '--min-train-size' => 4,
        '--test-window-size' => 2,
        '--step-size' => 2,
        '--learning-rate' => 0.05,
        '--iterations' => 2000,
    ]);

    $report = json_decode(file_get_contents($outputPath), true);

    expect($report)->toBeArray()
        ->and($report['report_type'])->toBe('nba_win_probability_calibration_rolling_evaluation')
        ->and($report['summary']['window_count'])->toBe(2)
        ->and($report['windows'])->toHaveCount(2)
        ->and($report['windows'][0]['train_rows'])->toBe(4)
        ->and($report['windows'][0]['evaluation_rows'])->toBe(2);
});
