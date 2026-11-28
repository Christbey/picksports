<?php

use Audit\NflIntegrityChangeImpact;

require_once __DIR__.'/../../scripts/audits/NflIntegrityChangeImpact.php';

it('does not report perfect calibration or select a factor without matched prior inputs', function (array $rows) {
    $report = NflIntegrityChangeImpact::calibration($rows, 25);
    expect($report['matched_games'])->toBe(0);
    foreach (['old_same_day_uncapped', 'prior_only_uncapped', 'prior_capped_rounded'] as $variant) {
        expect($report[$variant]['at_current_factor']['mae'])->toBeNull()
            ->and($report[$variant]['best_in_sample'])->toBeNull();
    }
})->with([
    'no games' => [[]],
    'missing priors' => [[['home_prior' => null, 'away_prior' => 1500, 'home_old_is_current_game' => true, 'away_old_is_current_game' => false]]],
]);

it('retains measured zero error for an actual matching calibration sample', function () {
    $rows = [['home_prior' => 1500, 'away_prior' => 1500, 'home_old' => 1500, 'away_old' => 1500,
        'home_old_is_current_game' => false, 'away_old_is_current_game' => false, 'neutral' => false, 'actual' => 2.25]];
    $report = NflIntegrityChangeImpact::calibration($rows, 25);
    expect($report['matched_games'])->toBe(1)
        ->and($report['old_same_day_uncapped']['at_current_factor']['mae'])->toEqual(0)
        ->and($report['old_same_day_uncapped']['best_in_sample']['factor'])->toEqual(0.09);
});
