import { formatTeamGameDate, teamGameStart } from '@/lib/teamPageDates';
import type { FeaturedProp, FeaturedPrediction } from '@/types/public-sport';
export const formatMetric = (value: number | string | null | undefined) => {
    if (value === null || value === undefined || value === '') return 'N/A';
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return 'N/A';
    if (Math.abs(numeric) >= 100) return numeric.toFixed(0);
    if (Math.abs(numeric) >= 10) return numeric.toFixed(1);
    return numeric.toFixed(2);
};
export const formatHomeSpreadLine = (
    value: number | string | null | undefined,
) => {
    if (value === null || value === undefined || value === '') return 'N/A';

    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return 'N/A';

    const homeLine = -numeric;
    if (Math.abs(homeLine) < 0.05) return 'PK';

    return `${homeLine > 0 ? '+' : ''}${homeLine.toFixed(1)}`;
};
/** A calendar key never establishes kickoff time. Only explicit offset timestamps do. */
export const formatGameDate = (value: string | null | undefined) => {
    const instant = teamGameStart({ starts_at: value });
    if (instant === null) {
        const calendar = formatTeamGameDate(value ?? null);
        return calendar === '-'
            ? 'Time unavailable'
            : `${calendar} · Time unavailable`;
    }
    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: 'UTC',
        timeZoneName: 'short',
    }).format(new Date(instant));
};
export const impactTone = (label?: string | null) => {
    if (label === 'High') return 'destructive';
    if (label === 'Medium') return 'secondary';
    return 'outline';
};
export const formatProbability = (value: number | null | undefined) => {
    if (
        value === null ||
        value === undefined ||
        !Number.isFinite(value) ||
        value < 0 ||
        value > 1
    )
        return 'N/A';
    return `${(value * 100).toFixed(1)}%`;
};
export const coverageBreakdown = (prop: FeaturedProp) => {
    const side = prop.recommendation;
    if (side !== 'Over' && side !== 'Under') return null;
    for (const [window, legacy, label] of [
        [
            'season',
            prop.stats?.times_covered_season,
            'Season selected-side record',
        ],
        [
            'last_5',
            prop.stats?.times_covered_last5,
            'Last 5 selected-side record',
        ],
    ] as const) {
        const source = prop.stats?.cover_record?.[window] ?? legacy;
        if (!source || !Number.isInteger(source.games) || source.games <= 0)
            continue;
        const hits =
            side === 'Over'
                ? (source.over ??
                  source.hits ??
                  (source.recommendation === side ? source.wins : undefined))
                : (source.under ??
                  (source.recommendation === side ? source.wins : undefined));
        const pushes = source.pushes ?? null;
        if (
            hits == null ||
            !Number.isInteger(hits) ||
            hits < 0 ||
            hits > source.games ||
            (pushes !== null &&
                (!Number.isInteger(pushes) ||
                    pushes < 0 ||
                    hits + pushes > source.games))
        )
            continue;
        return {
            hits,
            misses: pushes === null ? null : source.games - hits - pushes,
            pushes,
            games: source.games,
            label,
            hitPct: (hits / source.games) * 100,
            remainingPct:
                ((source.games - hits - (pushes ?? 0)) / source.games) * 100,
            pushPct: pushes === null ? 0 : (pushes / source.games) * 100,
        };
    }
    return null;
};
export const predictionBarWidth = (prediction: FeaturedPrediction) => {
    const value = prediction.home_win_probability;
    if (value === null || value === undefined || value === '') return null;
    const numeric = Number(value);
    return Number.isFinite(numeric) && numeric >= 0 && numeric <= 100
        ? `${numeric}%`
        : null;
};
export const propRecommendationTone = (recommendation: string) =>
    recommendation.toLowerCase() === 'over'
        ? 'border-emerald-400/25 bg-emerald-400/10 text-emerald-200'
        : 'border-rose-400/25 bg-rose-400/10 text-rose-200';
export const propCardTone = (recommendation: string) =>
    recommendation.toLowerCase() === 'over'
        ? 'from-emerald-500/12 via-white/[0.04] to-transparent'
        : 'from-rose-500/12 via-white/[0.04] to-transparent';
