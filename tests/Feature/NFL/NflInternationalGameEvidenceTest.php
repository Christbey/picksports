<?php

use App\Services\NFL\Matchups\NflInternationalGameEvidence;

function internationalScheduleRow(array $overrides = []): array
{
    return array_replace(['game_id' => '2025_04_MIN_PIT', 'season' => 2025, 'week' => 4,
        'away_team' => 'MIN', 'home_team' => 'PIT', 'game_type' => 'REG', 'gameday' => '2025-09-28',
        'stadium' => 'Acrisure Stadium', 'location' => 'Neutral'], $overrides);
}

it('uses the official dated matchup rather than the incorrect provider stadium', function () {
    $evidence = app(NflInternationalGameEvidence::class)->forScheduleRow(internationalScheduleRow());
    expect($evidence['country'])->toBe('IE')->and($evidence['stadium'])->toBe('Croke Park')
        ->and($evidence['source'])->toBe('nfl_official_international_schedule')
        ->and($evidence['source_url'])->toContain('nfl.com/');
});

it('does not infer an international venue from a neutral flag or a mismatched identity', function (array $overrides) {
    expect(app(NflInternationalGameEvidence::class)->forScheduleRow(internationalScheduleRow($overrides)))->toBeNull();
})->with([
    [['game_id' => '2025_04_GB_PIT', 'away_team' => 'GB']],
    [['gameday' => '2025-09-29']], [['season' => 2024]], [['week' => 5]],
    [['away_team' => 'GB']], [['home_team' => 'CLE']], [['game_type' => 'POST']],
    [['game_id' => '2027_04_MIN_PIT', 'season' => 2027]],
]);

it('matches international games in each supported historical season', function (array $row, string $country) {
    expect(app(NflInternationalGameEvidence::class)->forScheduleRow($row)['country'])->toBe($country);
})->with([
    [['game_id' => '2023_04_ATL_JAX', 'season' => 2023, 'week' => 4, 'away_team' => 'ATL', 'home_team' => 'JAX', 'game_type' => 'REG', 'gameday' => '2023-10-01'], 'GB'],
    [['game_id' => '2024_01_GB_PHI', 'season' => 2024, 'week' => 1, 'away_team' => 'GB', 'home_team' => 'PHI', 'game_type' => 'REG', 'gameday' => '2024-09-06'], 'BR'],
    [['game_id' => '2026_01_SF_LA', 'season' => 2026, 'week' => 1, 'away_team' => 'SF', 'home_team' => 'LA', 'game_type' => 'REG', 'gameday' => '2026-09-10'], 'AU'],
]);
