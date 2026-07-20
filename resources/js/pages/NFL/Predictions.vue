<script setup lang="ts">
import NflMatchupBoard from '@/components/nfl/NflMatchupBoard.vue';
import NflSignalsPanel from '@/components/nfl/NflSignalsPanel.vue';
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import { nflPredictions } from '@/routes';
</script>

<template>
    <PredictionsPageShell
        title="NFL Predictions"
        breadcrumb-title="NFL Predictions"
        :breadcrumb-href="nflPredictions().url"
        banner-storage-key="nfl-predictions-banner-dismissed"
        seo-description="NFL predictions, spreads, totals, and confidence picks powered by PickSports analytics."
    >
        <NflSignalsPanel class="mb-6" />
        <NflMatchupBoard />
    </PredictionsPageShell>
</template>
