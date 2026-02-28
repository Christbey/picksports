<script setup lang="ts">
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import SportPredictions from '@/components/SportPredictions.vue';
import { wnbaPredictionsConfig } from '@/config/predictions-configs';
import { wnbaPredictions } from '@/routes';
</script>

<template>
    <PredictionsPageShell
        title="WNBA Predictions"
        breadcrumb-title="WNBA Predictions"
        :breadcrumb-href="wnbaPredictions().url"
        banner-storage-key="wnba-predictions-banner-dismissed"
    >
        <SportPredictions :config="wnbaPredictionsConfig" />
    </PredictionsPageShell>
</template>
