<script setup lang="ts">
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import SportPredictions from '@/components/SportPredictions.vue';
import { wcbbPredictionsConfig } from '@/config/predictions-configs';
import { wcbbPredictions } from '@/routes';
</script>

<template>
    <PredictionsPageShell
        title="WCBB Predictions"
        breadcrumb-title="WCBB Predictions"
        :breadcrumb-href="wcbbPredictions().url"
        banner-storage-key="wcbb-predictions-banner-dismissed"
        seo-description="Women's college basketball predictions and confidence-based picks from PickSports analytics."
    >
        <SportPredictions :config="wcbbPredictionsConfig" />
    </PredictionsPageShell>
</template>
