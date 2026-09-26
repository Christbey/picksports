<?php

use Audit\NflContextLayerAudit;

require_once dirname(__DIR__, 3).'/scripts/audits/NflContextLayerAudit.php';

function contextLayerGame(array $overrides = []): array
{
    return array_replace(['id' => 1, 'date' => '2025-09-07', 'season' => 2025,
        'home' => 'PIT', 'away' => 'CIN', 'neutral' => false, 'home_line' => 3.5,
        'home_score' => 17, 'away_score' => 20, 'kickoff' => '2025-09-07T17:00:00Z',
        'home_coach' => 'Veteran', 'away_coach' => 'New Hire', 'away_qb_id' => 'GSIS-QB'], $overrides);
}

it('converts kickoff to Central time including daylight saving and preserves other windows', function () {
    expect(NflContextLayerAudit::timeWindow('2025-09-07T17:00:00Z'))->toBe('noon')
        ->and(NflContextLayerAudit::timeWindow('2025-12-07T18:00:00Z'))->toBe('noon')
        ->and(NflContextLayerAudit::timeWindow('2025-09-07T20:25:00Z'))->toBe('afternoon_3')
        ->and(NflContextLayerAudit::timeWindow('2025-09-08T00:20:00Z'))->toBe('night')
        ->and(NflContextLayerAudit::timeWindow('2025-09-07T13:30:00Z'))->toBe('other')
        ->and(NflContextLayerAudit::timeWindow('2025-09-07 12:00:00'))->toBeNull()
        ->and(NflContextLayerAudit::timeWindow(null))->toBeNull();
});

it('does not treat unknown rookie identities or playoff coverage as false', function () {
    $rows = NflContextLayerAudit::observations([contextLayerGame()], []);
    expect($rows[1]['vs_rookie_qb'])->toBeNull()
        ->and($rows[1]['vs_rookie_coach'])->toBeNull()
        ->and($rows[1]['vs_previous_playoff'])->toBeNull();
    $layer = NflContextLayerAudit::layer($rows, 'vs_rookie_qb', false, 'PIT', 3.5, 'home');
    expect($layer['known_appearances'])->toBe(0)->and($layer['team']['sample'])->toBe(0);
});

it('uses career rookie seasons not first starts or new team tenure', function () {
    $rows = NflContextLayerAudit::observations([contextLayerGame()], [2024 => ['CIN']], ['GSIS-QB' => 2025], ['new hire' => 2010]);
    expect($rows[1]['vs_rookie_qb'])->toBeTrue()
        ->and($rows[1]['vs_rookie_coach'])->toBeFalse()
        ->and($rows[1]['vs_previous_playoff'])->toBeTrue()
        ->and($rows[0]['vs_previous_playoff'])->toBeFalse();
});

it('grades both sides with original lines while keeping outright records separate', function () {
    $rows = NflContextLayerAudit::observations([contextLayerGame(), contextLayerGame(['id' => 2, 'home_line' => 3]), contextLayerGame(['id' => 3, 'home_line' => null])], []);
    $home = NflContextLayerAudit::layer($rows, 'time_window', 'noon', 'PIT', 3.5, 'home');
    expect($home['team']['ats'])->toBe(['W' => 1, 'L' => 0, 'P' => 1])
        ->and($home['team']['outright'])->toBe(['W' => 0, 'L' => 3, 'T' => 0])
        ->and($home['team']['missing_lines'])->toBe(1)
        ->and($home['team_same_line_venue']['sample'])->toBe(1);
    $away = NflContextLayerAudit::layer($rows, 'time_window', 'noon', 'CIN', -3.5, 'away');
    expect($away['team']['ats'])->toBe(['W' => 0, 'L' => 1, 'P' => 1]);
});

