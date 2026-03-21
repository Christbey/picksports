<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractFootballSyncPlayerStats;

class SyncPlayerStats extends AbstractFootballSyncPlayerStats
{
    protected const TEAM_MODEL_CLASS = \App\Models\NFL\Team::class;

    protected const PLAYER_MODEL_CLASS = \App\Models\NFL\Player::class;

    protected const PLAYER_STAT_MODEL_CLASS = \App\Models\NFL\PlayerStat::class;

    protected function parseCategoryUpdates(string $category, array $mappedStats): array
    {
        if (
            str_contains($category, 'return')
            || str_contains($category, 'kick return')
            || str_contains($category, 'punt return')
        ) {
            return $this->parseReturningStats($mappedStats, $category);
        }

        return match ($category) {
            'passing' => $this->parsePassingStats($mappedStats),
            'rushing' => $this->parseRushingStats($mappedStats),
            'receiving' => $this->parseReceivingStats($mappedStats),
            'defensive', 'defense' => $this->parseDefensiveStats($mappedStats),
            'kicking' => $this->parseKickingStats($mappedStats),
            default => [],
        };
    }

    protected function passingCompletionsField(): string
    {
        return 'passing_completions';
    }

    protected function passingAttemptsField(): string
    {
        return 'passing_attempts';
    }

    protected function interceptionsField(): string
    {
        return 'interceptions_thrown';
    }

    protected function rushingAttemptsField(): string
    {
        return 'rushing_attempts';
    }

    protected function receptionsField(): string
    {
        return 'receptions';
    }

    protected function receivingTargetsField(): string
    {
        return 'receiving_targets';
    }

    protected function parsePassingStats(array $stats): array
    {
        $updates = parent::parsePassingStats($stats);

        $updates['sacks_taken'] = $this->intFromKeys($stats, ['SACKS', 'SACK', 'SK']);
        $updates['sack_yards_lost'] = $this->intFromKeys($stats, ['SACK YDS LOST', 'SACKYDS', 'SKYDS', 'SACK_YARDS']);
        $updates['passing_long'] = $this->intFromKeys($stats, ['LONG', 'LNG']);
        $updates['passing_two_point_conversions'] = $this->intFromKeys($stats, ['2PT', '2PT PASS', '2PT PASS CONV', '2PT PASS CV']);

        return array_filter($updates, fn ($value) => $value !== null);
    }

    protected function parseRushingStats(array $stats): array
    {
        $updates = parent::parseRushingStats($stats);

        $updates['rushing_long'] = $this->intFromKeys($stats, ['LONG', 'LNG']);
        $updates['rushing_two_point_conversions'] = $this->intFromKeys($stats, ['2PT', '2PT RUSH', '2PT RUSH CONV', '2PT RUSH CV']);

        return array_filter($updates, fn ($value) => $value !== null);
    }

    protected function parseReceivingStats(array $stats): array
    {
        $updates = parent::parseReceivingStats($stats);

        $updates['receiving_long'] = $this->intFromKeys($stats, ['LONG', 'LNG']);
        $updates['receiving_two_point_conversions'] = $this->intFromKeys($stats, ['2PT', '2PT REC', '2PT REC CONV', '2PT REC CV']);

        return array_filter($updates, fn ($value) => $value !== null);
    }

    protected function parseReturningStats(array $stats, string $category): array
    {
        $updates = [];
        $isKick = str_contains($category, 'kick');
        $isPunt = str_contains($category, 'punt');

        if ($isKick || ! $isPunt) {
            $updates['kickoff_returns'] = $this->intFromKeys($stats, ['RET', 'KR', 'KRET']);
            $updates['kickoff_return_yards'] = $this->intFromKeys($stats, ['YDS', 'KRYDS', 'KR YDS']);
            $updates['kickoff_return_touchdowns'] = $this->intFromKeys($stats, ['TD', 'KRTD']);
            $updates['kickoff_return_long'] = $this->intFromKeys($stats, ['LONG', 'LNG', 'KR LONG']);
            $updates['kickoff_return_fair_catches'] = $this->intFromKeys($stats, ['FC', 'KRFair', 'KR FC']);
        }

        if ($isPunt || ! $isKick) {
            $updates['punt_returns'] = $this->intFromKeys($stats, ['RET', 'PR', 'PRET']);
            $updates['punt_return_yards'] = $this->intFromKeys($stats, ['YDS', 'PRYDS', 'PR YDS']);
            $updates['punt_return_touchdowns'] = $this->intFromKeys($stats, ['TD', 'PRTD']);
            $updates['punt_return_long'] = $this->intFromKeys($stats, ['LONG', 'LNG', 'PR LONG']);
            $updates['punt_return_fair_catches'] = $this->intFromKeys($stats, ['FC', 'PRFair', 'PR FC']);
        }

        return array_filter($updates, fn ($value) => $value !== null);
    }

