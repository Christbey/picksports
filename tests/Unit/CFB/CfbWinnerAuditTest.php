<?php

use App\Services\CFB\Predictions\CfbWinnerAudit;
use App\Services\CFB\Predictions\CfbWinnerMatchupCandidate;

function winnerAuditRow(int $id = 1, array $overrides = []): array
{
    $metrics = ['offensive_success_rate' => 0.5, 'defensive_success_rate' => 0.4,
        'offensive_true_epa_per_play' => 0.1, 'defensive_true_epa_per_play' => -0.1,
        'strength_of_schedule' => 1500, 'turnover_differential' => 0];

    return array_replace(['game_id' => $id, 'prediction_id' => 'prediction-'.$id, 'snapshot_id' => $id,
        'release' => 'test-v1', 'configuration_hash' => 'formula-a', 'season' => 2026,
        'kickoff' => '2026-10-17T18:00:00-05:00', 'captured_at' => '2026-10-17T10:00:00-05:00',
        'pregame_safe' => true, 'home' => 'MIA', 'away' => 'FSU', 'home_score' => 28, 'away_score' => 21,
        'home_probability' => 0.8, 'home_margin' => 10, 'models' => [],
        'quality' => ['qualified' => true], 'home_metrics' => $metrics, 'away_metrics' => $metrics,
        'home_metric_season' => 2026, 'away_metric_season' => 2026], $overrides);
}

function winnerAuditReport(array $rows, array $pairs = []): array
{
    return (new CfbWinnerAudit(new CfbWinnerMatchupCandidate))->evaluate(
        ['schema' => 'cfb-winner-audit-dataset-v1', 'rows' => $rows], '2026-10-03', '2026-10-12', $pairs);
}

it('evaluates winner probabilities and treats exact tossups as undecided rather than home picks', function () {
    $result = winnerAuditReport([winnerAuditRow(), winnerAuditRow(2, ['home_probability' => 0.5])]);
    $p = $result['cohorts']['all']['performance'];
    expect($p['wins'])->toBe(1)->and($p['undecided'])->toBe(1)
        ->and($p['accuracy'])->toBe(1)->and($p['brier'])->toBeGreaterThan(0)
        ->and($p['accuracy_wilson_95'][0])->toBeLessThan(1)
        ->and($result['promotion']['allowed'])->toBeFalse();
});

it('rejects duplicate games and late or unverified snapshots', function (array $rows) {
    winnerAuditReport($rows);
})->throws(InvalidArgumentException::class)->with([
    'duplicates' => fn () => [[winnerAuditRow(), winnerAuditRow()]],
    'at kickoff' => fn () => [[winnerAuditRow(1, ['captured_at' => '2026-10-17T18:00:00-05:00'])]],
    'unverified' => fn () => [[winnerAuditRow(1, ['pregame_safe' => false])]],
]);

it('excludes missing probabilities and invalid or tied final scores with explicit counts', function () {
    $report = winnerAuditReport([winnerAuditRow(), winnerAuditRow(2, ['home_probability' => null]),
        winnerAuditRow(3, ['home_probability' => 1]), winnerAuditRow(4, ['home_score' => 21])]);
    expect($report['games'])->toBe(1)
        ->and($report['excluded']['evaluation']['invalid_probability_or_final_result'])->toBe(3);
});

it('compares models on identical games and excludes pickems or missing baselines', function () {
    $report = winnerAuditReport([
        winnerAuditRow(1, ['models' => ['market' => ['margin' => -3]]]),
        winnerAuditRow(2, ['models' => ['market' => ['margin' => 0]]]),
        winnerAuditRow(3),
    ]);
    $pair = $report['cohorts']['all']['paired_baselines']['market'];
    expect($pair['games'])->toBe(1)->and($pair['game_ids'])->toBe([1])
        ->and($pair['published_wins'])->toBe(1)->and($pair['candidate_wins'])->toBe(0);
});

it('shows overconfidence and never labels descriptive confidence bands as calibrated', function () {
    $report = winnerAuditReport([winnerAuditRow(1, ['home_probability' => 0.95, 'home_score' => 0]),
        winnerAuditRow(2, ['home_probability' => 0.95])]);
    $band = $report['cohorts']['all']['performance']['reliability'][0];
    expect($band['mean_raw_estimate'])->toBe(0.95)->and($band['observed_win_rate'])->toBe(0.5)
        ->and($band['confidence_validated'])->toBeFalse()->and($band['minimum_30_games_met'])->toBeFalse();
});

