export const formatPropStat = (value: number | null | undefined): string =>
    typeof value === 'number' && Number.isFinite(value) ? String(value) : 'N/A';

export const formatPropOdds = (value: number | null | undefined): string =>
    typeof value === 'number' &&
    Number.isFinite(value) &&
    Math.abs(value) >= 100
        ? `${value > 0 ? '+' : ''}${value}`
        : 'N/A';

export function sideProbability(
    over: number | null | undefined,
    side: 'Over' | 'Under',
): string {
    if (
        typeof over !== 'number' ||
        !Number.isFinite(over) ||
        over < 0 ||
        over > 100
    )
        return 'N/A';
    return `${(side === 'Under' ? 100 - over : over).toFixed(1)}%`;
}

export const formatProbabilityEdge = (
    value: number | null | undefined,
): string =>
    typeof value === 'number' && Number.isFinite(value)
        ? `${value > 0 ? '+' : ''}${value.toFixed(1)} pp`
        : 'N/A';

export function propFreshness(
    fetchedAt: string | null | undefined,
    staleHours: number | null | undefined,
    now: number,
): 'unknown' | 'fresh' | 'stale' {
    const fetched = fetchedAt ? Date.parse(fetchedAt) : NaN;
    if (
        !Number.isFinite(fetched) ||
        fetched > now ||
        !staleHours ||
        staleHours <= 0
    )
        return 'unknown';
    return now - fetched > staleHours * 3_600_000 ? 'stale' : 'fresh';
}

export function formatPropTimestamp(
    value: string | null | undefined,
    timezone = 'America/Chicago',
): string {
    if (!value || !Number.isFinite(Date.parse(value))) return 'Unknown';
    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: timezone,
        timeZoneName: 'short',
    }).format(new Date(value));
}
