<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import type { TeamTrendData } from '@/types';

defineProps<{
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

const signalCountForSide = (side: TeamTrendData | null | undefined): number => {
    if (!side?.trends) return 0;
    return Object.values(side.trends).reduce((total, entries) => total + entries.length, 0);
};

const signalsForCategory = (
    side: TeamTrendData | null | undefined,
    category: string,
): number => {
    return side?.trends?.[category]?.length || 0;
};
</script>

<template>
    <Card class="overflow-hidden border-zinc-200/80 bg-gradient-to-b from-white to-zinc-50/30 shadow-sm dark:border-zinc-800 dark:from-zinc-950 dark:to-zinc-900/50">
        <CardHeader>
            <CardTitle class="text-lg">{{ title }}</CardTitle>
            <p v-if="subtitle" class="text-sm text-muted-foreground">{{ subtitle }}</p>
        </CardHeader>
        <CardContent>
            <div v-if="trendsLoading" class="space-y-4">
                <Skeleton class="h-24 w-full" />
                <Skeleton class="h-24 w-full" />
                <Skeleton class="h-24 w-full" />
            </div>

            <div v-else-if="allTrendCategories.length > 0" class="space-y-6">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-zinc-200/80 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-zinc-900/80">
                        <p class="text-xs uppercase tracking-wide text-muted-foreground">Categories</p>
                        <p class="mt-1 text-xl font-semibold">{{ allTrendCategories.length }}</p>
                    </div>
                    <div class="rounded-lg border border-zinc-200/80 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-zinc-900/80">
                        <p class="text-xs uppercase tracking-wide text-muted-foreground">{{ awayLabel || 'Away' }} Signals</p>
                        <p class="mt-1 text-xl font-semibold">{{ signalCountForSide(awayTrends) }}</p>
                    </div>
                    <div class="rounded-lg border border-zinc-200/80 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-zinc-900/80">
                        <p class="text-xs uppercase tracking-wide text-muted-foreground">{{ homeLabel || 'Home' }} Signals</p>
                        <p class="mt-1 text-xl font-semibold">{{ signalCountForSide(homeTrends) }}</p>
                    </div>
                </div>

                <div v-if="topMatchupEdges && topMatchupEdges.length > 0" class="rounded-xl border border-emerald-200/70 bg-emerald-50/70 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/20">
                    <h4 class="mb-2 text-sm font-semibold text-emerald-800 dark:text-emerald-300">Top Matchup Edges</h4>
                    <ul class="space-y-1 text-sm">
                        <li v-for="(edge, idx) in topMatchupEdges" :key="idx" class="flex items-start gap-2">
                            <span class="mt-1 h-1.5 w-1.5 rounded-full bg-emerald-500" />
                            <span>{{ edge }}</span>
                        </li>
                    </ul>
                </div>

                <div v-for="category in allTrendCategories" :key="category" class="rounded-xl border border-zinc-200/80 bg-white/95 p-4 dark:border-zinc-800 dark:bg-zinc-900/80">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <h4 class="font-semibold">{{ formatCategoryName(category) }}</h4>
                        <Badge variant="outline" class="text-[11px]">
                            {{ (awayTrends?.trends?.[category]?.length || 0) + (homeTrends?.trends?.[category]?.length || 0) }} signals
                        </Badge>
                    </div>

                    <div v-if="isLockedCategory(category)" class="rounded-lg border border-zinc-200/70 bg-zinc-50 py-4 text-center dark:border-zinc-800 dark:bg-zinc-900/70">
                        <div class="text-sm text-muted-foreground">
                            Upgrade to {{ formatTierName(getRequiredTier(category)) }} to unlock this trend
                        </div>
                    </div>

                    <div v-else class="grid gap-3 md:grid-cols-2">
                        <div class="rounded-lg border border-zinc-200/70 bg-zinc-50/70 p-3 dark:border-zinc-800 dark:bg-zinc-900/50">
                            <div class="mb-2 flex items-center justify-between">
                                <div class="text-sm font-medium text-muted-foreground">{{ awayLabel }}</div>
                                <span class="text-xs text-muted-foreground">{{ signalsForCategory(awayTrends, category) }} signals</span>
                            </div>
                            <ul v-if="awayTrends?.trends?.[category]?.length" class="space-y-1 text-sm">
                                <li v-for="(trend, idx) in awayTrends.trends[category]" :key="idx" class="flex items-start gap-2">
                                    <span class="mt-1 h-1.5 w-1.5 rounded-full bg-zinc-500" />
                                    <span>{{ trend }}</span>
                                </li>
                            </ul>
                            <p v-else class="text-sm text-muted-foreground">No trends available</p>
                        </div>
                        <div class="rounded-lg border border-zinc-200/70 bg-zinc-50/70 p-3 dark:border-zinc-800 dark:bg-zinc-900/50">
                            <div class="mb-2 flex items-center justify-between">
                                <div class="text-sm font-medium text-muted-foreground">{{ homeLabel }}</div>
                                <span class="text-xs text-muted-foreground">{{ signalsForCategory(homeTrends, category) }} signals</span>
                            </div>
                            <ul v-if="homeTrends?.trends?.[category]?.length" class="space-y-1 text-sm">
                                <li v-for="(trend, idx) in homeTrends.trends[category]" :key="idx" class="flex items-start gap-2">
                                    <span class="mt-1 h-1.5 w-1.5 rounded-full bg-zinc-500" />
                                    <span>{{ trend }}</span>
                                </li>
                            </ul>
                            <p v-else class="text-sm text-muted-foreground">No trends available</p>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="text-center py-8 text-muted-foreground">
                {{ emptyText }}
            </div>
        </CardContent>
    </Card>
</template>
