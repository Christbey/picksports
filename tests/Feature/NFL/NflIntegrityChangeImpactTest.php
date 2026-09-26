<?php

use Audit\NflHistoricalBaselineAudit;
use Audit\NflIntegrityChangeImpact;

require_once dirname(__DIR__, 3).'/scripts/audits/NflHistoricalBaselineAudit.php';
require_once dirname(__DIR__, 3).'/scripts/audits/NflIntegrityChangeImpact.php';

it('isolates the spread convention without changing the forecast', function () {
    $old = [['id' => 1, 'margin' => 10.0, 'actual' => 3.0, 'line' => 6.5]];
    $new = [['id' => 1, 'margin' => 10.0, 'actual' => 3.0, 'line' => -6.5]];
    $result = NflIntegrityChangeImpact::compare($old, $new, 'margin');
    expect($result['before']['win'])->toBe(1)->and($result['after']['loss'])->toBe(1)
        ->and($result['changes']['margin_changed'])->toBe(0)
        ->and($result['changes']['pick_changed'])->toBe(0)
        ->and($result['changes']['result_changed'])->toBe(1);
});

it('changes only downstream simulated ratings when the tie convention changes', function () {
    $games = [
        ['id' => 1, 'date' => '2020-09-01', 'season' => 2020, 'type' => '2', 'week' => 1, 'home' => 1, 'away' => 2, 'neutral' => true, 'actual' => 0.0],
        ['id' => 2, 'date' => '2020-09-08', 'season' => 2020, 'type' => '2', 'week' => 2, 'home' => 1, 'away' => 2, 'neutral' => true, 'actual' => 7.0],
    ];
    $fixed = NflHistoricalBaselineAudit::simulate($games, config('nfl.elo'));
    $legacy = NflHistoricalBaselineAudit::simulate($games, config('nfl.elo'), false);
    expect($fixed[0]['sequential_baseline'])->toBe($legacy[0]['sequential_baseline'])
        ->and($fixed[1]['sequential_baseline'])->toBe(0.0)
        ->and($legacy[1]['sequential_baseline'])->toBeLessThan(0.0);
});

it('measures leaked calibration separately and keeps the matched sample fixed', function () {
    $rows = [
        ['home_prior' => 1500, 'away_prior' => 1500, 'home_old' => 1600, 'away_old' => 1500, 'neutral' => true, 'actual' => 9.0, 'home_old_is_current_game' => true, 'away_old_is_current_game' => true],
        ['home_prior' => null, 'away_prior' => 1500, 'home_old' => 1600, 'away_old' => 1500, 'neutral' => true, 'actual' => 9.0, 'home_old_is_current_game' => true, 'away_old_is_current_game' => true],
    ];
    $report = NflIntegrityChangeImpact::calibration($rows, 25);
    expect($report['matched_games'])->toBe(1)->and($report['excluded_missing_prior'])->toBe(1)
        ->and($report['old_same_day_uncapped']['at_current_factor']['factor'])->toBe(0.09)
        ->and($report['old_same_day_uncapped']['at_current_factor']['mae'])->toEqualWithDelta(0.0, 1e-9)
        ->and($report['prior_capped_rounded']['at_current_factor']['mae'])->toBe(9.0);
});
