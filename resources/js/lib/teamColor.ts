export function normalizeTeamColor(color?: string | null): string | undefined {
    const hex = color?.trim().replace(/^#/, '');

    return hex && /^(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(hex)
        ? `#${hex}`
        : undefined;
}
