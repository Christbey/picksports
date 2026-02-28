<script setup lang="ts">
import CBBTeamController from '@/actions/App/Http/Controllers/CBB/TeamController';
import BasketballGameInsights from '@/components/game-page/BasketballGameInsights.vue';
import SportDetailedGamePage from '@/components/game-page/SportDetailedGamePage.vue';
import { useBasketballDetailedGamePage } from '@/composables/useBasketballDetailedGamePage';
import { type Game, type TopPerformer } from '@/types';

const props = defineProps<{
    game: Game;
}>();

const { pageProps, insightsProps } = useBasketballDetailedGamePage({
    sport: 'cbb',
    game: props.game,
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
            <BasketballGameInsights
                v-bind="insightsProps"
                :show-recap="false"
            />
        </template>
    </SportDetailedGamePage>
</template>
