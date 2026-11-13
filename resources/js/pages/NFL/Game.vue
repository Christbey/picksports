<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Activity, ClipboardList, Shield, TrendingUp } from 'lucide-vue-next';
import BettingPlanCard from '@/components/game-page/BettingPlanCard.vue';
import DepthChartCard from '@/components/game-page/DepthChartCard.vue';
import InjuryReportCard from '@/components/game-page/InjuryReportCard.vue';
import NflResearchBrief from '@/components/game-page/NflResearchBrief.vue';
import NflMatchupSignals from '@/components/game-page/NflMatchupSignals.vue';
import NflMarketHistory from '@/components/game-page/NflMarketHistory.vue';
import NflGameEnhancements from '@/components/game-page/NflGameEnhancements.vue';
import SportDetailedGamePage from '@/components/game-page/SportDetailedGamePage.vue';
import { useViewerAccess } from '@/composables/useViewerAccess';
import { useNflDetailedGamePage } from '@/composables/useNflDetailedGamePage';

const props = defineProps<{
    gameId: number;
}>();

const {
    pageProps,
    predictionSectionProps,
    analysisSectionProps,
    recentSectionProps,
    depthCharts,
} = useNflDetailedGamePage(props.gameId);

const awayInjuries = computed(
    () => pageProps.value.awayTeam?.active_injuries ?? [],
);
const homeInjuries = computed(
    () => pageProps.value.homeTeam?.active_injuries ?? [],
);
const { isAdmin } = useViewerAccess();
const mobileSection = ref('overview');
watch(isAdmin, (allowed) => {
    if (!allowed && mobileSection.value === 'research')
        mobileSection.value = 'overview';
});
const selectSection = (section: string) => {
    if (section === 'research' && !isAdmin.value) return;
    mobileSection.value = section;
};
const mobileSections = computed<
    Record<string, { label: string; icon: typeof Activity }>
>(() => ({
    overview: { label: 'Overview', icon: Activity },
    trends: { label: 'Trends', icon: TrendingUp },
    roster: { label: 'Roster', icon: Shield },
    ...(isAdmin.value
        ? { research: { label: 'Research', icon: ClipboardList } }
        : {}),
}));
// Keep panels mounted: navigation must not restart polling or fetch research again.
const sectionClass = (section: string) =>
    mobileSection.value === section
        ? 'min-w-0 space-y-4'
        : 'hidden min-w-0 space-y-4';
</script>

<template>
    <SportDetailedGamePage
        v-bind="pageProps"
        compact-matchup
        trends-workspace
        :linescore-class="sectionClass('overview')"
        :trends-class="sectionClass('trends')"
    >
        <template #afterHero>
            <nav
                aria-label="Game sections"
                class="grid grid-cols-2 gap-2 rounded-2xl border border-border/70 bg-card p-2 sm:flex"
            >
                <Button
                    v-for="(section, key) in mobileSections"
                    :key="key"
                    type="button"
                    class="min-h-11 min-w-0 flex-1 justify-start px-3 sm:justify-center"
                    :variant="mobileSection === key ? 'default' : 'ghost'"
                    :aria-pressed="mobileSection === key"
                    @click="selectSection(key)"
                >
                    <component :is="section.icon" aria-hidden="true" />
                    {{ section.label }}
                </Button>
            </nav>
            <p class="text-xs text-muted-foreground">
                <span
                    v-if="
                        analysisSectionProps.livePredictionData?.sourceUpdatedAt
                    "
                >
                    Updated
                    {{
                        new Date(
                            analysisSectionProps.livePredictionData
                                .sourceUpdatedAt,
                        ).toLocaleString()
                    }}</span
                >
                <span v-else>Latest saved game information</span>
            </p>
            <div :class="sectionClass('overview')">
                <NflGameEnhancements v-bind="predictionSectionProps" />
                <Button
                    type="button"
                    variant="outline"
                    class="h-auto min-h-11 w-full justify-start whitespace-normal md:hidden"
                    @click="selectSection('roster')"
                >
                    View injuries, availability &amp; depth charts →
                </Button>
            </div>
            <div :class="sectionClass('roster')">
                <p
                    v-if="pageProps.game.status === 'STATUS_FINAL'"
                    class="text-sm text-muted-foreground"
                >
                    Current availability report—not a preserved kickoff roster.
                </p>
                <InjuryReportCard
                    :away-team-abbr="pageProps.awayTeam?.abbreviation"
                    :home-team-abbr="pageProps.homeTeam?.abbreviation"
                    :away-injuries="awayInjuries"
                    :home-injuries="homeInjuries"
                    :away-injuries-available="
                        Array.isArray(pageProps.awayTeam?.active_injuries)
                    "
                    :home-injuries-available="
                        Array.isArray(pageProps.homeTeam?.active_injuries)
                    "
                    :depth-chart-context="
                        predictionSectionProps.prediction?.depth_chart_context
                    "
                />
                <details
                    v-if="depthCharts"
                    class="group rounded-xl border bg-card p-4"
                >
                    <summary
                        class="min-h-11 cursor-pointer content-center font-semibold"
                    >
                        Depth charts &amp; starters
                    </summary>
                    <DepthChartCard
                        class="mt-3"
                        :away-team="depthCharts.away_team"
                        :home-team="depthCharts.home_team"
                    />
                </details>
            </div>
        </template>

        <template #afterLinescore>
            <div
                v-if="isAdmin"
                :class="
                    mobileSection === 'research'
                        ? 'min-w-0 space-y-3'
                        : 'hidden min-w-0 space-y-3'
                "
            >
                <NflResearchBrief
                    :game-id="gameId"
                    :game-status="pageProps.game.status"
                />
            </div>
            <div :class="sectionClass('overview')">
                <BettingPlanCard
                    v-if="
                        predictionSectionProps.prediction?.narrative
                            ?.betting_plan &&
                        pageProps.game.status !== 'STATUS_FINAL'
                    "
                    :betting-plan="
                        predictionSectionProps.prediction.narrative.betting_plan
                    "
                />
                <NflGameEnhancements v-bind="analysisSectionProps" />
            </div>
        </template>

        <template #afterTrends>
            <div :class="sectionClass('trends')">
                <NflMarketHistory :game-id="gameId" />
                <NflMatchupSignals :game-id="gameId" />
                <p class="text-sm text-muted-foreground">
                    Recent games: up to five completed games from the same
                    season type, before this matchup’s kickoff. W–L–T excludes
                    ungraded scores.
                </p>
                <NflGameEnhancements v-bind="recentSectionProps" />
            </div>
        </template>
    </SportDetailedGamePage>
</template>
