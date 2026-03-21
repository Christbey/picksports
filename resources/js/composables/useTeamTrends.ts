import { computed, type Ref } from 'vue';
import { formatTierName } from '@/composables/useFormatters';
import type { TeamTrendData } from '@/types';

export const formatTrendCategoryName = (
    key: string | null | undefined,
): string => {
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

    return (
        names[key] ||
        key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
    );
};

export function useTeamTrends<T extends TeamTrendData>(
    homeTrends: Ref<T | null>,
    awayTrends?: Ref<T | null>,
) {
    const trendSignalScore = (message: string): number => {
        const normalized = message.toLowerCase();
        let score = 20;

        const percentMatches = [...message.matchAll(/(\d+(?:\.\d+)?)%/g)];
        if (percentMatches.length > 0) {
            const maxPercent = Math.max(
                ...percentMatches.map((m) => Number(m[1]) || 0),
            );
            score = Math.max(score, maxPercent);
        }

        const ratioMatch = message.match(/\((\d+)\/(\d+)\)/);
        if (ratioMatch) {
            const wins = Number(ratioMatch[1]) || 0;
            const total = Number(ratioMatch[2]) || 1;
            score = Math.max(score, (wins / total) * 100);
        }

        const volumeMatch = normalized.match(
            /in\s+(\d+)\s+of\s+(?:their\s+)?last\s+(\d+)/,
        );
        if (volumeMatch) {
            const wins = Number(volumeMatch[1]) || 0;
            const total = Number(volumeMatch[2]) || 1;
            score = Math.max(score, (wins / total) * 100);
        }

        const emphasisKeywords = [
            'winning streak',
            'primetime',
            'underdogs',
            'average',
        ];
        if (emphasisKeywords.some((keyword) => normalized.includes(keyword))) {
            score += 8;
        }

        return score;
    };

    const topMatchupEdges = computed(() => {
        const candidates: Array<{
            team: string;
            category: string;
            message: string;
            score: number;
        }> = [];

        const append = (data: TeamTrendData | null, fallbackLabel: string) => {
            if (!data?.trends) return;
            const teamLabel = data.team_abbreviation || fallbackLabel;

            Object.entries(data.trends).forEach(([category, messages]) => {
                messages.forEach((message) => {
                    const cleaned = String(message || '').trim();
                    if (cleaned === '') return;
                    candidates.push({
                        team: teamLabel,
                        category,
                        message: cleaned,
                        score: trendSignalScore(cleaned),
                    });
                });
            });
        };

        append(awayTrends?.value ?? null, 'Away');
        append(homeTrends.value, 'Home');

        const sorted = candidates.sort((a, b) => b.score - a.score);
        const selected: string[] = [];
        const seenCategoryTeam = new Set<string>();

        for (const candidate of sorted) {
            const key = `${candidate.team}:${candidate.category}`;
            if (seenCategoryTeam.has(key)) continue;

            selected.push(
                `${candidate.team} (${formatTrendCategoryName(candidate.category)}): ${candidate.message}`,
            );
            seenCategoryTeam.add(key);

            if (selected.length >= 3) break;
        }

        return selected;
    });

    const allTrendCategories = computed(() => {
        const categories = new Set<string>();

        const append = (data: TeamTrendData | null) => {
            if (!data) return;
            Object.keys(data.trends ?? {}).forEach((key) =>
                categories.add(key),
            );
        };

        append(homeTrends.value);
        if (awayTrends) append(awayTrends.value);

        return Array.from(categories).sort();
    });

    const isLockedCategory = (): boolean => {
        return false;
    };

    const getRequiredTier = (): string => {
        return 'free';
    };

    return {
        topMatchupEdges,
        allTrendCategories,
        isLockedCategory,
        getRequiredTier,
        formatTierName,
        formatTrendCategoryName,
    };
}
