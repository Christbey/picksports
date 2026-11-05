import {
    trendSignalScore,
    classifyTrendTone,
    signalCountForSide,
    signalsForCategory,
    totalForCategory,
    matchupWinRate,
    formatMatchupWinRate,
} from '@/lib/trendPresentation';
import { computed, ref } from 'vue';
import { isConditionalPattern } from '@/lib/trendContext';
import type {
    MatchupContextRow,
    TeamTrendData,
    TeamTrendSignal,
} from '@/types';
import { inject, type InjectionKey, toRefs } from 'vue';
import type {
    TrendTone,
    TrendInsight,
    TrendsComparisonProps,
} from '@/types/trends-comparison';

export function useTrendsComparison(props: TrendsComparisonProps) {
    const selectedWindow = ref('recent_5');
    const hasEvidence = computed(
        () =>
            !!(
                props.homeTrends?.team_evidence ||
                props.awayTrends?.team_evidence
            ),
    );
    const homeView = computed(() =>
        hasEvidence.value
            ? (props.homeTrends?.team_evidence?.windows[selectedWindow.value] ??
              null)
            : props.homeTrends,
    );
    const awayView = computed(() =>
        hasEvidence.value
            ? (props.awayTrends?.team_evidence?.windows[selectedWindow.value] ??
              null)
            : props.awayTrends,
    );
    const allTrendCategories = computed(() =>
        hasEvidence.value
            ? [
                  ...new Set([
                      ...Object.keys(homeView.value?.trends ?? {}),
                      ...Object.keys(awayView.value?.trends ?? {}),
                  ]),
              ]
            : props.allTrendCategories,
    );

    const sideLabel = (side: 'away' | 'home'): string =>
        side === 'away' ? props.awayLabel || 'Away' : props.homeLabel || 'Home';
    const insightLabel = (side: 'away' | 'home', tone: TrendTone): string => {
        if (tone === 'total') return 'Total context';
        if (tone === 'risk') return `${sideLabel(side)} risk`;
        return `${sideLabel(side)} context`;
    };
    const trendInsights = computed<TrendInsight[]>(() => {
        const insights: TrendInsight[] = [];

        const append = (
            side: 'away' | 'home',
            trends: TeamTrendData | null | undefined,
        ) => {
            if (!trends?.trends) return;

            if (Array.isArray(trends.scored_signals)) {
                trends.scored_signals.forEach(
                    (signal: TeamTrendSignal, index) => {
                        const message = String(signal.message || '').trim();
                        if (!message) return;

                        const tone = ['team', 'total', 'risk'].includes(
                            signal.tone,
                        )
                            ? (signal.tone as TrendTone)
                            : classifyTrendTone(message, signal.category) ||
                              'team';

                        insights.push({
                            key: `${side}-${signal.category}-${signal.id || index}`,
                            label: insightLabel(side, tone),
                            detail: props.formatCategoryName(signal.category),
                            category: signal.category,
                            message,
                            score: Number(signal.score) || 0,
                            tone,
                            quality: signal.quality,
                            confidence: signal.confidence,
                            sampleSize: signal.sample_size,
                            reasonCodes: signal.reason_codes ?? [],
                            direction: signal.direction,
                        });
                    },
                );

                return;
            }

            Object.entries(trends.trends).forEach(([category, messages]) => {
                messages.forEach((rawMessage, index) => {
                    const message = String(rawMessage || '').trim();
                    if (!message) return;

                    const tone = classifyTrendTone(message, category);
                    if (!tone) return;

                    insights.push({
                        key: `${side}-${category}-${index}`,
                        label: insightLabel(side, tone),
                        detail: props.formatCategoryName(category),
                        category,
                        message,
                        score: trendSignalScore(message, category),
                        tone,
                        quality: undefined,
                        confidence: undefined,
                        sampleSize: undefined,
                        reasonCodes: [],
                    });
                });
            });
        };

        append('away', awayView.value);
        append('home', homeView.value);

        return insights.sort((a, b) => b.score - a.score);
    });
    const contextualTrendCards = computed<TrendInsight[]>(() => {
        const selected: TrendInsight[] = [];

        const pushBest = (tone: TrendTone) => {
            const available = trendInsights.value.filter(
                (insight) =>
                    insight.tone === tone &&
                    !isConditionalPattern(insight.category, insight.message) &&
                    !selected.some(
                        (item) =>
                            item.key === insight.key ||
                            item.message === insight.message,
                    ),
            );
            const found =
                tone === 'team'
                    ? available.find(
                          (insight) => insight.direction === 'support',
                      ) || available[0]
                    : available[0];
            if (found) selected.push(found);
        };

        pushBest('team');
        pushBest('total');
        pushBest('risk');

        return selected;
    });

    const matchupSampleLabel = (row: MatchupContextRow): string => {
        return (['away', 'home'] as const)
            .map((side) => {
                const games = row[side].games || 0;
                return `${sideLabel(side)}: ${games} ${games === 1 ? 'game' : 'games'}`;
            })
            .join(' · ');
    };
    const matchupHistoryLabel = (row: MatchupContextRow): string => {
        const awayRate = matchupWinRate(row.away);
        const homeRate = matchupWinRate(row.home);

        if (awayRate === null && homeRate === null) return 'No sample';
        if (awayRate === null || homeRate === null) return 'Incomplete sample';
        if (awayRate === homeRate) return 'Equal historical win rates';
        if ((awayRate ?? 0) > (homeRate ?? 0)) {
            return `${props.awayLabel || 'Away'} higher historical win rate`;
        }

        return `${props.homeLabel || 'Home'} higher historical win rate`;
    };
    const matchupRows = computed(() => props.matchupContext?.rows ?? []);
    const displayTitle = computed(() =>
        matchupRows.value.length > 0 ? 'Trends & Matchup History' : props.title,
    );
    return {
        ...toRefs(props),
        props,
        selectedWindow,
        hasEvidence,
        homeView,
        awayView,
        allTrendCategories,
        contextualTrendCards,
        signalCountForSide,
        signalsForCategory,
        totalForCategory,
        formatMatchupWinRate,
        matchupSampleLabel,
        matchupHistoryLabel,
        matchupRows,
        displayTitle,
    };
}
export const trendsComparisonKey: InjectionKey<
    ReturnType<typeof useTrendsComparison>
> = Symbol('TrendsComparison');
export function useTrendsComparisonContext() {
    const state = inject(trendsComparisonKey);
    if (!state) throw new Error('TrendsComparison provider is missing.');
    return state;
}

export type { TrendsComparisonProps } from '@/types/trends-comparison';
