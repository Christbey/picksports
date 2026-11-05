<script setup lang="ts">
import { provide } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import DataRecovery from '@/components/DataRecovery.vue';
import MlbMatchupDetailDrawer from '@/components/mlb/MlbMatchupDetailDrawer.vue';
import {
    useMlbMatchupBoard,
    mlbMatchupBoardKey,
} from '@/composables/useMlbMatchupBoard';
import MlbMatchupBoardToolbar from '@/components/mlb/MlbMatchupBoardToolbar.vue';
import MlbMatchupBoardResults from '@/components/mlb/MlbMatchupBoardResults.vue';

const state = useMlbMatchupBoard();
provide(mlbMatchupBoardKey, state);
const {
    loading,
    refreshing,
    filtersLoading,
    error,
    boardStale,
    selectedPrediction,
    selectedCandidate,
    selectedCandidates,
    detailOpen,
    detailLoading,
    detailError,
    refreshBoard,
} = state;
</script>
<template>
    <section class="space-y-5">
        <div v-if="loading" class="space-y-4">
            <Skeleton class="h-20 w-full rounded-2xl" />
            <Skeleton class="h-28 w-full rounded-2xl" />
            <div class="grid gap-3 xl:grid-cols-2">
                <Skeleton class="h-44 rounded-2xl" />
                <Skeleton class="h-44 rounded-2xl" />
            </div>
        </div>

        <template v-else>
            <MlbMatchupBoardToolbar />

            <DataRecovery
                v-if="error"
                :message="error"
                :busy="refreshing || filtersLoading"
                :stale="boardStale"
                @retry="refreshBoard"
            />

            <MlbMatchupBoardResults />

            <MlbMatchupDetailDrawer
                v-model:open="detailOpen"
                :prediction="selectedPrediction"
                :candidate="selectedCandidate"
                :candidates="selectedCandidates"
                :loading-details="detailLoading"
                :detail-error="detailError"
                @select-candidate="selectedCandidate = $event"
            />
        </template>
    </section>
</template>
