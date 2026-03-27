<script setup lang="ts">
import { computed } from 'vue';
import BettingPlanCard from '@/components/game-page/BettingPlanCard.vue';
import DepthChartCard from '@/components/game-page/DepthChartCard.vue';
import DepthChartImpactCard from '@/components/game-page/DepthChartImpactCard.vue';
import InjuryReportCard from '@/components/game-page/InjuryReportCard.vue';
import MlbGameInsights from '@/components/game-page/MlbGameInsights.vue';
import SportDetailedGamePage from '@/components/game-page/SportDetailedGamePage.vue';
import { useMlbDetailedGamePage } from '@/composables/useMlbDetailedGamePage';

const props = defineProps<{
    gameId: number;
}>();

const { pageProps, recentSectionProps, depthCharts } = useMlbDetailedGamePage(props.gameId);
const awayInjuries = computed(
    () => pageProps.value.awayTeam?.active_injuries ?? [],
);
const homeInjuries = computed(
    () => pageProps.value.homeTeam?.active_injuries ?? [],
);
</script>

<template>
    <SportDetailedGamePage v-bind="pageProps">
        <template #afterPrediction>
            <BettingPlanCard
                :betting-plan="pageProps.prediction?.narrative?.betting_plan"
            />
            <DepthChartImpactCard
                :context="pageProps.prediction?.depth_chart_context"
            />
        </template>
        <template #afterHero>
            <DepthChartCard
                v-if="depthCharts"
                :away-team="depthCharts.away_team"
                :home-team="depthCharts.home_team"
            />
            <InjuryReportCard
                :away-team-abbr="pageProps.awayTeam?.abbreviation"
                :home-team-abbr="pageProps.homeTeam?.abbreviation"
                :away-injuries="awayInjuries"
                :home-injuries="homeInjuries"
            />
        </template>
        <template #afterTrends>
            <MlbGameInsights v-bind="recentSectionProps" />
        </template>
    </SportDetailedGamePage>
</template>
