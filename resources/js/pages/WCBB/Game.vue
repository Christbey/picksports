<script setup lang="ts">
import { computed } from 'vue';
import BasketballGameInsights from '@/components/game-page/BasketballGameInsights.vue';
import BettingPlanCard from '@/components/game-page/BettingPlanCard.vue';
import InjuryReportCard from '@/components/game-page/InjuryReportCard.vue';
import SportDetailedGamePage from '@/components/game-page/SportDetailedGamePage.vue';
import { useBasketballDetailedGamePage } from '@/composables/useBasketballDetailedGamePage';
import WCBBTeamController from '@/actions/App/Http/Controllers/WCBB/TeamController';

const props = defineProps<{
    gameId: number;
}>();

const { pageProps, insightsProps } = useBasketballDetailedGamePage({
    sport: 'wcbb',
    gameId: props.gameId,
    teamLink: (id: number) => WCBBTeamController(id),
    showLinescore: () => false,
});

const bettingPlan = computed(() => {
    const prediction = pageProps.value.prediction;
    const signal = prediction?.value_signal;
    const lean = signal?.best;
    const team =
        lean?.side === 'home'
            ? pageProps.value.homeTeam
            : lean?.side === 'away'
              ? pageProps.value.awayTeam
              : null;
    if (
        signal?.decision_status === 'provisional' &&
        lean?.grade === 'Tracking only' &&
        team &&
        typeof lean.market_line === 'number' &&
        typeof lean.price === 'number' &&
        typeof lean.edge === 'number'
    ) {
        const line = `${lean.market_line > 0 ? '+' : ''}${lean.market_line}`;
        const price = `${lean.price > 0 ? '+' : ''}${lean.price}`;
        return {
            bet_pick: `${team.name} ${line} (${price})`,
            reasoning: `The model differs from the stored spread by ${lean.edge.toFixed(1)} points. This lean is tracked for evaluation.`,
            classification: 'model lean',
        };
    }

    return prediction?.narrative?.betting_plan;
});

const awayInjuries = computed(
    () => pageProps.value.awayTeam?.active_injuries ?? [],
);
const homeInjuries = computed(
    () => pageProps.value.homeTeam?.active_injuries ?? [],
);
</script>

<template>
    <SportDetailedGamePage research-sport="wcbb" v-bind="pageProps">
        <template #afterPrediction>
            <BettingPlanCard :betting-plan="bettingPlan" />
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
