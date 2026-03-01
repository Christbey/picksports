<script setup lang="ts">
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import SportPredictions from '@/components/SportPredictions.vue';
import { cbbPredictionsConfig } from '@/config/predictions-configs';
import { cbbPredictions } from '@/routes';
</script>

<template>
    <PredictionsPageShell
        title="CBB Predictions"
        breadcrumb-title="CBB Predictions"
        :breadcrumb-href="cbbPredictions().url"
        banner-storage-key="cbb-predictions-banner-dismissed"
        seo-description="College basketball predictions and model-driven picks for spreads, totals, and value edges."
    >
        <SportPredictions :config="cbbPredictionsConfig" />
    </PredictionsPageShell>
</template>
