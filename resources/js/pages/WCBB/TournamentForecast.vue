<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { provide } from 'vue';
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import DataRecovery from '@/components/DataRecovery.vue';

import { Skeleton } from '@/components/ui/skeleton';
import {
    useWcbbForecast,
    wcbbForecastKey,
} from '@/composables/useWcbbForecast';
import WcbbForecastFilters from '@/components/predictions/WcbbForecastFilters.vue';
import WcbbForecastSummary from '@/components/predictions/WcbbForecastSummary.vue';
import WcbbForecastTeams from '@/components/predictions/WcbbForecastTeams.vue';
import WcbbForecastConferences from '@/components/predictions/WcbbForecastConferences.vue';
import WcbbForecastTitleOdds from '@/components/predictions/WcbbForecastTitleOdds.vue';
import WcbbForecastBubbleRisk from '@/components/predictions/WcbbForecastBubbleRisk.vue';
const state = useWcbbForecast();
provide(wcbbForecastKey, state);
const { bubbleRisk, loading, error, forecasts, fetchForecasts } = state;
</script>
<template>
    <Head title="WCBB Tournament Forecast" />

    <PredictionsPageShell
        title="WCBB Tournament Forecast"
        sport-title="WCBB"
        sport-href="/wcbb/predictions"
        page-title="Tournament Forecast"
        banner-storage-key="wcbb-tournament-forecast-banner-dismissed"
        seo-description="March Madness forecast with automatic bid probability, at-large probability, First Four risk, and championship odds."
    >
        <div class="space-y-4">
            <WcbbForecastFilters />

            <DataRecovery
                v-if="error"
                :message="error"
                :busy="loading"
                @retry="fetchForecasts"
            />

            <div
                v-if="loading"
                role="status"
                aria-label="Loading WCBB tournament forecast"
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
                No WCBB tournament forecast available for this season.
            </p>
            <template v-else-if="!error">
                <WcbbForecastSummary />

                <WcbbForecastTeams />

                <WcbbForecastConferences />

                <WcbbForecastTitleOdds />

                <WcbbForecastBubbleRisk v-if="bubbleRisk.length > 0" />
            </template>
        </div>
    </PredictionsPageShell>
</template>
