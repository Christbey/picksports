export interface BasketballTeamStats {
    field_goals_made: number;
    field_goals_attempted: number;
    three_point_made: number;
    three_point_attempted: number;
    free_throws_made: number;
    free_throws_attempted: number;
    rebounds: number;
    assists: number;
    turnovers: number;
    steals: number;
    blocks: number;
    points_in_paint?: number | null;
    fast_break_points?: number | null;
}
export type BasketballStatRow = {
    key: keyof BasketballTeamStats;
    label: string;
    compactLabel?: string;
    attempted?: keyof BasketballTeamStats;
    lowerIsBetter?: boolean;
    optional?: boolean;
};
export const basketballStatRows: BasketballStatRow[] = [
    {
        key: 'field_goals_made',
        attempted: 'field_goals_attempted',
        label: 'Field Goals',
        compactLabel: 'FG',
    },
    {
        key: 'three_point_made',
        attempted: 'three_point_attempted',
        label: '3-Point',
        compactLabel: '3PT',
    },
    {
        key: 'free_throws_made',
        attempted: 'free_throws_attempted',
        label: 'Free Throws',
        compactLabel: 'FT',
    },
    { key: 'rebounds', label: 'Rebounds' },
    { key: 'assists', label: 'Assists' },
    { key: 'turnovers', label: 'Turnovers', lowerIsBetter: true },
    { key: 'steals', label: 'Steals' },
    { key: 'blocks', label: 'Blocks' },
    { key: 'points_in_paint', label: 'Points in Paint', optional: true },
    { key: 'fast_break_points', label: 'Fast Break Points', optional: true },
];
export const shootingPercentage = (made: number, attempted: number) =>
    !attempted ? '0.0' : ((made / attempted) * 100).toFixed(1);
export function basketballStatValue(
    stats: BasketballTeamStats,
    row: BasketballStatRow,
    grid = false,
): string | number {
    const value = stats[row.key];
    if (row.attempted) return `${value}-${stats[row.attempted]}`;
    return grid && row.optional ? value || '-' : (value ?? '-');
}
