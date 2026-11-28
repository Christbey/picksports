import type { Game } from '@/types';

const timestamp =
    /^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/;

const calendarDate = (value: string | null | undefined): Date | null => {
    if (typeof value !== 'string') return null;
    const parts = /^(\d{4})-(\d{2})-(\d{2})(?:$|T)/.exec(value);
    if (
        !parts ||
        (value.length !== 10 &&
            (!timestamp.test(value) || !Number.isFinite(Date.parse(value))))
    )
        return null;
    const [, year, month, day] = parts;
    const date = new Date(
        Date.UTC(Number(year), Number(month) - 1, Number(day)),
    );
    return date.getUTCFullYear() === Number(year) &&
        date.getUTCMonth() === Number(month) - 1 &&
        date.getUTCDate() === Number(day)
        ? date
        : null;
};

/** Format the supplied calendar date; UTC here prevents a second browser-zone conversion, not a kickoff inference. */
export const formatTeamGameDate = (value: string | null): string => {
    const date = calendarDate(value);
    return date
        ? date.toLocaleDateString('en-US', {
              month: 'short',
              day: 'numeric',
              timeZone: 'UTC',
          })
        : '-';
};

/** Only the server's explicit offset-bearing starts_at can establish relative kickoff order. */
export const teamGameStart = (game: Pick<Game, 'starts_at'>): number | null => {
    const value = game.starts_at;
    if (
        typeof value !== 'string' ||
        !timestamp.test(value) ||
        !calendarDate(value)
    )
        return null;
    const instant = Date.parse(value);
    return Number.isFinite(instant) ? instant : null;
};

/** Calculation dates are calendar revision keys, not provider observation instants. */
export const teamMetricCalculationDate = (value: unknown): string | null =>
    typeof value === 'string' && calendarDate(value)
        ? value.slice(0, 10)
        : null;
