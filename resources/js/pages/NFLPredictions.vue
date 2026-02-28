<script setup lang="ts">
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import SportPredictions from '@/components/SportPredictions.vue';
import { nflPredictionsConfig } from '@/config/predictions-configs';
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
        <SportPredictions :config="nflPredictionsConfig" />
    </PredictionsPageShell>
</template>
