<?php

use Audit\NflNewCoachDivisionAudit;

require_once dirname(__DIR__, 3).'/scripts/audits/NflNewCoachDivisionAudit.php';

function coachDivisionSeason(int $year, string $coach, array $changes = []): array
{
    $rows = [];
    for ($week = 1; $week <= 16; $week++) {
        $rows[] = array_replace([
            'id' => $year * 100 + $week, 'season' => $year, 'week' => $week,
            'date' => (new DateTimeImmutable($year.'-09-01'))->modify('+'.($week - 1).' weeks')->format('Y-m-d'),
            'home' => 'PIT', 'away' => 'CIN', 'home_coach' => $coach, 'away_coach' => 'Stable opponent',
            'neutral' => false, 'division' => $week >= 4, 'home_line' => 3.5,
            'home_score' => 17, 'away_score' => 20, 'snapshot_id' => $year * 100 + $week,
        ], $changes[$week] ?? []);
    }

    return $rows;
}

it('selects the first division game regardless of week and grades the new coach side', function () {
    $result = NflNewCoachDivisionAudit::run([...coachDivisionSeason(2009, 'Old Coach'), ...coachDivisionSeason(2010, 'New Coach')]);
    expect($result['summaries']['all']['appearances'])->toBe(1)
        ->and($result['candidates'][0]['week'])->toBe(4)
        ->and($result['summaries']['home_plus_3_5']['ats'])->toBe(['W' => 1, 'L' => 0, 'P' => 0])
        ->and($result['summaries']['all']['outright'])->toBe(['W' => 0, 'L' => 1, 'T' => 0])
        ->and($result['production_modified'])->toBeFalse();
});

it('does not mistake an archive boundary a retained interim or a temporary substitute for a new hire', function () {
    $same = NflNewCoachDivisionAudit::run([...coachDivisionSeason(2009, 'Old Coach', [16 => ['home_coach' => 'Temporary']]), ...coachDivisionSeason(2010, 'Old Coach')]);
    $interim = NflNewCoachDivisionAudit::run([...coachDivisionSeason(2009, 'Old Coach', [16 => ['home_coach' => 'Promoted Interim']]), ...coachDivisionSeason(2010, 'Promoted Interim')]);
    $boundary = NflNewCoachDivisionAudit::run(coachDivisionSeason(2010, 'New Coach'));
    expect($same['summaries']['all']['appearances'])->toBe(0)
        ->and($interim['summaries']['all']['appearances'])->toBe(0)
        ->and($boundary['summaries']['all']['appearances'])->toBe(0);
});

it('excludes missing history and a coach change before the first division game', function () {
    foreach ([[2 => ['home_coach' => null]], [2 => ['home_coach' => 'Replacement']], [2 => ['division' => null]]] as $change) {
        $result = NflNewCoachDivisionAudit::run([...coachDivisionSeason(2009, 'Old Coach'), ...coachDivisionSeason(2010, 'New Coach', $change)]);
        expect($result['summaries']['all']['appearances'])->toBe(0);
    }
    $result = NflNewCoachDivisionAudit::run([...array_slice(coachDivisionSeason(2009, 'Old Coach'), 1), ...coachDivisionSeason(2010, 'New Coach')]);
    expect($result['summaries']['all']['appearances'])->toBe(0);
});

it('does not substitute a later divisional quote when the first game line is missing', function () {
    $result = NflNewCoachDivisionAudit::run([...coachDivisionSeason(2009, 'Old Coach'), ...coachDivisionSeason(2010, 'New Coach', [4 => ['home_line' => null]])]);
    expect($result['summaries']['all']['appearances'])->toBe(1)
        ->and($result['summaries']['all']['missing_lines'])->toBe(1)
        ->and($result['summaries']['all']['ats'])->toBe(['W' => 0, 'L' => 0, 'P' => 0]);
});

it('keeps pushes and mirrors road perspectives without a home team bias', function () {
    $rows = [...coachDivisionSeason(2009, 'Old Coach'), ...coachDivisionSeason(2010, 'New Coach', [4 => ['home_line' => 3]])];
    $result = NflNewCoachDivisionAudit::run($rows);
    expect($result['summaries']['all']['ats'])->toBe(['W' => 0, 'L' => 0, 'P' => 1]);
    foreach ($rows as &$row) {
        foreach ([['home', 'away'], ['home_coach', 'away_coach'], ['home_score', 'away_score']] as [$a, $b]) {
            [$row[$a], $row[$b]] = [$row[$b], $row[$a]];
        }
        $row['home_line'] = -$row['home_line'];
    }
    unset($row);
    $road = NflNewCoachDivisionAudit::run($rows);
    expect($road['summaries']['away']['ats'])->toBe($result['summaries']['all']['ats'])
        ->and($road['summaries']['home']['appearances'])->toBe(0);
});