it('requires complete playoff fields and never doubles duplicate games', function () {
    $games = [];
    for ($i = 0; $i < 11; $i++) {
        $games[] = ['id' => $i, 'season' => 2010, 'home' => 'T'.$i, 'away' => 'T'.($i + 1)];
    }
    expect(NflContextLayerAudit::playoffFields(array_slice($games, 1)))->toBe([])
        ->and(NflContextLayerAudit::playoffFields([...$games, $games[0]])[2010])->toHaveCount(12);
});

it('requires all 32 defenses with three games and treats ranking ties conservatively', function () {
    $totals = [];
    for ($i = 1; $i <= 32; $i++) {
        $totals['T'.$i] = ['games' => 3, 'allowed' => $i * 3];
    }
    $totals['T6']['allowed'] = 15;
    $ranks = NflContextLayerAudit::defenseRanks($totals);
    expect($ranks['T5']['rank'])->toBe(5)->and($ranks['T5']['rank_end'])->toBe(6);
    $totals['T32']['games'] = 2;
    expect(NflContextLayerAudit::defenseRanks($totals))->toBe([]);
});

it('never leaks target results or same-date games into defensive rankings', function () {
    $games = [];
    for ($day = 1; $day <= 4; $day++) {
        for ($i = 0; $i < 16; $i++) {
            $games[] = contextLayerGame(['id' => $day * 100 + $i, 'date' => '2025-09-0'.$day,
                'home' => 'T'.$i, 'away' => 'T'.($i + 16), 'home_score' => $day === 4 ? 99 : $i, 'away_score' => $i + 16]);
        }
    }
    $rows = NflContextLayerAudit::observations($games, []);
    expect($rows[95]['opponent_scoring_defense'])->toBeNull()
        ->and($rows[96]['opponent_scoring_defense']['points_allowed_per_game'])->toBe(16.0)
        ->and($rows[127]['opponent_scoring_defense']['points_allowed_per_game'])->toBe(15.0);
});

it('separates team coaching quarterback and paired histories across franchises', function () {
    $rows = NflContextLayerAudit::observations([
        contextLayerGame(['id' => 1, 'home_coach' => 'Prior coach', 'home_qb_id' => 'old-qb']),
        contextLayerGame(['id' => 2, 'home' => 'GB', 'home_coach' => 'Current Coach', 'home_qb_id' => 'current-qb']),
        contextLayerGame(['id' => 3, 'home' => 'DAL', 'home_coach' => 'Current Coach', 'home_qb_id' => 'other-qb', 'home_score' => 0]),
        contextLayerGame(['id' => 4, 'home' => 'NYJ', 'home_coach' => 'Other Coach', 'home_qb_id' => 'current-qb', 'home_score' => 0]),
    ], []);
    $result = NflContextLayerAudit::identities($rows, 'PIT', ' current  coach ', 'current-qb', 3.5, 'home', 'noon');
    $records = $result['same_line_venue_time'];
    expect($records['team']['record']['sample'])->toBe(1)
        ->and($records['coach_all_teams']['record']['ats'])->toBe(['W' => 1, 'L' => 1, 'P' => 0])
        ->and($records['qb_all_teams']['record']['ats'])->toBe(['W' => 1, 'L' => 1, 'P' => 0])
        ->and($records['coach_and_qb_all_teams']['record']['sample'])->toBe(1)
        ->and($records['team_coach_and_qb']['status'])->toBe('no_sample');
});

it('keeps missing identities unavailable rather than grouping unknown starters together', function () {
    $rows = NflContextLayerAudit::observations([contextLayerGame()], []);
    $result = NflContextLayerAudit::identities($rows, 'PIT', null, null, null, null, null);
    expect($result['all']['team']['record']['sample'])->toBe(1)
        ->and($result['all']['qb_all_teams']['status'])->toBe('unavailable')
        ->and($result['all']['coach_all_teams']['record'])->toBeNull()
        ->and($result['same_time_window']['team']['status'])->toBe('unavailable')
        ->and($result['same_line_venue']['team']['record'])->toBeNull();
});
