<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
const { loading, error } = state;
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

            <Alert v-if="error" variant="destructive">
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>

            <div v-if="loading" class="space-y-3">
                <Skeleton v-for="i in 8" :key="i" class="h-12 w-full" />
            </div>

            <template v-else>
                <NbaFuturesSummary />

                <NbaFuturesBreakdown />

                <NbaFuturesForecasts />

                <NbaFuturesTitleOdds />
            </template>
        </div>
    </PredictionsPageShell>
</template>
