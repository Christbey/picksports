<?php

use App\Services\BettingRecommendations\NflPropProbabilityCalibration;

function calibrationFixture(): array
{
    $rows = [];
    foreach (range(1, 240) as $i) {
        $date = $i <= 160 ? '2026-09-10' : '2026-10-20';
        $rows[] = ['game_id' => $i, 'player_id' => 1, 'market' => 'player_receptions',
            'game_start_at' => $date.'T20:00:00Z', 'labels_available_at' => ($i <= 160 ? '2026-09-11' : '2026-10-21').'T20:00:00Z',
            'history_max_game_start_at' => '2026-09-01T20:00:00Z', 'quote_observed_at' => $date.'T19:00:00Z',
            'quote_line' => 4.5, 'actual' => $i % 2 ? 5 : 3, 'legacy_mean' => 7, 'recent5_mean' => 4,
            'matchup_mean' => 5, 'matchup_probability' => .9, 'baseline_probability' => .95,
            'over_price' => -110, 'under_price' => -110, 'push_probability' => 0, 'matchup_status' => 'applied'];
    }

    return $rows;
}

it('fits only earlier labels and reduces overconfidence on a later untouched period', function () {
    $service = new NflPropProbabilityCalibration;
    $rows = calibrationFixture();
    $report = $service->report($rows, '2026-10-15');
    $market = $report['markets']['player_receptions'];
    expect($market['training_n'])->toBe(160)->and($market['test_n'])->toBe(80)
        ->and($market['evaluation']['calibrated_probability']['brier'])->toBeLessThan($market['evaluation']['matchup_probability']['brier'])
        ->and($report['production_promotion'])->toBeFalse();
    $fit = $market['fit'];
    foreach ($rows as &$row) {
        if ($row['game_id'] > 160) {
            $row['actual'] = 100;
        }
    }
    expect($service->report($rows, '2026-10-15')['markets']['player_receptions']['fit'])->toBe($fit);
});

it('deduplicates books and excludes postgame quotes and label leakage', function () {
    $rows = calibrationFixture();
    $rows[] = $rows[0];
    $rows[1]['quote_observed_at'] = '2026-09-11T20:00:00Z';
    $rows[2]['history_max_game_start_at'] = $rows[2]['game_start_at'];
    $rows[3]['labels_available_at'] = '2026-10-16T20:00:00Z';
    $result = (new NflPropProbabilityCalibration)->report($rows, '2026-10-15')['markets']['player_receptions'];
    expect($result['training_n'])->toBe(157)->and($result['test_n'])->toBe(80);
});

it('withholds calibration for inadequate samples and excludes pushes from probability scoring', function () {
    $rows = array_slice(calibrationFixture(), 0, 10);
    $rows[0]['quote_line'] = 5;
    $result = (new NflPropProbabilityCalibration)->report($rows, '2026-10-15')['markets']['player_receptions'];
    expect($result['status'])->toBe('insufficient_training_sample')->and($result['fit'])->toBeNull()
        ->and($result['training_n'])->toBe(9)->and($result['pushes'])->toBe(1);
});

it('settles quoted positive prices negative prices and pushes with one unit stakes', function () {
    $rows = array_slice(calibrationFixture(), 160, 3);
    foreach ($rows as &$row) {
        $row['matchup_probability'] = .9;
        $row['over_price'] = 150;
        $row['actual'] = 5;
    }
    $rows[1]['over_price'] = -200;
    $rows[2]['actual'] = 4.5;
    $result = (new NflPropProbabilityCalibration)->report($rows, '2026-10-15')['markets']['player_receptions']['price_evaluation']['matchup_probability'];
    expect($result['bets'])->toBe(3)->and($result['wins'])->toBe(2)->and($result['pushes'])->toBe(1)->and($result['units'])->toBe(2.0);
});

it('validates calibration command boundaries', function () {
    $this->artisan('nfl:calibrate-player-props', ['--season' => 2026, '--validation-from' => '2026-02-31'])->assertFailed();
});
