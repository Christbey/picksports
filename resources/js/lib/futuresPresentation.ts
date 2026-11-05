export const formatPct = (value: number, digits = 1) =>
    `${(value * 100).toFixed(digits)}%`;
export const formatEdgePoints = (value: number | null | undefined) => {
    if (value === null || value === undefined) return '-';
    const rounded = value.toFixed(1);
    return value >= 0 ? `+${rounded}pp` : `${rounded}pp`;
};
export const formatAmericanOdds = (value: number | null | undefined) => {
    if (value === null || value === undefined) return '-';
    return value > 0 ? `+${value}` : `${value}`;
};
export const toPct = (value: number) => Math.max(0, Math.min(100, value * 100));
export const formatTeam = (
    team: {
        location?: string | null;
        name?: string | null;
        abbreviation: string;
    } | null,
    fallback: number,
) => {
    if (!team) return `Team ${fallback}`;
    const full = `${team.location ?? ''} ${team.name ?? ''}`.trim();
    return full || team.abbreviation || `Team ${fallback}`;
};
export const statusTagClass = (row: { playoff_make_probability: number }) => {
    const make = row.playoff_make_probability;
    if (make >= 0.85)
        return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200';
    if (make >= 0.55)
        return 'bg-sky-100 text-sky-800 dark:bg-sky-900/30 dark:text-sky-200';
    if (make >= 0.35)
        return 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200';
    return 'bg-zinc-200 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200';
};
export const meterClass = (value: number) => {
    const pct = toPct(value);
    if (pct >= 70) return 'bg-emerald-500';
    if (pct >= 45) return 'bg-amber-500';
    return 'bg-rose-500';
};
