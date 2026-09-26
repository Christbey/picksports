/** Select the current slate before falling back to the next or most recent slate. */
export function currentSlateDate(
    dates: string[],
    today: string,
): string | null {
    const ordered = [...new Set(dates)].sort();
    if (ordered.includes(today)) return today;
    const start = new Date(`${today}T12:00:00Z`);
    start.setUTCDate(start.getUTCDate() - ((start.getUTCDay() + 6) % 7));
    const end = new Date(start);
    end.setUTCDate(end.getUTCDate() + 6);
    const week = ordered.filter(
        (date) =>
            date >= start.toISOString().slice(0, 10) &&
            date <= end.toISOString().slice(0, 10),
    );
    return (
        week.find((date) => date >= today) ??
        week.at(-1) ??
        ordered.find((date) => date >= today) ??
        ordered.at(-1) ??
        null
    );
}

export function footballSeason(today: string): number {
    const [year, month] = today.split('-').map(Number);
    return month <= 2 ? year - 1 : year;
}
