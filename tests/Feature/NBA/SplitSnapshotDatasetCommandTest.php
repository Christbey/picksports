<?php

use Illuminate\Support\Facades\Artisan;

it('splits the nba snapshot dataset chronologically into train validation and test files', function () {
    $inputPath = storage_path('app/ml/test_snapshot_split_input.csv');
    $outputDir = storage_path('app/ml/test-splits');

    @mkdir(dirname($inputPath), 0777, true);
    @mkdir($outputDir, 0777, true);
    @unlink($inputPath);
    @unlink($outputDir.'/nba_snapshot_train.csv');
    @unlink($outputDir.'/nba_snapshot_validation.csv');
    @unlink($outputDir.'/nba_snapshot_test.csv');

    file_put_contents($inputPath, implode("\n", [
        'prediction_id,game_date,feature_home_elo,target_home_margin',
        '10,2026-02-01,1500,5',
        '11,2026-02-02,1501,6',
        '12,2026-02-03,1502,7',
        '13,2026-02-04,1503,8',
        '14,2026-02-05,1504,9',
        '15,2026-02-06,1505,10',
    ]));

    Artisan::call('nba:split-snapshot-dataset', [
        '--input' => $inputPath,
        '--output-dir' => $outputDir,
        '--train' => 50,
        '--validation' => 25,
        '--test' => 25,
    ]);

    $train = file($outputDir.'/nba_snapshot_train.csv', FILE_IGNORE_NEW_LINES);
    $validation = file($outputDir.'/nba_snapshot_validation.csv', FILE_IGNORE_NEW_LINES);
    $test = file($outputDir.'/nba_snapshot_test.csv', FILE_IGNORE_NEW_LINES);

    expect($train)->toHaveCount(4)
        ->and($validation)->toHaveCount(2)
        ->and($test)->toHaveCount(3)
        ->and($train[1])->toContain('10,2026-02-01')
        ->and($train[3])->toContain('12,2026-02-03')
        ->and($validation[1])->toContain('13,2026-02-04')
        ->and($test[1])->toContain('14,2026-02-05');
});
