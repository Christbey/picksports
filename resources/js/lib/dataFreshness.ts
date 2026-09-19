export const storedTimestamp = (value: unknown): string => {
    if (typeof value !== 'string' || !value.trim()) return 'Unavailable';
    const date = new Date(value);
    return Number.isFinite(date.getTime())
        ? date.toLocaleString()
        : 'Unavailable';
};

export const availabilityFreshness = (
    items: Array<{ source_updated_at?: string | null }>,
): string => {
    const timestamps = items
        .map((item) => item.source_updated_at)
        .filter(
            (value): value is string =>
                typeof value === 'string' && Number.isFinite(Date.parse(value)),
        );
    if (!items.length)
        return 'Report refresh time unavailable; an empty list does not verify a fresh report.';
    const oldest = timestamps.sort((a, b) => Date.parse(a) - Date.parse(b))[0];
    return `Source timestamps: ${timestamps.length}/${items.length} listings. Oldest supplied update: ${storedTimestamp(oldest)}.`;
};
