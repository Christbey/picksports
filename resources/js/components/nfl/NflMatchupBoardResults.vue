<script setup lang="ts">
import NflMatchupCard from '@/components/nfl/NflMatchupCard.vue';
import { useNflMatchupBoardContext } from '@/composables/useNflMatchupBoard';
const {
    refreshing,
    filtersLoading,
    error,
    selectedDate,
    filteredPredictions,
    quietEmptyState,
    formatDateLabel,
    selectMatchup,
} = useNflMatchupBoardContext();
</script>
<template>
    <section class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold">
                    {{ formatDateLabel(selectedDate) }} ·
                    {{ filteredPredictions.length }}
                    {{ filteredPredictions.length === 1 ? 'game' : 'games' }}
                </h2>
            </div>
        </div>

        <div
            v-if="
                !error &&
                !filtersLoading &&
                !refreshing &&
                filteredPredictions.length === 0
            "
            class="rounded-2xl border border-dashed bg-card/70 p-6"
        >
            <div class="font-semibold">{{ quietEmptyState.title }}</div>
            <p class="mt-1 text-sm leading-6 text-muted-foreground">
                {{ quietEmptyState.body }}
            </p>
        </div>

        <div
            v-else-if="filteredPredictions.length > 0"
            class="grid gap-3 xl:grid-cols-2"
        >
            <NflMatchupCard
                v-for="prediction in filteredPredictions"
                :key="prediction.id"
                :prediction="prediction"
                @select="selectMatchup"
            />
        </div>
    </section>
</template>
