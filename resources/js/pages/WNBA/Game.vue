<script setup lang="ts">
import { computed } from 'vue';
import WNBATeamController from '@/actions/App/Http/Controllers/WNBA/TeamController';
import BasketballGameInsights from '@/components/game-page/BasketballGameInsights.vue';
import BettingPlanCard from '@/components/game-page/BettingPlanCard.vue';
import InjuryReportCard from '@/components/game-page/InjuryReportCard.vue';
import SportDetailedGamePage from '@/components/game-page/SportDetailedGamePage.vue';
import { useBasketballDetailedGamePage } from '@/composables/useBasketballDetailedGamePage';

const props = defineProps<{
    gameId: number;
}>();

const { pageProps, insightsProps } = useBasketballDetailedGamePage({
    sport: 'wnba',
    gameId: props.gameId,
    teamLink: (id: number) => WNBATeamController(id),
    showLinescore: () => false,
});

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
