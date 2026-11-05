<script setup lang="ts">
import { usePredictionBoardContext } from '@/composables/usePredictionBoard';
const { meta, fetchPredictions } = usePredictionBoardContext();
</script>

<template>
    <div
        v-if="meta && meta.last_page > 1"
        class="flex items-center justify-center gap-2"
    >
        <button
            @click="fetchPredictions(meta.current_page - 1)"
            :disabled="meta.current_page === 1"
            class="rounded border px-3 py-1 text-sm disabled:opacity-50"
        >
            Previous
        </button>
        <span class="text-sm text-muted-foreground">
            Page {{ meta.current_page }} of {{ meta.last_page }}
        </span>
        <button
            @click="fetchPredictions(meta.current_page + 1)"
            :disabled="meta.current_page === meta.last_page"
            class="rounded border px-3 py-1 text-sm disabled:opacity-50"
        >
            Next
        </button>
    </div>
</template>
