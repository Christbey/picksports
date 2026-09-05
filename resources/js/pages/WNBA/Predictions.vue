<script setup lang="ts">
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import SportPredictions from '@/components/SportPredictions.vue';
import { wnbaPredictionsConfig } from '@/config/predictions-configs';
import { wnbaPredictions } from '@/routes';
</script>

<template>
    <PredictionsPageShell
        title="WNBA Predictions"
        sport-title="WNBA"
        :sport-href="wnbaPredictions().url"
        page-title="Board"
        banner-storage-key="wnba-predictions-banner-dismissed"
        seo-description="WNBA predictions and betting insights with spread, total, and confidence model outputs."
    >
        <SportPredictions :config="wnbaPredictionsConfig" />
    </PredictionsPageShell>
</template>
