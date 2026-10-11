export function livePeriodLabel(
    sport: string,
    period: number | null,
    clock: string | null,
    status: string | null = null,
): string {
    const football = ['nfl', 'cfb'].includes(sport.toLowerCase());
    const normalizedClock = clock?.trim() ?? '';
    if (
        football &&
        (status?.toUpperCase() === 'STATUS_HALFTIME' ||
            (period === 2 && /^0+(?::0+)?(?:\.0+)?$/.test(normalizedClock)))
    ) {
        return 'Halftime';
    }

    const parts: string[] = [];
    if (period !== null) {
        parts.push(
            football
                ? period > 4
                    ? `OT${period > 5 ? period - 4 : ''}`
                    : `Q${period}`
                : `Period ${period}`,
        );
    }
    if (normalizedClock) parts.push(normalizedClock);
    return parts.join(' · ') || 'Live game';
}
