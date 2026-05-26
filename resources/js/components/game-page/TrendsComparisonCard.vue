<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import type { TeamTrendData, TeamTrendSignal } from '@/types';

type TrendTone = 'team' | 'total' | 'risk';

interface TrendInsight {
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
}

const props = defineProps<{
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
    emptyText: string;
}>();

const percentFromMessage = (message: string): number | null => {
    const matches = [...message.matchAll(/(\d+(?:\.\d+)?)%/g)];
    if (matches.length === 0) return null;

    return Math.max(...matches.map((match) => Number(match[1]) || 0));
};

const ratioScoreFromMessage = (message: string): number | null => {
    const ratioMatch = message.match(/\((\d+)\/(\d+)\)/);
    if (!ratioMatch) return null;

    const wins = Number(ratioMatch[1]) || 0;
    const total = Number(ratioMatch[2]) || 0;
    if (total <= 0) return null;

    return (wins / total) * 100;
};

const volumeScoreFromMessage = (message: string): number | null => {
    const volumeMatch = message
        .toLowerCase()
        .match(/in\s+(\d+)\s+of\s+(?:their\s+)?last\s+(\d+)/);
    if (!volumeMatch) return null;

    const wins = Number(volumeMatch[1]) || 0;
    const total = Number(volumeMatch[2]) || 0;
    if (total <= 0) return null;

    return (wins / total) * 100;
};