it('assigns complete Central kickoff dates to splits including UTC next-day kickoffs', function () {
    $report = winnerAuditReport([winnerAuditRow(1, ['kickoff' => '2026-10-12T02:00:00Z', 'captured_at' => '2026-10-11T12:00:00Z'])]);
    expect($report['cohorts'])->toHaveKey('split:validation')->not->toHaveKey('split:test');
});

it('does not impute missing matchup evidence or use prior-season metrics as current', function () {
    $row = winnerAuditRow();
    $row['home_metrics']['offensive_true_epa_per_play'] = null;
    $candidate = new CfbWinnerMatchupCandidate;
    expect($candidate->features($row))->toBeNull()
        ->and($candidate->features(winnerAuditRow(2, ['home_metric_season' => 2025])))->toBeNull();
    $coverage = winnerAuditReport([$row])['feature_coverage'];
    expect($coverage['complete_current_season_matchup_games'])->toBe(0)
        ->and($coverage['both_teams_numeric']['defensive_sack_rate'])->toBe(0);
});

it('tags only designated rivalry pairs without applying a rivalry penalty', function () {
    $rows = [winnerAuditRow(), winnerAuditRow(2, ['home' => 'HOU', 'away' => 'OKST'])];
    $report = winnerAuditReport($rows, ['FSU:MIA']);
    expect($report['cohorts']['rivalry:FSU:MIA']['performance']['wins'])->toBe(1)
        ->and($report['cohorts']['rivalry:not_in_user_designated_pairs']['performance']['wins'])->toBe(1)
        ->and($report['cohorts']['all']['performance'])->toBe(winnerAuditReport($rows)['cohorts']['all']['performance']);
});

it('ranks without outcomes and keeps top selections fixed when results change', function () {
    $rows = [];
    for ($i = 1; $i <= 8; $i++) {
        $rows[] = winnerAuditRow($i, ['home_probability' => 0.5 + $i * 0.05]);
    }
    $before = winnerAuditReport($rows)['selection_ranking']['raw_probability'];
    foreach ($rows as &$row) {
        $row['home_score'] = 0;
    }
    unset($row);
    $after = winnerAuditReport($rows)['selection_ranking']['raw_probability'];
    expect($before['daily'])->toBe($after['daily'])
        ->and($before['daily']['2026-10-17']['selected_game_ids'])->toBe([8, 7, 6, 5, 4])
        ->and($after['top_5_per_day']['losses'])->toBe(5);
});

it('requires sufficient separate temporal evidence for every release and formula', function () {
    $report = winnerAuditReport([winnerAuditRow()]);
    $candidate = $report['temporal_challengers']['test-v1:formula-a']['overall_matchup'];
    expect($candidate['status'])->toBe('insufficient_evidence')
        ->and($candidate['counts'])->toBe(['train' => 0, 'validation' => 0, 'test' => 1]);
});

it('fits both challengers exclusively on training games and is invariant to later outcomes', function () {
    $rows = [];
    for ($i = 1; $i <= 200; $i++) {
        $date = $i <= 100 ? '2026-09-26' : ($i <= 150 ? '2026-10-10' : '2026-10-17');
        $rows[] = winnerAuditRow($i, ['kickoff' => $date.'T18:00:00-05:00', 'captured_at' => $date.'T10:00:00-05:00',
            'home_score' => $i % 3 === 0 ? 0 : 28, 'home_probability' => $i % 2 === 0 ? 0.8 : 0.4]);
    }
    $first = winnerAuditReport($rows)['temporal_challengers']['test-v1:formula-a'];
    for ($i = 100; $i < 200; $i++) {
        $rows[$i]['home_score'] = $rows[$i]['home_score'] === 0 ? 28 : 0;
    }
    $second = winnerAuditReport($rows)['temporal_challengers']['test-v1:formula-a'];
    foreach (['probability_only', 'overall_matchup'] as $name) {
        expect($first[$name]['status'])->toBe('exploratory_challenger')
            ->and($first[$name]['model'])->toBe($second[$name]['model'])
            ->and($first[$name]['model']['training_game_ids'])->toBe(range(1, 100))
            ->and($first[$name]['evaluation']['test']['game_ids'])->toBe(range(151, 200));
    }
});
