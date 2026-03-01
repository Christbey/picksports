<script setup lang="ts">
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import SportPredictions from '@/components/SportPredictions.vue';
import { cfbPredictionsConfig } from '@/config/predictions-configs';
import { cfbPredictions } from '@/routes';
</script>

<template>
    <PredictionsPageShell
        title="CFB Predictions"
        breadcrumb-title="CFB Predictions"
        :breadcrumb-href="cfbPredictions().url"
        banner-storage-key="cfb-predictions-banner-dismissed"
        seo-description="College football predictions with model confidence, spread projections, and betting value analysis."
    >
        <SportPredictions :config="cfbPredictionsConfig" />
    </PredictionsPageShell>
</template>
