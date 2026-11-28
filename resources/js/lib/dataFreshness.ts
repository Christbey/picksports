export const storedTimestamp = (value: unknown): string => {
    if (typeof value !== 'string' || !value.trim()) return 'Unavailable';
    const date = new Date(value);
    return Number.isFinite(date.getTime())
        ? date.toLocaleString()
        : 'Unavailable';
};

export const availabilityFreshness = (
    items: Array<{
        source_updated_at?: string | null;
        source_updated_at_raw?: string | null;
    }>,
): string => {
    if (!items.length)
        return 'Report refresh time unavailable; an empty list does not verify a fresh report.';
    const supplied = items.filter((item) => {
        const raw = item.source_updated_at_raw ?? item.source_updated_at;
        return typeof raw === 'string' && raw.trim().length > 0;
    }).length;
    return `Source dates supplied for ${supplied}/${items.length} listings; their timezone and report freshness are unverified.`;
};
