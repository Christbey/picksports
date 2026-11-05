import type { FeaturedProp, FeaturedPrediction } from '@/types/public-sport';
export const formatMetric = (value: number | string | null | undefined) => {
    if (value === null || value === undefined || value === '') return 'N/A';
    const numeric = Number(value);
    if (Number.isNaN(numeric)) return String(value);
    if (Math.abs(numeric) >= 100) return numeric.toFixed(0);
    if (Math.abs(numeric) >= 10) return numeric.toFixed(1);
    return numeric.toFixed(2);
};
export const formatHomeSpreadLine = (
    value: number | string | null | undefined,
) => {
    if (value === null || value === undefined || value === '') return 'N/A';

    const numeric = Number(value);
    if (Number.isNaN(numeric)) return String(value);

    const homeLine = -numeric;
    if (Math.abs(homeLine) < 0.05) return 'PK';

    return `${homeLine > 0 ? '+' : ''}${homeLine.toFixed(1)}`;
};
export const formatGameDate = (value: string | null | undefined) => {
    if (!value) return 'TBD';

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
};
export const impactTone = (label?: string | null) => {
    if (label === 'High') return 'destructive';
    if (label === 'Medium') return 'secondary';
    return 'outline';
};
export const formatProbability = (value: number | null | undefined) => {
    if (value === null || value === undefined) return 'N/A';
    return `${(value * 100).toFixed(1)}%`;
};
export const coverageBreakdown = (prop: FeaturedProp) => {
    const season = prop.stats?.times_covered_season;
    const last5 = prop.stats?.times_covered_last5;
    const source = season && season.games > 0 ? season : last5;

    if (!source || source.games <= 0) {
        return null;
    }

    return {
        hits: source.hits,
        misses: Math.max(0, source.games - source.hits),
        games: source.games,
        label: source === season ? 'Season cover rate' : 'Last 5 cover rate',
        hitPct: (source.hits / source.games) * 100,
    };
};
export const predictionBarWidth = (prediction: FeaturedPrediction) =>
    `${Math.max(12, Math.min(100, Number(prediction.home_win_probability ?? 0)))}%`;
export const propRecommendationTone = (recommendation: string) =>
    recommendation.toLowerCase() === 'over'
        ? 'border-emerald-400/25 bg-emerald-400/10 text-emerald-200'
        : 'border-rose-400/25 bg-rose-400/10 text-rose-200';
export const propCardTone = (recommendation: string) =>
    recommendation.toLowerCase() === 'over'
        ? 'from-emerald-500/12 via-white/[0.04] to-transparent'
        : 'from-rose-500/12 via-white/[0.04] to-transparent';
