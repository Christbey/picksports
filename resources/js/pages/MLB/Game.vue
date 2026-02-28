<script setup lang="ts">
import MlbGameInsights from '@/components/game-page/MlbGameInsights.vue'
import SportDetailedGamePage from '@/components/game-page/SportDetailedGamePage.vue'
import { useMlbDetailedGamePage } from '@/composables/useMlbDetailedGamePage'
import { type MlbPageGame } from '@/types'

const props = defineProps<{
    game: MlbPageGame
}>()

const { pageProps, recentSectionProps } = useMlbDetailedGamePage(props.game)
</script>

<template>
    <SportDetailedGamePage v-bind="pageProps">
        <template #afterTrends>
            <MlbGameInsights v-bind="recentSectionProps" />
        </template>
    </SportDetailedGamePage>
</template>
