<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { usePredictionBoardContext } from '@/composables/usePredictionBoard';
const {
    filterMode,
    seasonType,
    week,
    selectedSeason,
    clearFilters,
    showSpringTrainingBadge,
    predictions,
    meta,
    loading,
    config,
} = usePredictionBoardContext();
</script>

<template>
    <header class="flex flex-wrap items-center justify-between gap-4 pb-2">
        <div>
            <p
                class="mb-1 text-xs font-medium tracking-widest text-muted-foreground uppercase"
            >
                {{ config.sport }} / Game board
            </p>
            <h1 class="text-3xl font-semibold tracking-tight">
                {{
                    filterMode === 'seasonWeek' && week !== ''
                        ? `Week ${week}`
                        : config.title
                }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ selectedSeason
                }}<template v-if="seasonType"> · {{ seasonType }}</template>
                <template v-if="!loading">
                    ·
                    {{ meta?.total ?? predictions.length }} games</template
                >
                <span v-if="showSpringTrainingBadge"> · Spring training</span>
            </p>
        </div>
        <Button
            v-if="filterMode === 'seasonWeek'"
            variant="outline"
            :disabled="loading"
            @click="clearFilters"
            >Current week</Button
        >
    </header>
</template>
