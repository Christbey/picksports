<script setup lang="ts">
import { usePredictionBoardContext } from '@/composables/usePredictionBoard';
const { meta, loading, fetchPredictions } = usePredictionBoardContext();
</script>

<template>
    <div
        v-if="meta && meta.last_page > 1"
        role="navigation"
        aria-label="Prediction pages"
        class="flex flex-wrap items-center justify-center gap-2"
    >
        <button
            @click="fetchPredictions(meta.current_page - 1)"
            :disabled="loading || meta.current_page === 1"
            type="button"
            class="min-h-11 min-w-11 rounded border px-3 py-1 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-50 sm:min-h-0"
        >
            Previous
        </button>
        <span class="text-sm text-muted-foreground">
            Page {{ meta.current_page }} of {{ meta.last_page }}
        </span>
        <button
            @click="fetchPredictions(meta.current_page + 1)"
            :disabled="loading || meta.current_page === meta.last_page"
            type="button"
            class="min-h-11 min-w-11 rounded border px-3 py-1 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-50 sm:min-h-0"
        >
            Next
        </button>
    </div>
</template>
