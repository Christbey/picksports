import type { MatchupContextData, TeamTrendData } from '@/types';
export type TrendTone = 'team' | 'total' | 'risk';
export interface TrendInsight {
    key: string;
    label: string;
    detail: string;
    category: string;
    message: string;
    score: number;
    tone: TrendTone;
    quality?: string;
    confidence?: string;
    sampleSize?: number | null;
    reasonCodes?: string[];
    direction?: string;
}
export type TrendsComparisonProps = {
    title: string;
    subtitle?: string;
    trendsLoading: boolean;
    topMatchupEdges?: string[];
    allTrendCategories: string[];
    formatCategoryName: (category: string) => string;
    isLockedCategory: (category: string) => boolean;
    formatTierName: (tier: string) => string;
    getRequiredTier: (category: string) => string;
    awayLabel?: string | null;
    homeLabel?: string | null;
    awayTrends?: TeamTrendData | null;
    homeTrends?: TeamTrendData | null;
    matchupContext?: MatchupContextData | null;
    emptyText: string;
};
