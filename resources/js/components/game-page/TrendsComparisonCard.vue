<script setup lang="ts">
import NflTeamEvidencePanel from '@/components/game-page/NflTeamEvidencePanel.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { provide } from 'vue';
import {
    useTrendsComparison,
    trendsComparisonKey,
    type TrendsComparisonProps,
} from '@/composables/useTrendsComparison';
import TrendMatchupContext from '@/components/game-page/TrendMatchupContext.vue';
import TrendContextualInsights from '@/components/game-page/TrendContextualInsights.vue';
import TrendCategoryDetails from '@/components/game-page/TrendCategoryDetails.vue';
const props = defineProps<TrendsComparisonProps>();
const state = useTrendsComparison(props);
provide(trendsComparisonKey, state);
const {
    selectedWindow,
    hasEvidence,
    homeView,
    awayView,
    allTrendCategories: visibleCategories,
    signalCountForSide,
    matchupRows,
    displayTitle,
} = state;
</script>
<template>
    <Card class="overflow-hidden py-0">
        <CardHeader class="gap-2 px-4 py-4 md:px-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="ui-kicker">Pattern Signals</div>
                    <CardTitle class="mt-1 text-lg tracking-tight">
                        {{ displayTitle }}
                    </CardTitle>
                </div>
                <div
                    v-if="visibleCategories.length > 0"
                    class="flex gap-2 text-xs"
                >
                    <Badge variant="secondary">
                        {{ visibleCategories.length }}
                        {{
                            visibleCategories.length === 1
                                ? 'category'
                                : 'categories'
                        }}
                    </Badge>
                    <Badge variant="outline">
                        {{
                            signalCountForSide(awayView) +
                            signalCountForSide(homeView)
                        }}
                        signals
                    </Badge>
                </div>
            </div>
            <p v-if="subtitle" class="text-sm text-muted-foreground">
                {{ subtitle }}
            </p>
            <p class="text-xs text-muted-foreground">
                Historical context only. Pattern ranks are not win probabilities
                or validated betting advantages; small samples are especially
                uncertain.
            </p>
        </CardHeader>
        <CardContent class="px-4 pb-4 md:px-5">
            <NflTeamEvidencePanel
                v-if="hasEvidence && !trendsLoading"
                v-model:window="selectedWindow"
                class="mb-4"
                :home="props.homeTrends?.team_evidence"
                :away="props.awayTrends?.team_evidence"
                :home-label="homeLabel || 'Home'"
                :away-label="awayLabel || 'Away'"
            />
            <div v-if="trendsLoading" class="space-y-4">
                <Skeleton class="h-16 w-full" />
                <Skeleton class="h-16 w-full" />
                <Skeleton class="h-16 w-full" />
            </div>

            <div
                v-else-if="
                    visibleCategories.length > 0 || matchupRows.length > 0
                "
                class="space-y-4"
            >
                <div class="grid grid-cols-3 gap-2">
                    <div
                        class="rounded-lg border border-border/70 bg-muted/30 px-3 py-2"
                    >
                        <p
                            class="text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            Categories
                        </p>
                        <p class="mt-1 text-xl font-semibold">
                            {{ visibleCategories.length }}
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
                            {{ signalCountForSide(awayView) }}
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
                            {{ signalCountForSide(homeView) }}
                        </p>
                    </div>
                </div>

                <TrendMatchupContext />

                <TrendContextualInsights />

                <div
                    v-if="
                        !hasEvidence &&
                        topMatchupEdges &&
                        topMatchupEdges.length > 0
                    "
                    class="rounded-lg border border-border/70 bg-muted/25 p-3"
                >
                    <h4 class="mb-2 text-sm font-semibold">
                        Supporting Patterns
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

                <TrendCategoryDetails />
            </div>

            <div v-else class="py-8 text-center text-muted-foreground">
                {{ emptyText }}
            </div>
        </CardContent>
    </Card>
</template>