    protected function parseDefensiveStats(array $stats): array
    {
        $updates = [];

        $updates['tackles_total'] = isset($stats['TOT']) || isset($stats['TOTAL']) ? (int) ($stats['TOT'] ?? $stats['TOTAL'] ?? 0) : null;
        $updates['tackles_solo'] = isset($stats['SOLO']) ? (int) $stats['SOLO'] : null;
        $updates['tackles_assists'] = isset($stats['AST']) ? (int) $stats['AST'] : null;
        $updates['sacks'] = isset($stats['SACKS']) || isset($stats['SACK']) ? (float) ($stats['SACKS'] ?? $stats['SACK'] ?? 0) : null;
        $updates['interceptions'] = isset($stats['INT']) || isset($stats['INTS'])
            ? (int) ($stats['INT'] ?? $stats['INTS'] ?? 0)
            : null;
        $updates['passes_defended'] = isset($stats['PD']) || isset($stats['PDEF'])
            ? (int) ($stats['PD'] ?? $stats['PDEF'] ?? 0)
            : null;
        $updates['fumbles_forced'] = isset($stats['FF']) ? (int) $stats['FF'] : null;
        $updates['fumbles_recovered'] = isset($stats['FR']) || isset($stats['REC']) ? (int) ($stats['FR'] ?? $stats['REC'] ?? 0) : null;

        return array_filter($updates, fn ($value) => $value !== null);
    }

    protected function parseKickingStats(array $stats): array
    {
        $updates = [];

        [$fgm, $fga] = $this->resolveMadeAttemptPair($stats, [
            ['FG', '/'],
            ['FGM/FGA', '/'],
        ], ['FGM', 'FGA']);
        if ($fgm !== null) {
            $updates['field_goals_made'] = $fgm;
        }
        if ($fga !== null) {
            $updates['field_goals_attempted'] = $fga;
        }

        [$xpm, $xpa] = $this->resolveMadeAttemptPair($stats, [
            ['XP', '/'],
            ['PAT', '/'],
            ['XP/PAT', '/'],
            ['XPM/XPA', '/'],
            ['PATM/PATA', '/'],
        ], ['XPM', 'XPA', 'PATM', 'PATA']);
        if ($xpm !== null) {
            $updates['extra_points_made'] = $xpm;
        }
        if ($xpa !== null) {
            $updates['extra_points_attempted'] = $xpa;
        }

        return array_filter($updates, fn ($value) => $value !== null);
    }

    /**
     * @param  array<string,mixed>  $stats
     * @param  array<int,array{0:string,1:string}>  $compoundKeys
     * @param  array<int,string>  $simpleKeys
     * @return array{0:?int,1:?int}
     */
    protected function resolveMadeAttemptPair(array $stats, array $compoundKeys, array $simpleKeys): array
    {
        foreach ($compoundKeys as [$key, $separator]) {
            $value = isset($stats[$key]) ? (string) $stats[$key] : '';
            if ($value === '' || ! str_contains($value, $separator)) {
                continue;
            }

            $parts = explode($separator, $value);
            if (count($parts) !== 2) {
                continue;
            }

            return [(int) trim($parts[0]), (int) trim($parts[1])];
        }

        $madeKey = null;
        $attemptKey = null;
        foreach ($simpleKeys as $key) {
            if (str_ends_with($key, 'M') && isset($stats[$key])) {
                $madeKey = $key;
            }
            if (str_ends_with($key, 'A') && isset($stats[$key])) {
                $attemptKey = $key;
            }
        }

        if ($madeKey !== null || $attemptKey !== null) {
            return [
                $madeKey !== null ? (int) $stats[$madeKey] : null,
                $attemptKey !== null ? (int) $stats[$attemptKey] : null,
            ];
        }

        return [null, null];
    }

    /**
     * @param  array<string,mixed>  $stats
     * @param  array<int,string>  $keys
     */
    protected function intFromKeys(array $stats, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $stats)) {
                continue;
            }

            $value = $stats[$key];
            if ($value === null || $value === '') {
                continue;
            }

            if (is_numeric($value)) {
                return (int) $value;
            }

            if (is_string($value)) {
                if (preg_match('/-?\\d+/', $value, $matches) === 1) {
                    return (int) $matches[0];
                }
            }
        }

        return null;
    }
}
