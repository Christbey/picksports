<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Skeleton } from '@/components/ui/skeleton';
import { provide } from 'vue';
import { useMlbFutures, mlbFuturesKey } from '@/composables/useMlbFutures';
import MlbFuturesFilters from '@/components/predictions/MlbFuturesFilters.vue';
import MlbFuturesSummary from '@/components/predictions/MlbFuturesSummary.vue';
import MlbFuturesBreakdown from '@/components/predictions/MlbFuturesBreakdown.vue';
import MlbFuturesForecasts from '@/components/predictions/MlbFuturesForecasts.vue';
import MlbFuturesTitleOdds from '@/components/predictions/MlbFuturesTitleOdds.vue';
const state = useMlbFutures();
provide(mlbFuturesKey, state);
const { loading, error } = state;
</script>
<template>
    <Head title="MLB Futures" />

    <PredictionsPageShell
        title="MLB Futures"
        sport-title="MLB"
        sport-href="/mlb/predictions"
        page-title="Futures"
        banner-storage-key="mlb-futures-banner-dismissed"
        seo-description="MLB postseason forecast with playoff odds, LCS probability, World Series probability, and championship futures."
    >
        <div class="space-y-5">
            <MlbFuturesFilters />

            <Alert v-if="error" variant="destructive">
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>

            <div v-if="loading" class="space-y-3">
                <Skeleton v-for="i in 8" :key="i" class="h-12 w-full" />
            </div>

            <template v-else>
                <MlbFuturesSummary />

                <MlbFuturesBreakdown />

                <MlbFuturesForecasts />

                <MlbFuturesTitleOdds />
            </template>
        </div>
    </PredictionsPageShell>
</template>
