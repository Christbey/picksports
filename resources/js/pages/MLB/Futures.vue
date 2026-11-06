<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import DataRecovery from '@/components/DataRecovery.vue';
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
const { loading, error, forecasts, fetchForecasts } = state;
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

            <DataRecovery
                v-if="error"
                :message="error"
                :busy="loading"
                @retry="fetchForecasts"
            />

            <div
                v-if="loading"
                role="status"
                aria-label="Loading futures"
                aria-busy="true"
                class="space-y-3"
            >
                <Skeleton v-for="i in 8" :key="i" class="h-12 w-full" />
            </div>

            <p
                v-else-if="!error && !forecasts.length"
                role="status"
                class="py-12 text-center text-muted-foreground"
            >
                No futures available for this season.
            </p>
            <template v-else-if="!error">
                <MlbFuturesSummary />

                <MlbFuturesBreakdown />

                <MlbFuturesForecasts />

                <MlbFuturesTitleOdds />
            </template>
        </div>
    </PredictionsPageShell>
</template>
