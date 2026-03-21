<script setup lang="ts">
import BettingPlanCard from '@/components/game-page/BettingPlanCard.vue';
import NflGameEnhancements from '@/components/game-page/NflGameEnhancements.vue';
import SportDetailedGamePage from '@/components/game-page/SportDetailedGamePage.vue';
import { useCfbDetailedGamePage } from '@/composables/useCfbDetailedGamePage';

const props = defineProps<{
    gameId: number;
}>();

const {
    pageProps,
    predictionSectionProps,
    analysisSectionProps,
    recentSectionProps,
} = useCfbDetailedGamePage(props.gameId);
</script>

<template>
    <SportDetailedGamePage v-bind="pageProps">
        <template #afterHero>
            <NflGameEnhancements v-bind="predictionSectionProps" />
        </template>

        <template #afterLinescore>
            <BettingPlanCard
                :betting-plan="pageProps.prediction?.narrative?.betting_plan"
            />
            <NflGameEnhancements v-bind="analysisSectionProps" />
        </template>

        <template #afterTrends>
            <NflGameEnhancements v-bind="recentSectionProps" />
        </template>
    </SportDetailedGamePage>
</template>
