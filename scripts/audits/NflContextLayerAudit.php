<?php

namespace Audit;

/** Offline descriptive cohorts. Callers supply verified identities and market lines. */
final class NflContextLayerAudit
{
    public static function timeWindow(?string $kickoff): ?string
    {
        if ($kickoff === null || ! preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $kickoff)) {
            return null;
        }
        try {
            $time = (new \DateTimeImmutable($kickoff))->setTimezone(new \DateTimeZone('America/Chicago'));
        } catch (\Exception) {
            return null;
        }
        $hour = (int) $time->format('G');

        return match (true) {
            $hour === 12 => 'noon',
            $hour === 15 => 'afternoon_3',
            $hour >= 18 && $hour < 23 => 'night',
            default => 'other',
        };
    }

    /** Complete prior postseason field only; never infer non-playoff from absent data. */
    public static function playoffFields(array $games): array
    {
        $seasons = [];
        foreach ($games as $game) {
            $seasons[$game['season']][$game['id']] = $game;
        }
        $fields = [];
        foreach ($seasons as $season => $rows) {
            $teams = array_unique(array_merge(array_column($rows, 'home'), array_column($rows, 'away')));
            if (count($rows) === ($season >= 2020 ? 13 : 11) && count($teams) === ($season >= 2020 ? 14 : 12)) {
                $fields[$season] = $teams;
            }
        }

        return $fields;
    }

    /** Lower points allowed/game is better; tied boundaries do not qualify. */
    public static function defenseRanks(array $totals): array
    {
        if (count($totals) !== 32 || count(array_filter($totals, fn ($t) => $t['games'] >= 3)) !== 32) {
            return [];
        }
        $values = array_map(fn ($t) => $t['allowed'] / $t['games'], $totals);
        $ranks = [];
        foreach ($values as $team => $value) {
            $better = count(array_filter($values, fn ($v) => $v < $value - 0.000001));
            $tied = count(array_filter($values, fn ($v) => abs($v - $value) < 0.000001));
            $ranks[$team] = ['rank' => $better + 1, 'rank_end' => $better + $tied, 'points_allowed_per_game' => round($value, 2)];
        }

        return $ranks;
    }

    /** Maps are verified career rookie seasons, keyed by GSIS QB ID / normalized HC name. */
    public static function observations(array $games, array $playoffs, array $qbRookieYears = [], array $coachRookieYears = []): array
    {
        usort($games, fn ($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
        $byDate = [];
        foreach ($games as $game) {
            $byDate[$game['date']][] = $game;
        }
        $totals = [];
        $rows = [];
        foreach ($byDate as $daily) {
            // All games on a date use the same pre-date rankings.
            foreach ($daily as $game) {
                $season = $game['season'];
                $ranks = self::defenseRanks($totals[$season] ?? []);
                foreach (['away', 'home'] as $side) {
                    $opposite = $side === 'home' ? 'away' : 'home';
                    $sign = $side === 'home' ? 1 : -1;
                    $qb = $game[$opposite.'_qb_id'] ?? null;
                    $coach = mb_strtolower(trim($game[$opposite.'_coach'] ?? ''));
                    $defense = $ranks[$game[$opposite]] ?? null;
                    $rows[] = [
                        'game_id' => $game['id'], 'date' => $game['date'], 'season' => $season,
                        'team' => $game[$side], 'opponent' => $game[$opposite],
                        'coach' => $game[$side.'_coach'] ?? null,
                        'qb_id' => $game[$side.'_qb_id'] ?? null,
                        'venue' => $game['neutral'] === null ? null : ($game['neutral'] ? 'neutral' : $side),
                        'line' => $game['home_line'] === null ? null : $sign * $game['home_line'],
                        'margin' => $sign * ($game['home_score'] - $game['away_score']),
                        'time_window' => self::timeWindow($game['kickoff'] ?? null),
                        'vs_previous_playoff' => isset($playoffs[$season - 1]) ? in_array($game[$opposite], $playoffs[$season - 1], true) : null,
                        'vs_rookie_qb' => isset($qbRookieYears[$qb]) ? $qbRookieYears[$qb] === $season : null,
                        'vs_rookie_coach' => isset($coachRookieYears[$coach]) ? $coachRookieYears[$coach] === $season : null,
                        'opponent_scoring_defense' => $defense,
                        'vs_top_5_scoring_defense' => $defense === null ? null : $defense['rank_end'] <= 5,
                        'vs_top_10_scoring_defense' => $defense === null ? null : $defense['rank_end'] <= 10,
                        'vs_bottom_5_scoring_defense' => $defense === null ? null : $defense['rank'] > 27,
                        'vs_bottom_10_scoring_defense' => $defense === null ? null : $defense['rank'] > 22,
                    ];
                }
            }
            foreach ($daily as $game) {
                foreach (['home', 'away'] as $side) {
                    $opposite = $side === 'home' ? 'away' : 'home';
                    $t = &$totals[$game['season']][$game[$side]];
                    $t ??= ['games' => 0, 'allowed' => 0];
                    $t['games']++;
                    $t['allowed'] += $game[$opposite.'_score'];
                    unset($t);
                }
            }
        }

        return $rows;
    }

    public static function record(array $rows): array
    {
        $ats = ['W' => 0, 'L' => 0, 'P' => 0];
        $su = ['W' => 0, 'L' => 0, 'T' => 0];
        foreach ($rows as $row) {
            $su[$row['margin'] > 0 ? 'W' : ($row['margin'] < 0 ? 'L' : 'T')]++;
            if ($row['line'] !== null) {
                $margin = $row['margin'] + $row['line'];
                $ats[$margin > 0 ? 'W' : ($margin < 0 ? 'L' : 'P')]++;
            }
        }

        return ['sample' => count($rows), 'ats' => $ats, 'outright' => $su,
            'missing_lines' => count($rows) - array_sum($ats),
            'ats_win_pct' => $ats['W'] + $ats['L'] ? round(100 * $ats['W'] / ($ats['W'] + $ats['L']), 1) : null,
            'game_ids' => array_values(array_unique(array_column($rows, 'game_id')))];
    }

    /** Same situations for team, head coach, recorded starting QB and their intersection. */
    public static function identities(array $rows, string $team, ?string $coach, ?string $qbId, ?float $line, ?string $venue, ?string $window): array
    {
        $normalize = fn ($name) => mb_strtolower(trim(preg_replace('/\s+/', ' ', $name ?? '')));
        $coach = $normalize($coach);
        $hasCoach = $coach !== '';
        $hasQb = $qbId !== null && trim($qbId) !== '';
        $coachMatch = fn ($r) => $hasCoach && $normalize($r['coach']) === $coach;
        $qbMatch = fn ($r) => $hasQb && ($r['qb_id'] ?? null) === $qbId;
        $exact = fn ($r) => $line !== null && $venue !== null && $r['line'] !== null && abs($r['line'] - $line) < 0.00001 && $r['venue'] === $venue;
        $cohorts = [
            'team' => [true, fn ($r) => $r['team'] === $team],
            'coach_all_teams' => [$hasCoach, $coachMatch],
            'qb_all_teams' => [$hasQb, $qbMatch],
            'coach_and_qb_all_teams' => [$hasCoach && $hasQb, fn ($r) => $coachMatch($r) && $qbMatch($r)],
            'team_and_coach' => [$hasCoach, fn ($r) => $r['team'] === $team && $coachMatch($r)],
            'team_and_qb' => [$hasQb, fn ($r) => $r['team'] === $team && $qbMatch($r)],
            'team_coach_and_qb' => [$hasCoach && $hasQb, fn ($r) => $r['team'] === $team && $coachMatch($r) && $qbMatch($r)],
        ];
        $situations = [
            'all' => [true, fn ($r) => true],
            'same_time_window' => [$window !== null, fn ($r) => $r['time_window'] === $window],
            'home_underdog' => [true, fn ($r) => $r['venue'] === 'home' && $r['line'] !== null && $r['line'] > 0],
            'same_line_venue' => [$line !== null && $venue !== null, $exact],
            'same_line_venue_time' => [$line !== null && $venue !== null && $window !== null, fn ($r) => $exact($r) && $r['time_window'] === $window],
        ];
        $result = [];
        foreach ($situations as $name => [$available, $filter]) {
            $sample = array_filter($rows, $filter);
            foreach ($cohorts as $cohort => [$identified, $matches]) {
                $record = $available && $identified ? self::record(array_filter($sample, $matches)) : null;
                $result[$name][$cohort] = ['status' => $record === null ? 'unavailable' : ($record['sample'] === 0 ? 'no_sample' : 'available'), 'record' => $record];
            }
        }

        return $result;
    }

    public static function layer(array $rows, string $field, mixed $value, string $team, ?float $line, ?string $venue, ?string $coach = null): array
    {
        $known = array_filter($rows, fn ($r) => ($r[$field] ?? null) !== null);
        $matches = array_filter($known, fn ($r) => $r[$field] === $value);
        $exact = fn ($r) => $line !== null && $venue !== null && $r['line'] !== null && abs($r['line'] - $line) < 0.00001 && $r['venue'] === $venue;

        return ['field' => $field, 'value' => $value, 'known_appearances' => count($known),
            'unknown_appearances' => count($rows) - count($known),
            'team' => self::record(array_filter($matches, fn ($r) => $r['team'] === $team)),
            'team_same_line_venue' => self::record(array_filter($matches, fn ($r) => $r['team'] === $team && $exact($r))),
            'league_same_line_venue' => self::record(array_filter($matches, $exact)),
            'coach' => $coach === null ? null : self::record(array_filter($matches, fn ($r) => $r['coach'] === $coach)),
        ];
    }
}