const trendSignalScore = (message: string, category: string): number => {
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

const classifyTrendTone = (
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

const sideLabel = (side: 'away' | 'home'): string =>
    side === 'away' ? props.awayLabel || 'Away' : props.homeLabel || 'Home';

const trendInsights = computed<TrendInsight[]>(() => {
    const insights: TrendInsight[] = [];

    const append = (
        side: 'away' | 'home',
        trends: TeamTrendData | null | undefined,
    ) => {
        if (!trends?.trends) return;

        if (Array.isArray(trends.scored_signals)) {
            trends.scored_signals.forEach((signal: TeamTrendSignal, index) => {
                const message = String(signal.message || '').trim();
                if (!message) return;

                const tone = ['team', 'total', 'risk'].includes(signal.tone)
                    ? (signal.tone as TrendTone)
                    : classifyTrendTone(message, signal.category) || 'team';

                insights.push({
                    key: `${side}-${signal.category}-${signal.id || index}`,
                    label:
                        tone === 'total'
                            ? 'Total context'
                            : tone === 'risk'
                              ? `${sideLabel(side)} risk`
                              : `${sideLabel(side)} edge`,
                    detail: props.formatCategoryName(signal.category),
                    category: signal.category,
                    message,
                    score: Number(signal.score) || 0,
                    tone,
                    quality: signal.quality,
                    confidence: signal.confidence,
                    sampleSize: signal.sample_size,
                    reasonCodes: signal.reason_codes ?? [],
                });
            });

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
                    label:
                        tone === 'total'
                            ? 'Total context'
                            : tone === 'risk'
                              ? `${sideLabel(side)} risk`
                              : `${sideLabel(side)} edge`,
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

    append('away', props.awayTrends);
    append('home', props.homeTrends);

    return insights.sort((a, b) => b.score - a.score);
});

const actionableTrendCards = computed<TrendInsight[]>(() => {
    const selected: TrendInsight[] = [];

    const pushBest = (tone: TrendTone) => {
        const found = trendInsights.value.find(
            (insight) =>
                insight.tone === tone &&
                !selected.some((item) => item.key === insight.key),
        );
        if (found) selected.push(found);
    };

    pushBest('team');
    pushBest('total');
    pushBest('risk');

    return selected;
});

const signalCountForSide = (side: TeamTrendData | null | undefined): number => {
    if (!side?.trends) return 0;
    return Object.values(side.trends).reduce(
        (total, entries) => total + entries.length,
        0,
    );
};

const signalsForCategory = (
    side: TeamTrendData | null | undefined,
    category: string,
): number => {
    return side?.trends?.[category]?.length || 0;
};

const totalForCategory = (
    awayTrends: TeamTrendData | null | undefined,
    homeTrends: TeamTrendData | null | undefined,
    category: string,
): number =>
    signalsForCategory(awayTrends, category) +
    signalsForCategory(homeTrends, category);
</script>

<template>
    <Card class="overflow-hidden py-0">
        <CardHeader class="gap-2 px-4 py-4 md:px-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="ui-kicker">Pattern Signals</div>
                    <CardTitle class="mt-1 text-lg tracking-tight">
                        {{ title }}
                    </CardTitle>
                </div>
                <div
                    v-if="allTrendCategories.length > 0"
                    class="flex gap-2 text-xs"
                >
                    <Badge variant="secondary">
                        {{ allTrendCategories.length }} categories
                    </Badge>
                    <Badge variant="outline">
                        {{
                            signalCountForSide(awayTrends) +
                            signalCountForSide(homeTrends)
                        }}
                        signals
                    </Badge>
                </div>
            </div>
            <p v-if="subtitle" class="text-sm text-muted-foreground">
                {{ subtitle }}
            </p>
        </CardHeader>
        <CardContent class="px-4 pb-4 md:px-5">
            <div v-if="trendsLoading" class="space-y-4">
                <Skeleton class="h-16 w-full" />
                <Skeleton class="h-16 w-full" />
                <Skeleton class="h-16 w-full" />
            </div>

            <div v-else-if="allTrendCategories.length > 0" class="space-y-4">
                <div class="grid gap-2 sm:grid-cols-3">
                    <div
                        class="rounded-lg border border-border/70 bg-muted/30 px-3 py-2"
                    >
                        <p
                            class="text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            Categories
                        </p>
                        <p class="mt-1 text-xl font-semibold">
                            {{ allTrendCategories.length }}
                        </p>
                    </div>
                    <div
                        class="rounded-lg border border-border/70 bg-muted/30 px-3 py-2"
                    >
                        <p
                            class="text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            {{ awayLabel || 'Away' }} Signals
                        </p>
                        <p class="mt-1 text-xl font-semibold">
                            {{ signalCountForSide(awayTrends) }}
                        </p>
                    </div>
                    <div
                        class="rounded-lg border border-border/70 bg-muted/30 px-3 py-2"
                    >
                        <p
                            class="text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            {{ homeLabel || 'Home' }} Signals
                        </p>
                        <p class="mt-1 text-xl font-semibold">
                            {{ signalCountForSide(homeTrends) }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="actionableTrendCards.length > 0"
                    class="grid gap-3 lg:grid-cols-3"
                >
                    <div
                        v-for="insight in actionableTrendCards"
                        :key="insight.key"
                        class="rounded-lg border p-3"
                        :class="{
                            'border-emerald-300/70 bg-emerald-500/10 dark:border-emerald-800/60 dark:bg-emerald-500/12':
                                insight.tone === 'team',
                            'border-blue-300/70 bg-blue-500/10 dark:border-blue-800/60 dark:bg-blue-500/12':
                                insight.tone === 'total',
                            'border-amber-300/70 bg-amber-500/10 dark:border-amber-800/60 dark:bg-amber-500/12':
                                insight.tone === 'risk',
                        }"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p
                                    class="text-xs tracking-wide text-muted-foreground uppercase"
                                >
                                    {{ insight.label }}
                                </p>
                                <p class="mt-1 text-sm font-semibold">
                                    {{ insight.detail }}
                                </p>
                            </div>
                            <Badge variant="outline" class="shrink-0 text-[11px]">
                                {{ insight.score }}
                            </Badge>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <Badge
                                v-if="insight.quality"
                                variant="secondary"
                                class="text-[10px] capitalize"
                            >
                                {{ insight.quality }}
                            </Badge>
                            <Badge
                                v-if="insight.confidence"
                                variant="outline"
                                class="text-[10px] capitalize"
                            >
                                {{ insight.confidence.replace('_', ' ') }}
                            </Badge>
                            <Badge
                                v-if="insight.sampleSize"
                                variant="outline"
                                class="text-[10px]"
                            >
                                {{ insight.sampleSize }} games
                            </Badge>
                        </div>
                        <p class="mt-2 text-sm leading-relaxed">
                            {{ insight.message }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="topMatchupEdges && topMatchupEdges.length > 0"
                    class="rounded-lg border border-border/70 bg-muted/25 p-3"
                >
                    <h4 class="mb-2 text-sm font-semibold">
                        Top Matchup Edges
                    </h4>
                    <ul class="space-y-1 text-sm">
                        <li
                            v-for="(edge, idx) in topMatchupEdges"
                            :key="idx"
                            class="flex items-start gap-2"
                        >
                            <span
                                class="mt-1 h-1.5 w-1.5 rounded-full bg-emerald-500"
                            />
                            <span>{{ edge }}</span>
                        </li>
                    </ul>
                </div>

                <details
                    v-for="category in allTrendCategories"
                    :key="category"
                    class="group rounded-lg border border-border/70 bg-card"
                >
                    <summary
                        class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 [&::-webkit-details-marker]:hidden"
                    >
                        <div class="min-w-0">
                            <h4 class="truncate text-sm font-semibold">
                                {{ formatCategoryName(category) }}
                            </h4>
                            <p class="text-xs text-muted-foreground">
                                {{ awayLabel || 'Away' }}
                                {{ signalsForCategory(awayTrends, category) }}
                                / {{ homeLabel || 'Home' }}
                                {{ signalsForCategory(homeTrends, category) }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <Badge variant="outline" class="text-[11px]">
                                {{
                                    totalForCategory(
                                        awayTrends,
                                        homeTrends,
                                        category,
                                    )
                                }}
                                signals
                            </Badge>
                            <span
                                class="text-xs text-muted-foreground transition-transform group-open:rotate-180"
                            >
                                v
                            </span>
                        </div>
                    </summary>

                    <div
                        v-if="isLockedCategory(category)"
                        class="mx-3 mb-3 rounded-lg border border-zinc-200/70 bg-zinc-50 py-4 text-center dark:border-zinc-800 dark:bg-zinc-900/70"
                    >
                        <div class="text-sm text-muted-foreground">
                            Upgrade to
                            {{ formatTierName(getRequiredTier(category)) }} to
                            unlock this trend
                        </div>
                    </div>

                    <div
                        v-else
                        class="grid gap-3 border-t border-border/70 p-3 md:grid-cols-2"
                    >
                        <div
                            class="rounded-lg border border-border/70 bg-muted/25 p-3"
                        >
                            <div class="mb-2 flex items-center justify-between">
                                <div
                                    class="text-sm font-medium text-muted-foreground"
                                >
                                    {{ awayLabel }}
                                </div>
                                <span class="text-xs text-muted-foreground"
                                    >{{
                                        signalsForCategory(awayTrends, category)
                                    }}
                                    signals</span
                                >
                            </div>
                            <ul
                                v-if="awayTrends?.trends?.[category]?.length"
                                class="space-y-1 text-sm"
                            >
                                <li
                                    v-for="(trend, idx) in awayTrends.trends[
                                        category
                                    ]"
                                    :key="idx"
                                    class="flex items-start gap-2"
                                >
                                    <span
                                        class="mt-1 h-1.5 w-1.5 rounded-full bg-zinc-500"
                                    />
                                    <span>{{ trend }}</span>
                                </li>
                            </ul>
                            <p v-else class="text-sm text-muted-foreground">
                                No trends available
                            </p>
                        </div>
                        <div
                            class="rounded-lg border border-border/70 bg-muted/25 p-3"
                        >
                            <div class="mb-2 flex items-center justify-between">
                                <div
                                    class="text-sm font-medium text-muted-foreground"
                                >
                                    {{ homeLabel }}
                                </div>
                                <span class="text-xs text-muted-foreground"
                                    >{{
                                        signalsForCategory(homeTrends, category)
                                    }}
                                    signals</span
                                >
                            </div>
                            <ul
                                v-if="homeTrends?.trends?.[category]?.length"
                                class="space-y-1 text-sm"
                            >
                                <li
                                    v-for="(trend, idx) in homeTrends.trends[
                                        category
                                    ]"
                                    :key="idx"
                                    class="flex items-start gap-2"
                                >
                                    <span
                                        class="mt-1 h-1.5 w-1.5 rounded-full bg-zinc-500"
                                    />
                                    <span>{{ trend }}</span>
                                </li>
                            </ul>
                            <p v-else class="text-sm text-muted-foreground">
                                No trends available
                            </p>
                        </div>
                    </div>
                </details>
            </div>

            <div v-else class="py-8 text-center text-muted-foreground">
                {{ emptyText }}
            </div>
        </CardContent>
    </Card>
</template>
