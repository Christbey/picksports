<script setup lang="ts">
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import SportPredictions from '@/components/SportPredictions.vue';
import { nbaPredictionsConfig } from '@/config/predictions-configs';
import { nbaPredictions } from '@/routes';
</script>

<template>
    <PredictionsPageShell
        title="NBA Predictions"
        breadcrumb-title="NBA Predictions"
        :breadcrumb-href="nbaPredictions().url"
        banner-storage-key="nba-predictions-banner-dismissed"
    >
        <SportPredictions :config="nbaPredictionsConfig" />
    </PredictionsPageShell>
</template>
