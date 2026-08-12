<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import BettingPlanCard from '@/components/game-page/BettingPlanCard.vue';
import DepthChartCard from '@/components/game-page/DepthChartCard.vue';
import InjuryReportCard from '@/components/game-page/InjuryReportCard.vue';
import MlbGameInsights from '@/components/game-page/MlbGameInsights.vue';
import ProbableStartersCard from '@/components/game-page/ProbableStartersCard.vue';
import SportDetailedGamePage from '@/components/game-page/SportDetailedGamePage.vue';
import { useGameDepthCharts } from '@/composables/useGameDepthCharts';
import { useMlbDetailedGamePage } from '@/composables/useMlbDetailedGamePage';

const props = defineProps<{
    gameId: number;
}>();

const { pageProps, recentSectionProps, depthChartsAvailable, loadTrends } =
    useMlbDetailedGamePage(props.gameId);
const { depthCharts, reloadDepthCharts } = useGameDepthCharts(
    'mlb',
    props.gameId,
    { autoLoad: false },
);
const depthChartTrigger = ref<HTMLElement | null>(null);
const trendsTrigger = ref<HTMLElement | null>(null);
let depthChartObserver: IntersectionObserver | null = null;
let trendsObserver: IntersectionObserver | null = null;
const awayInjuries = computed(
    () => pageProps.value.awayTeam?.active_injuries ?? [],
);
const homeInjuries = computed(
    () => pageProps.value.homeTeam?.active_injuries ?? [],
);
const hasDepthChartEntries = computed(() =>
    Boolean(
        depthCharts.value &&
        ((depthCharts.value.away_team?.entries.length ?? 0) > 0 ||
            (depthCharts.value.home_team?.entries.length ?? 0) > 0),
    ),
);

const observeOnce = (
    element: HTMLElement,
    load: () => Promise<void>,
    rootMargin: string,
): IntersectionObserver | null => {
    if (typeof IntersectionObserver === 'undefined') {
        void load();

        return null;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            if (!entries.some((entry) => entry.isIntersecting)) return;
            observer.disconnect();
            void load();
        },
        { rootMargin },
    );
    observer.observe(element);

    return observer;
};

watch(
    [depthChartTrigger, depthChartsAvailable],
    ([element, available]) => {
        depthChartObserver?.disconnect();
        depthChartObserver = null;
        if (element && available && !depthCharts.value) {
            depthChartObserver = observeOnce(
                element,
                reloadDepthCharts,
                '300px 0px',
            );
        }
    },
    { flush: 'post' },
);

watch(
    trendsTrigger,
    (element) => {
        trendsObserver?.disconnect();
        trendsObserver = element
            ? observeOnce(element, loadTrends, '600px 0px')
            : null;
    },
    { flush: 'post' },
);

onBeforeUnmount(() => {
    depthChartObserver?.disconnect();
    trendsObserver?.disconnect();
});
</script>

<template>
    <SportDetailedGamePage v-bind="pageProps">
        <template #afterPrediction>
            <BettingPlanCard
                :prediction="pageProps.prediction"
                :betting-plan="pageProps.prediction?.narrative?.betting_plan"
            />
        </template>
        <template #afterHero>
            <div
                ref="depthChartTrigger"
                class="grid gap-4 xl:grid-cols-[minmax(0,1.25fr)_minmax(320px,0.75fr)]"
            >
                <DepthChartCard
                    v-if="hasDepthChartEntries && depthCharts"
                    :away-team="depthCharts.away_team"
                    :home-team="depthCharts.home_team"
                />
                <ProbableStartersCard
                    v-else
                    :away-team="pageProps.awayTeam"
                    :home-team="pageProps.homeTeam"
                    :away-starter-name="pageProps.awayStarterName"
                    :home-starter-name="pageProps.homeStarterName"
                    :away-starter-rating="pageProps.awayStarterRating"
                    :home-starter-rating="pageProps.homeStarterRating"
                    :away-starter-source="pageProps.awayStarterSource"
                    :home-starter-source="pageProps.homeStarterSource"
                    :away-starter-confidence="pageProps.awayStarterConfidence"
                    :home-starter-confidence="pageProps.homeStarterConfidence"
                    :away-starter-forecast="pageProps.awayStarterForecast"
                    :home-starter-forecast="pageProps.homeStarterForecast"
                />
                <InjuryReportCard
                    :away-team-abbr="pageProps.awayTeam?.abbreviation"
                    :home-team-abbr="pageProps.homeTeam?.abbreviation"
                    :away-injuries="awayInjuries"
                    :home-injuries="homeInjuries"
                    :depth-chart-context="
                        pageProps.prediction?.depth_chart_context
                    "
                />
            </div>
        </template>
        <template #beforeTrends>
            <div ref="trendsTrigger" class="h-px" aria-hidden="true" />
        </template>
        <template #afterTrends>
            <MlbGameInsights v-bind="recentSectionProps" />
        </template>
    </SportDetailedGamePage>
</template>
