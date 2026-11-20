<script setup lang="ts">
import NflTeamEvidencePanel from '@/components/game-page/NflTeamEvidencePanel.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { computed, provide, ref } from 'vue';
import type { TeamTrendData } from '@/types';
import { Button } from '@/components/ui/button';
import {
    useTrendsComparison,
    trendsComparisonKey,
    type TrendsComparisonProps,
} from '@/composables/useTrendsComparison';
import TrendMatchupContext from '@/components/game-page/TrendMatchupContext.vue';
import TrendContextualInsights from '@/components/game-page/TrendContextualInsights.vue';
import TrendCategoryDetails from '@/components/game-page/TrendCategoryDetails.vue';
const props = defineProps<TrendsComparisonProps & { workspace?: boolean }>();
const state = useTrendsComparison(props);
provide(trendsComparisonKey, state);
const {
    selectedWindow,
    hasEvidence,
    homeView,
    awayView,
    allTrendCategories: visibleCategories,
    matchupRows,
    displayTitle,
} = state;
function comparisonValues(team: TeamTrendData | null | undefined) {
    const evidence = team?.evidence;
    const games = evidence?.sample_size ?? team?.sample_size;
    const record = evidence?.record;
    const margin = evidence?.metrics.margin;

    return {
        window: games && games > 0 ? evidence?.label : undefined,
        games: games && games > 0 ? String(games) : undefined,
        record:
            record && record.wins + record.losses + record.ties > 0
                ? `${record.wins}–${record.losses}–${record.ties}`
                : undefined,
        margin:
            margin?.value != null && margin.sample_size > 0
                ? `${margin.value > 0 ? '+' : ''}${margin.value.toFixed(1)}${margin.sample_size !== games ? ` (${margin.sample_size} games)` : ''}`
                : undefined,
    };
}

const comparisonRows = computed(() => {
    const away = comparisonValues(awayView.value);
    const home = comparisonValues(homeView.value);
    const metrics = [
        { key: 'window', label: 'Sample window' },
        { key: 'games', label: 'Games in sample' },
        { key: 'record', label: 'Record (W–L–T)' },
        { key: 'margin', label: 'Avg. scoring margin' },
    ] as const;

    return metrics
        .map(({ key, label }) => ({ label, away: away[key], home: home[key] }))
        .filter((row) => row.away || row.home);
});

const mobileView = ref('evidence');
const views = {
    evidence: 'Team Evidence',
    history: 'History',
    patterns: 'Patterns',
};
const viewClass = (view: string) =>
    hasEvidence.value && mobileView.value !== view
        ? props.workspace
            ? 'hidden'
            : 'hidden md:block'
        : '';
</script>
<template>
    <section
        v-if="workspace"
        class="ui-surface space-y-3 p-4"
        aria-label="Team comparison"
    >
        <h2 class="text-lg font-semibold">Team comparison</h2>
        <p v-if="trendsLoading" class="text-sm text-muted-foreground">
            Loading team evidence…
        </p>
        <NflTeamEvidencePanel
            v-else-if="hasEvidence"
            v-model:window="selectedWindow"
            workspace
            :home="props.homeTrends?.team_evidence"
            :away="props.awayTrends?.team_evidence"
            :home-label="homeLabel || 'Home'"
            :away-label="awayLabel || 'Away'"
        />
        <p v-else class="text-sm text-muted-foreground">
            Team evidence unavailable.
        </p>
        <details>
            <summary
                class="min-h-11 cursor-pointer content-center text-sm font-medium"
            >
                Matchup history
            </summary>
            <TrendMatchupContext />
            <p v-if="!matchupRows.length" class="text-sm text-muted-foreground">
                No matchup history is available.
            </p>
        </details>
        <details>
            <summary
                class="min-h-11 cursor-pointer content-center text-sm font-medium"
            >
                Advanced patterns &amp; filters
            </summary>
            <p class="text-xs text-muted-foreground">
                Historical context only. Pattern ranks are not win probabilities
                or validated betting advantages; small samples are uncertain.
            </p>
            <TrendContextualInsights /><TrendCategoryDetails />
        </details>
    </section>
    <Card v-else class="overflow-hidden py-0">
        <CardHeader class="gap-2 px-4 py-4 md:px-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div v-if="!workspace" class="ui-kicker">
                        Pattern Signals
                    </div>
                    <CardTitle class="mt-1 text-lg tracking-tight">
                        {{ displayTitle }}
                    </CardTitle>
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
            <nav
                v-if="hasEvidence && !trendsLoading"
                aria-label="Trends views"
                class="mb-4 grid grid-cols-3 gap-1 rounded-xl border bg-muted/40 p-1"
                :class="{ 'md:hidden': !workspace }"
            >
                <Button
                    v-for="(label, view) in views"
                    :key="view"
                    type="button"
                    class="h-auto min-h-11 min-w-0 px-1 whitespace-normal sm:px-3"
                    :variant="mobileView === view ? 'default' : 'ghost'"
                    :aria-pressed="mobileView === view"
                    @click="mobileView = view"
                >
                    {{ label }}
                </Button>
            </nav>
            <NflTeamEvidencePanel
                v-if="hasEvidence && !trendsLoading"
                v-model:window="selectedWindow"
                :class="viewClass('evidence')"
                :workspace="workspace"
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
                    comparisonRows.length > 0 ||
                    visibleCategories.length > 0 ||
                    matchupRows.length > 0 ||
                    (!hasEvidence && topMatchupEdges?.length)
                "
                class="space-y-4"
            >
                <div :class="viewClass('history')">
                    <TrendMatchupContext />
                    <p
                        v-if="!matchupRows.length && hasEvidence"
                        class="py-4 text-sm text-muted-foreground"
                        :class="{ 'md:hidden': !workspace }"
                    >
                        No matchup history is available.
                    </p>
                </div>

                <div class="space-y-4" :class="viewClass('patterns')">
                    <div
                        v-if="comparisonRows.length"
                        class="overflow-hidden rounded-lg border border-border"
                    >
                        <table
                            class="w-full text-sm tabular-nums"
                            aria-label="Team trends comparison"
                        >
                            <thead
                                class="bg-muted/50 text-xs text-muted-foreground"
                            >
                                <tr>
                                    <th
                                        scope="col"
                                        class="px-3 py-2 text-left font-medium"
                                    >
                                        Team comparison
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-3 py-2 text-right font-semibold"
                                    >
                                        {{ awayLabel || 'Away' }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-3 py-2 text-right font-semibold"
                                    >
                                        {{ homeLabel || 'Home' }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in comparisonRows"
                                    :key="row.label"
                                    class="border-t border-border"
                                >
                                    <th
                                        scope="row"
                                        class="px-3 py-2 text-left font-medium"
                                    >
                                        {{ row.label }}
                                    </th>
                                    <td class="px-3 py-2 text-right">
                                        {{ row.away ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        {{ row.home ?? '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <TrendContextualInsights />

                    <TrendCategoryDetails
                        :supporting-patterns="
                            hasEvidence ? undefined : topMatchupEdges
                        "
                    />
                </div>
            </div>

            <div
                v-else-if="!hasEvidence || mobileView !== 'evidence'"
                class="py-8 text-center text-muted-foreground"
            >
                {{ emptyText }}
            </div>
        </CardContent>
    </Card>
</template>
