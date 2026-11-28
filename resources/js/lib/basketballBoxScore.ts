export interface BasketballTeamStats {
    team_type?: string;
    field_goals_made: number | null;
    field_goals_attempted: number | null;
    three_point_made: number | null;
    three_point_attempted: number | null;
    free_throws_made: number | null;
    free_throws_attempted: number | null;
    rebounds: number | null;
    assists: number | null;
    turnovers: number | null;
    steals: number | null;
    blocks: number | null;
    points_in_paint?: number | null;
    fast_break_points?: number | null;
}
export type BasketballStatRow = {
    key: Exclude<keyof BasketballTeamStats, 'team_type'>;
    label: string;
    compactLabel?: string;
    attempted?: Exclude<keyof BasketballTeamStats, 'team_type'>;
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
export const shootingPercentage = (
    made: number | null,
    attempted: number | null,
): string => {
    if (
        made === null ||
        attempted === null ||
        !Number.isFinite(made) ||
        !Number.isFinite(attempted) ||
        made < 0 ||
        attempted < made
    )
        return 'Unavailable';
    return attempted === 0 ? '0.0' : ((made / attempted) * 100).toFixed(1);
};
export function basketballStatValue(
    stats: BasketballTeamStats,
    row: BasketballStatRow,
    grid = false,
): string | number {
    const value = stats[row.key];
    if (row.attempted)
        return `${value ?? 'Unavailable'}-${stats[row.attempted] ?? 'Unavailable'}`;
    void grid;
    return value ?? 'Unavailable';
}
