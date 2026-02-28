import { computed, type Ref } from 'vue';
import { formatTierName } from '@/composables/useFormatters';
import type { TeamTrendData } from '@/types';

export const formatTrendCategoryName = (key: string | null | undefined): string => {
    if (!key) return 'General';

    const names: Record<string, string> = {
        scoring: 'Scoring',
        halves: 'Halves',
        margins: 'Margins',
        totals: 'Totals',
        first_score: 'First Score',
        situational: 'Situational',
        streaks: 'Streaks',
        advanced: 'Advanced',
        time_based: 'Time Based',
        rest_schedule: 'Rest & Schedule',
        opponent_strength: 'Opponent Strength',
        conference: 'Conference',
        scoring_patterns: 'Scoring Patterns',
        offensive_efficiency: 'Offensive Efficiency',
        defensive_performance: 'Defensive Performance',
        momentum: 'Momentum',
        clutch_performance: 'Clutch Performance',
    };

    return names[key] || key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
};

export function useTeamTrends<T extends TeamTrendData>(
    homeTrends: Ref<T | null>,
    awayTrends?: Ref<T | null>,
) {
    const allTrendCategories = computed(() => {
        const categories = new Set<string>();

        const append = (data: TeamTrendData | null) => {
            if (!data) return;
            Object.keys(data.trends ?? {}).forEach((key) => categories.add(key));
            Object.keys(data.locked_trends ?? {}).forEach((key) => categories.add(key));
        };

        append(homeTrends.value);
        if (awayTrends) append(awayTrends.value);

        return Array.from(categories).sort();
    });

    const isLockedCategory = (category: string): boolean => {
        return !!(
            homeTrends.value?.locked_trends?.[category] ||
            awayTrends?.value?.locked_trends?.[category]
        );
    };

    const getRequiredTier = (category: string): string => {
        return (
            homeTrends.value?.locked_trends?.[category] ||
            awayTrends?.value?.locked_trends?.[category] ||
            'pro'
        );
    };

    return {
        allTrendCategories,
        isLockedCategory,
        getRequiredTier,
        formatTierName,
        formatTrendCategoryName,
    };
}
