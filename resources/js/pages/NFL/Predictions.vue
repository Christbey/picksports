<script setup lang="ts">
import NflMatchupBoard from '@/components/nfl/NflMatchupBoard.vue';
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import { nflPredictions } from '@/routes';
</script>

<template>
    <PredictionsPageShell
        title="NFL Predictions"
        sport-title="NFL"
        :sport-href="nflPredictions().url"
        page-title="Board"
        banner-storage-key="nfl-predictions-banner-dismissed"
        seo-description="NFL predictions, spreads, totals, and confidence picks powered by PickSports analytics."
    >
        <NflMatchupBoard />
    </PredictionsPageShell>
</template>
