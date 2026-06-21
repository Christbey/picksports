<script setup lang="ts">
import MlbDailyPicksPanel from '@/components/mlb/MlbDailyPicksPanel.vue';
import MlbMatchupBoard from '@/components/mlb/MlbMatchupBoard.vue';
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import { mlbPredictions } from '@/routes';
</script>

<template>
    <PredictionsPageShell
        title="MLB Daily Board"
        breadcrumb-title="MLB Daily Board"
        :breadcrumb-href="mlbPredictions().url"
        banner-storage-key="mlb-predictions-banner-dismissed"
        seo-description="Market-aware MLB daily board with tracking candidates, model context, and slate intelligence."
    >
        <MlbDailyPicksPanel class="mb-6" />
        <MlbMatchupBoard />
    </PredictionsPageShell>
</template>
