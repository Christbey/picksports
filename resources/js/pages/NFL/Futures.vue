<script setup lang="ts">
import { provide } from 'vue';
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import DataRecovery from '@/components/DataRecovery.vue';

import { Skeleton } from '@/components/ui/skeleton';
import { useNflForecast, nflForecastKey } from '@/composables/useNflForecast';
import NflForecastFilters from '@/components/predictions/NflForecastFilters.vue';
import NflForecastSummary from '@/components/predictions/NflForecastSummary.vue';
import NflForecastConferenceLeaders from '@/components/predictions/NflForecastConferenceLeaders.vue';
import NflForecastOutlook from '@/components/predictions/NflForecastOutlook.vue';
const state = useNflForecast();
provide(nflForecastKey, state);
const {
    forecasts,
    simulations,
    warnings,
    conferenceLeaders,
    loading,
    error,
    fetchForecasts,
} = state;
</script>
<template>
    <PredictionsPageShell
        title="NFL Futures"
        sport-title="NFL"
        sport-href="/nfl/predictions"
        page-title="Futures"
        banner-storage-key="nfl-futures-banner-dismissed"
        seo-description="NFL playoff, conference championship, and Super Bowl forecasts compared with available futures prices."
    >
        <div class="space-y-5">
            <NflForecastFilters />

            <DataRecovery
                v-if="error"
                :message="error"
                :busy="loading"
                @retry="fetchForecasts"
            />

            <p class="text-sm text-muted-foreground">
                Schedule-based estimates, not calibrated betting probabilities.
                Completed results are preserved. Prices older than 36 hours are
                excluded from comparisons.
            </p>
            <p v-if="simulations" class="text-xs text-muted-foreground">
                * 0% means no occurrences in
                {{ simulations.toLocaleString() }} simulations, not proof of
                impossibility.
            </p>
            <p
                v-for="warning in warnings"
                :key="warning"
                class="text-xs text-muted-foreground"
            >
                {{ warning }}
            </p>
            <div
                v-if="loading"
                role="status"
                aria-label="Loading NFL futures"
                aria-busy="true"
                class="space-y-3"
            >
                <Skeleton v-for="row in 8" :key="row" class="h-12 w-full" />
            </div>

            <p
                v-else-if="!error && !forecasts.length"
                role="status"
                class="py-12 text-center text-muted-foreground"
            >
                No NFL futures available for this season.
            </p>
            <template v-else-if="!error">
                <NflForecastSummary />

                <NflForecastConferenceLeaders v-if="conferenceLeaders.length" />

                <NflForecastOutlook />
            </template>
        </div>
    </PredictionsPageShell>
</template>
