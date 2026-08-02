<script setup lang="ts">
import { computed } from 'vue';
import BettingPlanCard from '@/components/game-page/BettingPlanCard.vue';
import DepthChartCard from '@/components/game-page/DepthChartCard.vue';
import InjuryReportCard from '@/components/game-page/InjuryReportCard.vue';
import MlbGameInsights from '@/components/game-page/MlbGameInsights.vue';
import ProbableStartersCard from '@/components/game-page/ProbableStartersCard.vue';
import SportDetailedGamePage from '@/components/game-page/SportDetailedGamePage.vue';
import { useMlbDetailedGamePage } from '@/composables/useMlbDetailedGamePage';

const props = defineProps<{
    gameId: number;
}>();

const { pageProps, recentSectionProps, depthCharts } = useMlbDetailedGamePage(
    props.gameId,
);
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
        <template #afterTrends>
            <MlbGameInsights v-bind="recentSectionProps" />
        </template>
    </SportDetailedGamePage>
</template>
