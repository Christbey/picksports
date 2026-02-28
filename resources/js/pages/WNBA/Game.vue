<script setup lang="ts">
import WNBATeamController from '@/actions/App/Http/Controllers/WNBA/TeamController';
import BasketballGameInsights from '@/components/game-page/BasketballGameInsights.vue';
import SportDetailedGamePage from '@/components/game-page/SportDetailedGamePage.vue';
import { useBasketballDetailedGamePage } from '@/composables/useBasketballDetailedGamePage';
import { type Game } from '@/types';

const props = defineProps<{
    game: Game;
}>();

const { pageProps, insightsProps } = useBasketballDetailedGamePage({
    sport: 'wnba',
    game: props.game,
    teamLink: (id: number) => WNBATeamController(id),
    showTrends: false,
    showLinescore: () => false,
});
</script>

<template>
    <SportDetailedGamePage v-bind="pageProps">
        <template #afterPrediction>
            <BasketballGameInsights v-bind="insightsProps" :show-recap="false" />
        </template>
    </SportDetailedGamePage>
</template>
