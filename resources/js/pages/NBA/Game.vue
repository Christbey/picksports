<script setup lang="ts">
import { computed } from 'vue';
import BasketballGameInsights from '@/components/game-page/BasketballGameInsights.vue';
import BettingPlanCard from '@/components/game-page/BettingPlanCard.vue';
import DepthChartCard from '@/components/game-page/DepthChartCard.vue';
import DepthChartImpactCard from '@/components/game-page/DepthChartImpactCard.vue';
import InjuryReportCard from '@/components/game-page/InjuryReportCard.vue';
import LiveBettingAnalysisCard from '@/components/game-page/LiveBettingAnalysisCard.vue';
import SportDetailedGamePage from '@/components/game-page/SportDetailedGamePage.vue';
import { useGameDepthCharts } from '@/composables/useGameDepthCharts';
import { useBasketballDetailedGamePage } from '@/composables/useBasketballDetailedGamePage';
import { type TopPerformer } from '@/types';
import NBATeamController from '@/actions/App/Http/Controllers/NBA/TeamController';

const props = defineProps<{
    gameId: number;
}>();

const { pageProps, insightsProps } = useBasketballDetailedGamePage({
    sport: 'nba',
    gameId: props.gameId,
    sortTopPerformers: (players: TopPerformer[]) => players.slice(0, 10),
    teamLink: (id: number) => NBATeamController(id),
});
const { depthCharts } = useGameDepthCharts('nba', props.gameId);

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
        </template>
        <template #afterPrediction>
            <LiveBettingAnalysisCard
                :has-live-prediction="false"
                :betting-value="pageProps.prediction?.betting_value"
                :winner-correct="pageProps.prediction?.winner_correct ?? null"
                :actual-total="pageProps.prediction?.actual_total ?? null"
                sportsbook-label="Vegas"
            />
            <BettingPlanCard
                :betting-plan="pageProps.prediction?.narrative?.betting_plan"
            />
            <DepthChartImpactCard
                :context="pageProps.prediction?.depth_chart_context"
            />
            <BasketballGameInsights
                v-bind="insightsProps"
                box-score-layout="grid"
            />
            <InjuryReportCard
                :away-team-abbr="pageProps.awayTeam?.abbreviation"
                :home-team-abbr="pageProps.homeTeam?.abbreviation"
                :away-injuries="awayInjuries"
                :home-injuries="homeInjuries"
            />
        </template>
    </SportDetailedGamePage>
</template>
