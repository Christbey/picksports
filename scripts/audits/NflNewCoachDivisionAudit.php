<?php

namespace Audit;

/** Offline descriptive audit. No forecast, database or release writes. */
final class NflNewCoachDivisionAudit
{
    public static function run(array $games): array
    {
        usort($games, fn ($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
        $seasons = [];
        foreach ($games as $game) {
            foreach (['home', 'away'] as $side) {
                $sign = $side === 'home' ? 1 : -1;
                $coach = trim(mb_strtolower(preg_replace('/\s+/', ' ', $game[$side.'_coach'] ?? '')));
                $seasons[$game[$side]][$game['season']][] = [
                    'game_id' => $game['id'], 'season' => $game['season'], 'week' => $game['week'],
                    'date' => $game['date'], 'team' => $game[$side], 'opponent' => $game[$side === 'home' ? 'away' : 'home'],
                    'coach' => $coach === '' ? null : $coach, 'coach_name' => $game[$side.'_coach'],
                    'venue' => $game['neutral'] === null ? null : ($game['neutral'] ? 'neutral' : $side),
                    'division' => $game['division'],
                    'line' => $game['home_line'] === null ? null : $sign * $game['home_line'],
                    'margin' => $sign * ($game['home_score'] - $game['away_score']),
                    'snapshot_id' => $game['snapshot_id'],
                ];
            }
        }
        $excluded = [];
        $candidates = [];
        $expected = fn ($season) => $season >= 2021 ? 17 : 16;
        foreach ($seasons as $team => $years) {
            foreach ($years as $season => $rows) {
                if ($season < 2010 || $season > 2025) {
                    continue;
                }
                $previous = $years[$season - 1] ?? [];
                if (count($previous) !== $expected($season - 1) || count($rows) !== $expected($season)) {
                    $excluded[] = ['team' => $team, 'season' => $season, 'reason' => 'incomplete_season_schedule'];

                    continue;
                }
                $coach = $rows[0]['coach'];
                if ($coach === null || in_array(null, array_column($previous, 'coach'), true)) {
                    $excluded[] = ['team' => $team, 'season' => $season, 'reason' => 'unknown_coach_boundary'];

                    continue;
                }
                // An interim promoted in place is not beginning a new tenure.
                // A regular coach returning after a temporary substitute is not a new hire.
                if (in_array($coach, array_column($previous, 'coach'), true)) {
                    continue;
                }
                foreach ($rows as $row) {
                    if ($row['coach'] !== $coach || $row['division'] === null) {
                        $excluded[] = ['team' => $team, 'season' => $season, 'reason' => 'coach_or_division_uncertain_before_first_division_game'];
                        break;
                    }
                    if ($row['division']) {
                        $candidates[] = $row;
                        break;
                    }
                }
            }
        }
        $filters = [
            'all' => fn ($r) => true,
            'home' => fn ($r) => $r['venue'] === 'home',
            'away' => fn ($r) => $r['venue'] === 'away',
            'underdog' => fn ($r) => $r['line'] !== null && $r['line'] > 0,
            'home_underdog' => fn ($r) => $r['venue'] === 'home' && $r['line'] !== null && $r['line'] > 0,
            'home_plus_3_5' => fn ($r) => $r['venue'] === 'home' && $r['line'] !== null && abs($r['line'] - 3.5) < 0.00001,
            'away_plus_8_5' => fn ($r) => $r['venue'] === 'away' && $r['line'] !== null && abs($r['line'] - 8.5) < 0.00001,
            'mike_mccarthy' => fn ($r) => $r['coach'] === 'mike mccarthy',
        ];
        $summaries = [];
        foreach ($filters as $name => $filter) {
            $summaries[$name] = self::summary(array_values(array_filter($candidates, $filter)));
        }

        return ['definition' => 'First divisional regular-season game of a season-opening coach absent from that team’s entire preceding season. Complete adjacent schedules required. Midseason/interim changes not pooled. No week filter.',
            'seasons' => [2010, 2025], 'summaries' => $summaries, 'excluded_team_seasons' => $excluded,
            'candidates' => $candidates, 'production_modified' => false];
    }

    private static function summary(array $rows): array
    {
        $ats = ['W' => 0, 'L' => 0, 'P' => 0];
        $su = ['W' => 0, 'L' => 0, 'T' => 0];
        foreach ($rows as $r) {
            $su[$r['margin'] > 0 ? 'W' : ($r['margin'] < 0 ? 'L' : 'T')]++;
            if ($r['line'] !== null) {
                $cover = $r['margin'] + $r['line'];
                $ats[$cover > 0 ? 'W' : ($cover < 0 ? 'L' : 'P')]++;
            }
        }

        return ['appearances' => count($rows), 'unique_games' => count(array_unique(array_column($rows, 'game_id'))),
            'ats' => $ats, 'outright' => $su, 'missing_lines' => count($rows) - array_sum($ats),
            'ats_win_pct' => $ats['W'] + $ats['L'] ? round(100 * $ats['W'] / ($ats['W'] + $ats['L']), 1) : null,
            'game_ids' => array_values(array_unique(array_column($rows, 'game_id')))];
    }
}
