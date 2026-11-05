<script setup lang="ts">
import { provide } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
const { error, refreshError } = board;
</script>

<template>
    <div class="space-y-4">
        <p v-if="refreshError" role="status" class="text-sm text-amber-600">
            {{ refreshError }}
        </p>
        <PredictionBoardHeader />

        <PredictionBoardToolbar />
        <PredictionBoardFilters />

        <Alert v-if="error" variant="destructive">
            <AlertDescription>{{ error }}</AlertDescription>
        </Alert>

        <PredictionBoardResults />

        <!-- Pagination -->
        <PredictionBoardPagination />
    </div>
</template>
