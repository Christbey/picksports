<script setup lang="ts">
import { CalendarX2, FilterX } from 'lucide-vue-next';
import UnifiedPredictionCard from '@/components/predictions/UnifiedPredictionCard.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { usePredictionBoardContext } from '@/composables/usePredictionBoard';
const {
    filterMode,
    availableDates,
    clearFilters,
    filteredPredictions,
    emptyStateTitle,
    emptyStateDescription,
    showEmptyClearAction,
    gameHref,
    loading,
    error,
    config,
} = usePredictionBoardContext();
</script>

<template>
    <div
        v-if="loading"
        role="status"
        aria-label="Loading predictions"
        aria-busy="true"
        class="grid gap-4"
    >
        <Card v-for="i in 3" :key="i">
            <CardHeader>
                <Skeleton class="h-4 w-48" />
                <Skeleton class="h-3 w-32" />
            </CardHeader>
            <CardContent>
                <Skeleton class="h-20 w-full" />
            </CardContent>
        </Card>
    </div>

    <div
        v-else-if="!error && filteredPredictions.length > 0"
        class="divide-y divide-border overflow-hidden rounded-lg border border-border bg-card"
    >
        <template
            v-for="prediction in filteredPredictions"
            :key="prediction.game.id"
        >
            <UnifiedPredictionCard
                :prediction="prediction"
                :href="gameHref(prediction)"
                :sport="config.sport"
                compact
            />
        </template>
    </div>

    <Card v-else-if="!error" class="border-dashed">
        <CardContent class="flex flex-col items-center gap-3 py-12 text-center">
            <div class="rounded-full bg-muted p-3 text-muted-foreground">
                <CalendarX2
                    v-if="filterMode === 'date' && availableDates.length === 0"
                    class="h-5 w-5"
                />
                <FilterX v-else class="h-5 w-5" />
            </div>
            <h3 class="text-base font-semibold">{{ emptyStateTitle }}</h3>
            <p class="max-w-xl text-sm text-muted-foreground">
                {{ emptyStateDescription }}
            </p>
            <div v-if="showEmptyClearAction" class="pt-1">
                <Button variant="outline" size="sm" @click="clearFilters">
                    Clear Filters
                </Button>
            </div>
        </CardContent>
    </Card>
</template>
