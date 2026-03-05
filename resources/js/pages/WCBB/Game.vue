<script setup lang="ts">
import { computed } from 'vue';
import WCBBTeamController from '@/actions/App/Http/Controllers/WCBB/TeamController';
import BasketballGameInsights from '@/components/game-page/BasketballGameInsights.vue';
import InjuryReportCard from '@/components/game-page/InjuryReportCard.vue';
import SportDetailedGamePage from '@/components/game-page/SportDetailedGamePage.vue';
import { useBasketballDetailedGamePage } from '@/composables/useBasketballDetailedGamePage';
import { type Game } from '@/types';

const props = defineProps<{
    game: Game;
}>();

const { pageProps, insightsProps } = useBasketballDetailedGamePage({
    sport: 'wcbb',
    game: props.game,
    teamLink: (id: number) => WCBBTeamController(id),
    showTrends: false,
    showLinescore: () => false,
});

const awayInjuries = computed(() => pageProps.value.awayTeam?.active_injuries ?? []);
const homeInjuries = computed(() => pageProps.value.homeTeam?.active_injuries ?? []);
</script>

<template>
    <SportDetailedGamePage v-bind="pageProps">
        <template #afterPrediction>
            <BasketballGameInsights v-bind="insightsProps" :show-recap="false" />
            <InjuryReportCard
                :away-team-abbr="pageProps.awayTeam?.abbreviation"
                :home-team-abbr="pageProps.homeTeam?.abbreviation"
                :away-injuries="awayInjuries"
                :home-injuries="homeInjuries"
            />
        </template>
    </SportDetailedGamePage>
</template>
