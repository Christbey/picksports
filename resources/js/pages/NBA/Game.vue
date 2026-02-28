<script setup lang="ts">
import NBATeamController from '@/actions/App/Http/Controllers/NBA/TeamController';
import BasketballGameInsights from '@/components/game-page/BasketballGameInsights.vue';
import SportDetailedGamePage from '@/components/game-page/SportDetailedGamePage.vue';
import { useBasketballDetailedGamePage } from '@/composables/useBasketballDetailedGamePage';
import { type Game, type TopPerformer } from '@/types';

const props = defineProps<{
    game: Game;
}>();

const { pageProps, insightsProps } = useBasketballDetailedGamePage({
    sport: 'nba',
    game: props.game,
    sortTopPerformers: (players: TopPerformer[]) => players.slice(0, 10),
    teamLink: (id: number) => NBATeamController(id),
});
</script>

<template>
    <SportDetailedGamePage v-bind="pageProps">
        <template #afterPrediction>
            <BasketballGameInsights
                v-bind="insightsProps"
                box-score-layout="grid"
            />
        </template>
    </SportDetailedGamePage>
</template>
