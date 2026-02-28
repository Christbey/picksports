<script setup lang="ts">
import NflGameEnhancements from '@/components/game-page/NflGameEnhancements.vue'
import SportDetailedGamePage from '@/components/game-page/SportDetailedGamePage.vue'
import { useNflDetailedGamePage } from '@/composables/useNflDetailedGamePage'
import { type NflPageGame } from '@/types'

const props = defineProps<{
  game: NflPageGame
}>()

const {
  pageProps,
  predictionSectionProps,
  analysisSectionProps,
  recentSectionProps,
} = useNflDetailedGamePage(props.game)
</script>

<template>
  <SportDetailedGamePage v-bind="pageProps">
    <template #afterHero>
      <NflGameEnhancements v-bind="predictionSectionProps" />
    </template>

    <template #afterLinescore>
      <NflGameEnhancements v-bind="analysisSectionProps" />
    </template>

    <template #afterTrends>
      <NflGameEnhancements v-bind="recentSectionProps" />
    </template>
  </SportDetailedGamePage>
</template>
