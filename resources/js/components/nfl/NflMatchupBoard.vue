<script setup lang="ts">
import { provide } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import DataRecovery from '@/components/DataRecovery.vue';
import NflMatchupDetailDrawer from '@/components/nfl/NflMatchupDetailDrawer.vue';
import NflMatchupBoardToolbar from '@/components/nfl/NflMatchupBoardToolbar.vue';
import NflMatchupBoardResults from '@/components/nfl/NflMatchupBoardResults.vue';
import NflMatchupBoardInsights from '@/components/nfl/NflMatchupBoardInsights.vue';
import {
    useNflMatchupBoard,
    nflMatchupBoardKey,
} from '@/composables/useNflMatchupBoard';
const state = useNflMatchupBoard();
provide(nflMatchupBoardKey, state);
const {
    loading,
    refreshing,
    filtersLoading,
    error,
    boardStale,
    selectedPrediction,
    detailOpen,
    loadBoard,
    refreshBoard,
} = state;
</script>
<template>
    <section class="space-y-5" :aria-busy="refreshing">
        <header>
            <h1 class="text-xl font-semibold">NFL predictions</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Matchup forecasts and stored lines. Updates may be delayed.
            </p>
        </header>
        <div v-if="loading" class="space-y-4">
            <Skeleton class="h-20 w-full rounded-2xl" />
            <Skeleton class="h-28 w-full rounded-2xl" />
            <div class="grid gap-3 xl:grid-cols-2">
                <Skeleton class="h-44 rounded-2xl" />
                <Skeleton class="h-44 rounded-2xl" />
            </div>
        </div>

        <template v-else>
            <NflMatchupBoardToolbar />

            <DataRecovery
                v-if="error"
                :message="error"
                :busy="refreshing || filtersLoading"
                :stale="boardStale"
                @retry="refreshBoard"
            />

            <NflMatchupBoardResults />

            <NflMatchupBoardInsights />

            <NflMatchupDetailDrawer
                v-model:open="detailOpen"
                :prediction="selectedPrediction"
                @research-updated="loadBoard"
            />
        </template>
    </section>
</template>
