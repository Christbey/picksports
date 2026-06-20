<script setup lang="ts">
import MlbDailyPicksPanel from '@/components/mlb/MlbDailyPicksPanel.vue';
import MlbSignalsPanel from '@/components/mlb/MlbSignalsPanel.vue';
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import SportPredictions from '@/components/SportPredictions.vue';
import { mlbPredictionsConfig } from '@/config/predictions-configs';
import { mlbPredictions } from '@/routes';
</script>

<template>
    <PredictionsPageShell
        title="MLB Predictions"
        breadcrumb-title="MLB Predictions"
        :breadcrumb-href="mlbPredictions().url"
        banner-storage-key="mlb-predictions-banner-dismissed"
        seo-description="MLB predictions and betting edges across moneyline, run line, and totals using PickSports models."
    >
        <MlbDailyPicksPanel class="mb-6" />
        <MlbSignalsPanel class="mb-6" />
        <SportPredictions :config="mlbPredictionsConfig" />
    </PredictionsPageShell>
</template>
