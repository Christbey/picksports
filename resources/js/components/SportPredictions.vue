<script setup lang="ts">
import { provide } from 'vue';
import DataRecovery from '@/components/DataRecovery.vue';
import {
    usePredictionBoard,
    predictionBoardKey,
    type PredictionsBoardProps,
} from '@/composables/usePredictionBoard';
import PredictionBoardHeader from '@/components/predictions/PredictionBoardHeader.vue';
import PredictionBoardToolbar from '@/components/predictions/PredictionBoardToolbar.vue';
import PredictionBoardFilters from '@/components/predictions/PredictionBoardFilters.vue';
import PredictionBoardResults from '@/components/predictions/PredictionBoardResults.vue';
import PredictionBoardPagination from '@/components/predictions/PredictionBoardPagination.vue';
const props = defineProps<PredictionsBoardProps>();
const board = usePredictionBoard(props);
provide(predictionBoardKey, board);
const { error, refreshError, loading, refreshing, retryPredictions } = board;
</script>

<template>
    <div class="space-y-4">
        <DataRecovery
            v-if="refreshError"
            :message="refreshError"
            :busy="refreshing || loading"
            stale
            @retry="retryPredictions"
        />
        <PredictionBoardHeader />

        <PredictionBoardToolbar />
        <PredictionBoardFilters />

        <DataRecovery
            v-if="error"
            :message="error"
            :busy="loading"
            @retry="retryPredictions"
        />

        <PredictionBoardResults />

        <!-- Pagination -->
        <PredictionBoardPagination />
    </div>
</template>
