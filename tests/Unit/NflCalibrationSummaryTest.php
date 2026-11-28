<?php

use App\Services\NFL\NflCalibrationReport;
use Tests\TestCase;

uses(TestCase::class);

it('scores probabilities and point errors without claiming validation', function () {
    $report = app(NflCalibrationReport::class)->summarize([
        ['game_id' => 1, 'snapshot_id' => 1, 'home_score' => 24., 'away_score' => 17.,
            'outputs' => ['win_probability' => .8, 'predicted_spread' => 4, 'predicted_total' => 45]],
        ['game_id' => 2, 'snapshot_id' => 2, 'home_score' => 14., 'away_score' => 21.,
            'outputs' => ['win_probability' => .2, 'predicted_spread' => -4, 'predicted_total' => 39]],
    ]);
    expect($report['moneyline']['brier'])->toBe(.04)
        ->and($report['moneyline']['log_loss'])->toBe(round(-log(.8), 6))
        ->and($report['moneyline']['validation_status'])->toBe('not_validated')
        ->and($report['spread']['mae'])->toBe(3.)
        ->and($report['spread']['mean_prediction_minus_actual'])->toBe(0.)
        ->and($report['total']['mae'])->toBe(4.);
});

it('excludes ties and invalid probabilities while retaining valid point forecasts', function () {
    $report = app(NflCalibrationReport::class)->summarize([
        ['game_id' => 1, 'snapshot_id' => 1, 'home_score' => 17., 'away_score' => 17., 'outputs' => ['win_probability' => .6]],
        ['game_id' => 2, 'snapshot_id' => 2, 'home_score' => 10., 'away_score' => 17., 'outputs' => ['win_probability' => 1.2, 'predicted_total' => 30]],
    ]);
    expect($report['moneyline']['sample'])->toBe(0)->and($report['moneyline']['brier'])->toBeNull()
        ->and($report['moneyline']['ties_excluded'])->toBe(1)->and($report['total']['sample'])->toBe(1)
        ->and($report['spread']['mae'])->toBeNull();
});
