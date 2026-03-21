export function normalizeSeasonType(value: unknown): string {
    return String(value ?? '')
        .trim()
        .toLowerCase();
}

export function isMlbRegularSeasonType(value: unknown): boolean {
    const normalized = normalizeSeasonType(value);

    return (
        normalized === '2' ||
        normalized === 'regular' ||
        normalized === 'regular season'
    );
}

export function isMlbSpringTrainingType(value: unknown): boolean {
    const normalized = normalizeSeasonType(value);

    return (
        normalized === '1' ||
        normalized === 'preseason' ||
        normalized === 'spring training' ||
        normalized === 'spring_training'
    );
}
