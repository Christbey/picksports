<?php

namespace App\Services\NFL\Matchups;

/** Official international schedules, joined only after final-game identity verification. */
final class NflInternationalGameEvidence
{
    private const SOURCES = [
        2023 => 'https://www.nfl.com/news/nfl-announces-schedule-for-five-international-games-in-2023-x0246',
        2024 => 'https://www.nfl.com/news/2024-nfl-schedule-release-international-series-to-feature-five-games-in-three-countries',
        2025 => 'https://www.nfl.com/news/nfl-announces-2025-international-games-to-feature-seven-games-in-five-countries',
        2026 => 'https://operations.nfl.com/programs-initiatives/international-growth/nfl-international-games',
    ];

    private const GAMES = [
        '2023_04_ATL_JAX' => ['2023-10-01', 'GB', 'Wembley Stadium'],
        '2023_05_JAX_BUF' => ['2023-10-08', 'GB', 'Tottenham Hotspur Stadium'],
        '2023_06_BAL_TEN' => ['2023-10-15', 'GB', 'Tottenham Hotspur Stadium'],
        '2023_09_MIA_KC' => ['2023-11-05', 'DE', 'Frankfurt Stadium'],
        '2023_10_IND_NE' => ['2023-11-12', 'DE', 'Frankfurt Stadium'],
        '2024_01_GB_PHI' => ['2024-09-06', 'BR', 'Corinthians Arena'],
        '2024_05_NYJ_MIN' => ['2024-10-06', 'GB', 'Tottenham Hotspur Stadium'],
        '2024_06_JAX_CHI' => ['2024-10-13', 'GB', 'Tottenham Hotspur Stadium'],
        '2024_07_NE_JAX' => ['2024-10-20', 'GB', 'Wembley Stadium'],
        '2024_10_NYG_CAR' => ['2024-11-10', 'DE', 'Allianz Arena'],
        '2025_01_KC_LAC' => ['2025-09-05', 'BR', 'Corinthians Arena'],
        '2025_04_MIN_PIT' => ['2025-09-28', 'IE', 'Croke Park'],
        '2025_05_MIN_CLE' => ['2025-10-05', 'GB', 'Tottenham Hotspur Stadium'],
        '2025_06_DEN_NYJ' => ['2025-10-12', 'GB', 'Tottenham Hotspur Stadium'],
        '2025_07_LA_JAX' => ['2025-10-19', 'GB', 'Wembley Stadium'],
        '2025_10_ATL_IND' => ['2025-11-09', 'DE', 'Olympic Stadium Berlin'],
        '2025_11_WAS_MIA' => ['2025-11-16', 'ES', 'Santiago Bernabeu Stadium'],
        '2026_01_SF_LA' => ['2026-09-10', 'AU', 'Melbourne Cricket Ground'],
        '2026_03_BAL_DAL' => ['2026-09-27', 'BR', 'Maracana Stadium'],
        '2026_04_IND_WAS' => ['2026-10-04', 'GB', 'Tottenham Hotspur Stadium'],
        '2026_05_PHI_JAX' => ['2026-10-11', 'GB', 'Tottenham Hotspur Stadium'],
        '2026_06_HOU_JAX' => ['2026-10-18', 'GB', 'Wembley Stadium'],
        '2026_07_PIT_NO' => ['2026-10-25', 'FR', 'Stade de France'],
        '2026_09_CIN_ATL' => ['2026-11-08', 'ES', 'Bernabeu Stadium'],
        '2026_10_NE_DET' => ['2026-11-15', 'DE', 'FC Bayern Munich Arena'],
        '2026_11_MIN_SF' => ['2026-11-22', 'MX', 'Estadio Banorte'],
    ];

    public function forScheduleRow(array $row): ?array
    {
        $id = $row['game_id'] ?? '';
        $entry = self::GAMES[$id] ?? null;
        if ($entry === null || ($row['game_type'] ?? null) !== 'REG' || ($row['gameday'] ?? null) !== $entry[0]) {
            return null;
        }
        $expected = sprintf('%04d_%02d_%s_%s', $row['season'] ?? 0, $row['week'] ?? 0, $row['away_team'] ?? '', $row['home_team'] ?? '');
        if ($id !== $expected) {
            return null;
        }

        return ['source' => 'nfl_official_international_schedule', 'source_url' => self::SOURCES[(int) $row['season']],
            'registry_version' => '2026-09-30', 'source_game_id' => $id, 'gameday' => $entry[0],
            'country' => $entry[1], 'stadium' => $entry[2]];
    }
}
