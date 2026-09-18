<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import BettingPlanCard from '@/components/game-page/BettingPlanCard.vue';
import DepthChartCard from '@/components/game-page/DepthChartCard.vue';
import InjuryReportCard from '@/components/game-page/InjuryReportCard.vue';
import NflResearchBrief from '@/components/game-page/NflResearchBrief.vue';
import NflGameEnhancements from '@/components/game-page/NflGameEnhancements.vue';
import SportDetailedGamePage from '@/components/game-page/SportDetailedGamePage.vue';
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
const mobileSection = ref('overview');
const mobileContent = ref<HTMLElement | null>(null);
const selectSection = async (section: string) => {
    mobileSection.value = section;
    await nextTick();
    mobileContent.value?.scrollIntoView({ block: 'start' });
};
const mobileSections = {
    overview: 'Overview',
    research: 'Research',
    trends: 'Trends',
    roster: 'Roster',
};
// Keep panels mounted: navigation must not restart polling or fetch research again.
const sectionClass = (section: string) =>
    mobileSection.value === section
        ? 'min-w-0 space-y-4'
        : 'hidden min-w-0 space-y-4 md:block';
</script>

<template>
    <SportDetailedGamePage
        v-bind="pageProps"
        compact-matchup
        :linescore-class="sectionClass('overview')"
        :trends-class="sectionClass('trends')"
    >
        <template #afterHero>
            <nav
                aria-label="Game sections"
                class="fixed inset-x-3 bottom-[max(0.75rem,env(safe-area-inset-bottom))] z-40 grid grid-cols-4 gap-1 rounded-xl border bg-background/95 p-1 shadow-lg backdrop-blur md:hidden"
            >
                <button
                    v-for="(label, key) in mobileSections"
                    :key="key"
                    type="button"
                    class="min-h-11 rounded-lg px-1 py-2 text-sm font-medium"
                    :class="
                        mobileSection === key
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground'
                    "
                    :aria-pressed="mobileSection === key"
                    @click="selectSection(key)"
                >
                    {{ label }}
                </button>
            </nav>
            <div ref="mobileContent" class="scroll-mt-36 md:hidden" />
            <div :class="sectionClass('overview')">
                <NflGameEnhancements v-bind="predictionSectionProps" />
                <button
                    type="button"
                    class="min-h-11 w-full rounded-lg border px-4 py-3 text-left text-sm font-medium md:hidden"
                    @click="selectSection('roster')"
                >
                    View injuries, availability &amp; depth charts →
                </button>
            </div>
            <div :class="sectionClass('roster')">
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
                :class="
                    mobileSection === 'overview' || mobileSection === 'research'
                        ? 'min-w-0 space-y-3'
                        : 'hidden min-w-0 space-y-3 md:block'
                "
            >
                <NflResearchBrief
                    :game-id="gameId"
                    :mobile-compact="mobileSection === 'overview'"
                />
                <button
                    v-if="mobileSection === 'overview'"
                    type="button"
                    class="min-h-11 w-full rounded-lg border px-4 py-3 text-left text-sm font-medium md:hidden"
                    @click="selectSection('research')"
                >
                    Read evidence &amp; counterarguments →
                </button>
            </div>
            <div :class="sectionClass('overview')">
                <BettingPlanCard
                    v-if="
                        predictionSectionProps.prediction?.narrative
                            ?.betting_plan
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
                <NflGameEnhancements v-bind="recentSectionProps" />
            </div>
            <div aria-hidden="true" class="h-24 shrink-0 md:hidden" />
        </template>
    </SportDetailedGamePage>
</template>
