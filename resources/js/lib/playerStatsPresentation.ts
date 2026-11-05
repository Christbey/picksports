import type {
    PlayerLeaderboardEntry,
    StatColumn,
    SportPlayerStatsShellConfig,
} from '@/types/player-stats';

export const formatEpa = (value: number | undefined) => {
    return Number(value ?? 0).toFixed(2);
};

export const formatColumnValue = (
    entry: PlayerLeaderboardEntry,
    column: StatColumn,
) => {
    const value = entry[column.key as keyof PlayerLeaderboardEntry];
    const numericValue = typeof value === 'number' ? value : undefined;
    if (column.format) return column.format(numericValue, entry);
    return Number(numericValue ?? 0).toFixed(1);
};

export const rankColorClass = (rankIndex: number, total: number): string => {
    if (total <= 2) return '';

    const topCutoff = Math.max(1, Math.floor(total * 0.2));
    const bottomCutoffStart = Math.ceil(total * 0.8);
    const oneBasedRank = rankIndex + 1;

    if (oneBasedRank <= topCutoff) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    if (oneBasedRank >= bottomCutoffStart) {
        return 'text-rose-600 dark:text-rose-400';
    }

    return '';
};

export function buildDefaultPlayerStatColumns(
    showEpaColumns: boolean | undefined,
): StatColumn[] {
    const columns: NonNullable<SportPlayerStatsShellConfig['statColumns']> = [
        {
            key: 'points_per_game',
            label: 'PPG',
            cellClass: 'p-2 text-right font-medium',
        },
        { key: 'rebounds_per_game', label: 'RPG', cellClass: 'p-2 text-right' },
        { key: 'assists_per_game', label: 'APG', cellClass: 'p-2 text-right' },
        {
            key: 'steals_per_game',
            label: 'SPG',
            cellClass: 'hidden p-2 text-right md:table-cell',
        },
        {
            key: 'blocks_per_game',
            label: 'BPG',
            cellClass: 'hidden p-2 text-right md:table-cell',
        },
        {
            key: 'field_goal_percentage',
            label: 'FG%',
            cellClass: 'hidden p-2 text-right lg:table-cell',
            format: (value) => `${Number(value ?? 0).toFixed(1)}%`,
        },
        {
            key: 'three_point_percentage',
            label: '3P%',
            cellClass: 'hidden p-2 text-right lg:table-cell',
            format: (value) => `${Number(value ?? 0).toFixed(1)}%`,
        },
        {
            key: 'free_throw_percentage',
            label: 'FT%',
            cellClass: 'hidden p-2 text-right lg:table-cell',
            format: (value) => `${Number(value ?? 0).toFixed(1)}%`,
        },
        {
            key: 'minutes_per_game',
            label: 'MPG',
            cellClass:
                'hidden p-2 text-right text-muted-foreground lg:table-cell',
        },
    ];

    if (showEpaColumns) {
        columns.push(
            {
                key: 'estimated_epa_per_game',
                label: 'EPA/G',
                cellClass: 'hidden p-2 text-right font-medium lg:table-cell',
                format: (value) => formatEpa(value),
            },
            {
                key: 'estimated_epa_per_36',
                label: 'EPA/36',
                cellClass: 'hidden p-2 text-right lg:table-cell',
                format: (value) => formatEpa(value),
            },
        );
    }

    return columns;
}
