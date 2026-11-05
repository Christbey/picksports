import type { MatchupContextRow, TeamTrendData } from '@/types';
import type { TrendTone } from '@/types/trends-comparison';
export const percentFromMessage = (message: string): number | null => {
    const matches = [...message.matchAll(/(\d+(?:\.\d+)?)%/g)];
    if (matches.length === 0) return null;

    return Math.max(...matches.map((match) => Number(match[1]) || 0));
};
export const ratioScoreFromMessage = (message: string): number | null => {
    const ratioMatch = message.match(/\((\d+)\/(\d+)\)/);
    if (!ratioMatch) return null;

    const wins = Number(ratioMatch[1]) || 0;
    const total = Number(ratioMatch[2]) || 0;
    if (total <= 0) return null;

    return (wins / total) * 100;
};
export const volumeScoreFromMessage = (message: string): number | null => {
    const volumeMatch = message
        .toLowerCase()
        .match(/in\s+(\d+)\s+of\s+(?:their\s+)?last\s+(\d+)/);
    if (!volumeMatch) return null;

    const wins = Number(volumeMatch[1]) || 0;
    const total = Number(volumeMatch[2]) || 0;
    if (total <= 0) return null;

    return (wins / total) * 100;
};
export const trendSignalScore = (message: string, category: string): number => {
    const normalized = message.toLowerCase();
    const scoredValues = [
        percentFromMessage(message),
        ratioScoreFromMessage(message),
        volumeScoreFromMessage(message),
    ].filter((value): value is number => value !== null);

    let score = scoredValues.length > 0 ? Math.max(...scoredValues) : 50;

    if (
        [
            'streak',
            'winning streak',
            'covered',
            'average',
            'clutch',
            'rest',
            'home',
            'away',
            'division',
            'conference',
        ].some((keyword) => normalized.includes(keyword))
    ) {
        score += 8;
    }

    if (
        [
            'totals',
            'scoring',
            'scoring_patterns',
            'defensive_performance',
            'offensive_efficiency',
        ].includes(category)
    ) {
        score += 4;
    }

    return Math.min(100, Math.round(score));
};
export const classifyTrendTone = (
    message: string,
    category: string,
): TrendTone | null => {
    const normalized = message.toLowerCase();

    if (
        [
            'over',
            'under',
            'total',
            'high-scoring',
            'low-scoring',
            'scored',
            'allowed',
            'runs',
            'points',
        ].some((keyword) => normalized.includes(keyword)) ||
        ['totals', 'scoring', 'scoring_patterns'].includes(category)
    ) {
        return 'total';
    }

    if (
        [
            'lost',
            'loss',
            'struggle',
            'allowed',
            'failed',
            'cold',
            'poor',
            'decline',
            'turnover',
            'foul',
            'blown',
        ].some((keyword) => normalized.includes(keyword))
    ) {
        return 'risk';
    }

    if (
        [
            'won',
            'winning',
            'covered',
            'streak',
            'margin',
            'home',
            'away',
            'rest',
            'clutch',
            'conference',
            'division',
            'opponent',
        ].some((keyword) => normalized.includes(keyword))
    ) {
        return 'team';
    }

    return null;
};
export const signalCountForSide = (
    side: TeamTrendData | null | undefined,
): number => {
    if (!side?.trends) return 0;
    return Object.values(side.trends).reduce(
        (total, entries) => total + entries.length,
        0,
    );
};
export const signalsForCategory = (
    side: TeamTrendData | null | undefined,
    category: string,
): number => {
    return side?.trends?.[category]?.length || 0;
};
export const totalForCategory = (
    awayTrends: TeamTrendData | null | undefined,
    homeTrends: TeamTrendData | null | undefined,
    category: string,
): number =>
    signalsForCategory(awayTrends, category) +
    signalsForCategory(homeTrends, category);
export const matchupWinRate = (
    record: MatchupContextRow['away'],
): number | null => {
    if (!record.games) return null;

    return (record.wins + record.ties * 0.5) / record.games;
};
export const formatMatchupWinRate = (
    record: MatchupContextRow['away'],
): string => {
    const rate = matchupWinRate(record);

    return rate === null ? 'No sample' : `${Math.round(rate * 100)}%`;
};
