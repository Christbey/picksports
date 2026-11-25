import type { ApiV2SportSlug } from '@/types';

/** Match BasketballSeason and SportsPipelineRegistry in America/Chicago. */
export function currentSportSeason(
    sport: ApiV2SportSlug,
    date = new Date(),
): number {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: 'America/Chicago',
        year: 'numeric',
        month: 'numeric',
    }).formatToParts(date);
    const year = Number(parts.find((part) => part.type === 'year')?.value);
    const month = Number(parts.find((part) => part.type === 'month')?.value);

    switch (sport) {
        case 'nba':
        case 'cbb':
        case 'wcbb':
            return year + (month >= 7 ? 1 : 0);
        case 'nfl':
        case 'cfb':
            return year - (month <= 2 ? 1 : 0);
        default:
            return year;
    }
}

export function defaultSportSeason(
    sport: ApiV2SportSlug,
    availableSeasons: number[],
    date = new Date(),
): string {
    const seasons = availableSeasons.filter(Number.isFinite);
    if (seasons.length === 0) return '';
    const current = currentSportSeason(sport, date);
    if (seasons.includes(current)) return String(current);

    // Retain available historical coverage until the new season has data.
    const past = seasons.filter((season) => season < current);
    return String(past.length ? Math.max(...past) : Math.min(...seasons));
}
