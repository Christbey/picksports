<script setup lang="ts">
import { computed } from 'vue';
import CBBTeamController from '@/actions/App/Http/Controllers/CBB/TeamController';
import BasketballGameInsights from '@/components/game-page/BasketballGameInsights.vue';
import BettingPlanCard from '@/components/game-page/BettingPlanCard.vue';
import InjuryReportCard from '@/components/game-page/InjuryReportCard.vue';
import LiveBettingAnalysisCard from '@/components/game-page/LiveBettingAnalysisCard.vue';
import SportDetailedGamePage from '@/components/game-page/SportDetailedGamePage.vue';
import { useBasketballDetailedGamePage } from '@/composables/useBasketballDetailedGamePage';
import { type TopPerformer } from '@/types';

const props = defineProps<{
    gameId: number;
}>();

const { pageProps, insightsProps } = useBasketballDetailedGamePage({
    sport: 'cbb',
    gameId: props.gameId,
    sortTopPerformers: (players: TopPerformer[]) =>
        players
            .sort((a, b) => (b.points || 0) - (a.points || 0))
            .slice(0, 10),
    teamLink: (id: number) => CBBTeamController(id),
    subtitleText: (sampleSize) => `Based on last ${sampleSize} games before this matchup`,
    venueLabel: (game) => game.venue || null,
    showLinescore: (game, homeLinescores, awayLinescores) =>
        game.status === 'STATUS_FINAL'
        && (homeLinescores.length > 0 || awayLinescores.length > 0),
});

const awayInjuries = computed(() => pageProps.value.awayTeam?.active_injuries ?? []);
const homeInjuries = computed(() => pageProps.value.homeTeam?.active_injuries ?? []);
</script>

<template>
    <SportDetailedGamePage v-bind="pageProps">
        <template #beforePrediction>
            <BasketballGameInsights
                v-bind="insightsProps"
                box-score-layout="table"
                :show-metrics="false"
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
            <BasketballGameInsights
                v-bind="insightsProps"
                :show-recap="false"
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
