<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { usePredictionBoardContext } from '@/composables/usePredictionBoard';
const {
    meta,
    loading,
    fetchPredictions,
    selectedSeason,
    selectedDate,
    seasonType,
    week,
    searchQuery,
    betViewMode,
} = usePredictionBoardContext();
const navigation = ref<HTMLElement | null>(null);
let generation = 0;
let releaseOwnership = () => {};

function invalidateFocusOwnership() {
    generation++;
    releaseOwnership();
    releaseOwnership = () => {};
}

watch(
    [selectedSeason, selectedDate, seasonType, week, searchQuery, betViewMode],
    invalidateFocusOwnership,
    { flush: 'sync' },
);
onBeforeUnmount(invalidateFocusOwnership);

async function changePage(page: number, event: MouseEvent) {
    const trigger = event.currentTarget;
    if (
        !(trigger instanceof HTMLButtonElement) ||
        trigger.disabled ||
        loading.value
    )
        return;
    invalidateFocusOwnership();
    const request = generation;
    // Only an already-focused trigger can own focus lost when disabled for loading.
    let ownsFocus = document.activeElement === trigger;
    const movedFocus = (event: FocusEvent) => {
        if (event.target !== trigger && event.target !== document.body)
            ownsFocus = false;
    };
    const userInteraction = () => {
        ownsFocus = false;
    };
    document.addEventListener('focusin', movedFocus, true);
    document.addEventListener('pointerdown', userInteraction, true);
    document.addEventListener('keydown', userInteraction, true);
    const release = () => {
        document.removeEventListener('focusin', movedFocus, true);
        document.removeEventListener('pointerdown', userInteraction, true);
        document.removeEventListener('keydown', userInteraction, true);
    };
    releaseOwnership = release;
    try {
        await fetchPredictions(page);
        await nextTick();
        if (request !== generation || !ownsFocus || loading.value) return;
        const active = document.activeElement;
        if (active !== trigger && active !== document.body) return;
        const target =
            trigger.isConnected && !trigger.disabled
                ? trigger
                : navigation.value?.querySelector<HTMLButtonElement>(
                      'button:not(:disabled)',
                  );
        // An error retains the original enabled trigger; a terminal page uses its enabled peer.
        target?.focus({ preventScroll: true });
    } finally {
        release();
        if (releaseOwnership === release) releaseOwnership = () => {};
    }
}
</script>

<template>
    <div
        v-if="meta && meta.last_page > 1"
        ref="navigation"
        role="navigation"
        aria-label="Prediction pages"
        class="flex flex-wrap items-center justify-center gap-2"
    >
        <button
            @click="changePage(meta.current_page - 1, $event)"
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
            @click="changePage(meta.current_page + 1, $event)"
            :disabled="loading || meta.current_page === meta.last_page"
            type="button"
            class="min-h-11 min-w-11 rounded border px-3 py-1 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-50 sm:min-h-0"
        >
            Next
        </button>
    </div>
</template>
