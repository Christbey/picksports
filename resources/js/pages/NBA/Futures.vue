<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import DataRecovery from '@/components/DataRecovery.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { provide } from 'vue';
import { useNbaFutures, nbaFuturesKey } from '@/composables/useNbaFutures';
import NbaFuturesFilters from '@/components/predictions/NbaFuturesFilters.vue';
import NbaFuturesSummary from '@/components/predictions/NbaFuturesSummary.vue';
import NbaFuturesBreakdown from '@/components/predictions/NbaFuturesBreakdown.vue';
import NbaFuturesForecasts from '@/components/predictions/NbaFuturesForecasts.vue';
import NbaFuturesTitleOdds from '@/components/predictions/NbaFuturesTitleOdds.vue';
const state = useNbaFutures();
provide(nbaFuturesKey, state);
const { loading, error, forecasts, fetchForecasts, notice } = state;
</script>
<template>
    <Head title="NBA Futures" />

    <PredictionsPageShell
        title="NBA Futures"
        sport-title="NBA"
        sport-href="/nba/predictions"
        page-title="Futures"
        banner-storage-key="nba-futures-banner-dismissed"
        seo-description="NBA postseason forecast with playoff odds, conference finals odds, Finals probability, and championship futures."
    >
        <div class="space-y-5">
            <NbaFuturesFilters />

            <DataRecovery
                v-if="error"
                :message="error"
                :busy="loading"
                @retry="fetchForecasts"
            />
            <p
                v-if="notice"
                role="status"
                class="text-sm text-muted-foreground"
            >
                {{ notice }}
            </p>

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
                <NbaFuturesSummary />

                <NbaFuturesBreakdown />

                <NbaFuturesForecasts />

                <NbaFuturesTitleOdds />
            </template>
        </div>
    </PredictionsPageShell>
</template>
