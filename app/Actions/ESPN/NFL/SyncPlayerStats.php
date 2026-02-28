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
        $updates['interceptions'] = isset($stats['INT']) ? (int) $stats['INT'] : null;
        $updates['passes_defended'] = isset($stats['PD']) ? (int) $stats['PD'] : null;
        $updates['fumbles_forced'] = isset($stats['FF']) ? (int) $stats['FF'] : null;
        $updates['fumbles_recovered'] = isset($stats['FR']) || isset($stats['REC']) ? (int) ($stats['FR'] ?? $stats['REC'] ?? 0) : null;

        return array_filter($updates, fn ($value) => $value !== null);
    }

    protected function parseKickingStats(array $stats): array
    {
        $updates = [];

        if (isset($stats['FG'])) {
            $parts = explode('/', (string) $stats['FG']);
            if (count($parts) === 2) {
                $updates['field_goals_made'] = (int) $parts[0];
                $updates['field_goals_attempted'] = (int) $parts[1];
            }
        }

        if (isset($stats['XP']) || isset($stats['PAT'])) {
            $xpStat = (string) ($stats['XP'] ?? $stats['PAT'] ?? '');
            $parts = explode('/', $xpStat);
            if (count($parts) === 2) {
                $updates['extra_points_made'] = (int) $parts[0];
                $updates['extra_points_attempted'] = (int) $parts[1];
            }
        }

        return array_filter($updates, fn ($value) => $value !== null);
    }

}
