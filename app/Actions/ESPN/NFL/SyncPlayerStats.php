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

        $updates['sacks_taken'] = isset($stats['SACKS']) || isset($stats['SACK'])
            ? (int) ($stats['SACKS'] ?? $stats['SACK'] ?? 0)
            : null;

        return array_filter($updates, fn ($value) => $value !== null);
    }

    protected function parseRushingStats(array $stats): array
    {
        $updates = parent::parseRushingStats($stats);

        $updates['rushing_long'] = isset($stats['LONG']) || isset($stats['LNG'])
            ? (int) ($stats['LONG'] ?? $stats['LNG'] ?? 0)
            : null;

        return array_filter($updates, fn ($value) => $value !== null);
    }

    protected function parseReceivingStats(array $stats): array
    {
        $updates = parent::parseReceivingStats($stats);

        $updates['receiving_long'] = isset($stats['LONG']) || isset($stats['LNG'])
            ? (int) ($stats['LONG'] ?? $stats['LNG'] ?? 0)
            : null;

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

}
