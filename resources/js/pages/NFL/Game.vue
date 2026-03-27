<script setup lang="ts">
import { computed } from 'vue';
import BettingPlanCard from '@/components/game-page/BettingPlanCard.vue';
import DepthChartCard from '@/components/game-page/DepthChartCard.vue';
import DepthChartImpactCard from '@/components/game-page/DepthChartImpactCard.vue';
import InjuryReportCard from '@/components/game-page/InjuryReportCard.vue';
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
</script>

<template>
    <SportDetailedGamePage v-bind="pageProps">
        <template #afterHero>
            <DepthChartCard
                v-if="depthCharts"
                :away-team="depthCharts.away_team"
                :home-team="depthCharts.home_team"
            />
            <NflGameEnhancements v-bind="predictionSectionProps" />
            <InjuryReportCard
                :away-team-abbr="pageProps.awayTeam?.abbreviation"
                :home-team-abbr="pageProps.homeTeam?.abbreviation"
                :away-injuries="awayInjuries"
                :home-injuries="homeInjuries"
            />
        </template>

        <template #afterLinescore>
            <BettingPlanCard
                :betting-plan="pageProps.prediction?.narrative?.betting_plan"
            />
            <DepthChartImpactCard
                :context="pageProps.prediction?.depth_chart_context"
            />
            <NflGameEnhancements v-bind="analysisSectionProps" />
        </template>

        <template #afterTrends>
            <NflGameEnhancements v-bind="recentSectionProps" />
        </template>
    </SportDetailedGamePage>
</template>
