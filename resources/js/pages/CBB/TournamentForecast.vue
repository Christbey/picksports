<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { provide } from 'vue';
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import DataRecovery from '@/components/DataRecovery.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Skeleton } from '@/components/ui/skeleton';
import { useCbbForecast, cbbForecastKey } from '@/composables/useCbbForecast';
import CbbForecastFilters from '@/components/predictions/CbbForecastFilters.vue';
import CbbForecastSummary from '@/components/predictions/CbbForecastSummary.vue';
import CbbForecastField from '@/components/predictions/CbbForecastField.vue';
import CbbForecastChampionship from '@/components/predictions/CbbForecastChampionship.vue';
import CbbForecastDeepRuns from '@/components/predictions/CbbForecastDeepRuns.vue';
const state = useCbbForecast();
provide(cbbForecastKey, state);
const { actualFieldKnown, loading, error, fetchForecasts } = state;
</script>
<template>
    <Head title="CBB Tournament Forecast" />

    <PredictionsPageShell
        title="CBB Tournament Forecast"
        sport-title="CBB"
        sport-href="/cbb/predictions"
        page-title="Tournament Forecast"
        banner-storage-key="cbb-tournament-forecast-banner-dismissed"
        seo-description="March Madness field outlook with actual teams, regional placement, and championship probabilities."
    >
        <div class="space-y-4">
            <CbbForecastFilters />

            <DataRecovery
                v-if="error"
                :message="error"
                :busy="loading"
                @retry="fetchForecasts"
            />

            <div
                v-if="loading"
                role="status"
                aria-label="Loading CBB tournament forecast"
                aria-busy="true"
                class="space-y-3"
            >
                <Skeleton v-for="i in 8" :key="i" class="h-12 w-full" />
            </div>

            <template v-else-if="!error && actualFieldKnown">
                <CbbForecastSummary />

                <CbbForecastField />

                <CbbForecastChampionship />

                <CbbForecastDeepRuns />
            </template>

            <Alert v-else-if="!error">
                <AlertDescription
                    >No actual tournament field is attached to this season yet.
                    Load the tournament metadata and forecast rows for the
                    selected season to populate this page.</AlertDescription
                >
            </Alert>
        </div>
    </PredictionsPageShell>
</template>
