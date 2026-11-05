<script setup lang="ts">
import { SlidersHorizontal, Search } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usePredictionBoardContext } from '@/composables/usePredictionBoard';
const {
    filterMode,
    availableDates,
    selectedDate,
    seasonType,
    week,
    searchQuery,
    showFilters,
    betViewMode,
    weekOptions,
    formatDateLabel,
    applyFilters,
    recommendedBetCount,
    loading,
} = usePredictionBoardContext();
</script>

<template>
    <div class="flex flex-wrap items-center gap-3 border-y border-border py-3">
        <div class="relative min-w-40 flex-1">
            <Search
                class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
            />
            <Input
                id="prediction-search"
                v-model="searchQuery"
                aria-label="Search matchups on this page"
                placeholder="Find a team…"
                class="border-transparent bg-muted/50 pl-9 shadow-none"
            />
        </div>
        <select
            v-if="filterMode === 'seasonWeek'"
            id="week"
            v-model="week"
            aria-label="Week"
            class="h-10 rounded-md border border-input bg-background px-3 text-sm"
            :disabled="!seasonType || loading"
            @change="applyFilters"
        >
            <option value="">All weeks</option>
            <option
                v-for="option in weekOptions"
                :key="option.value"
                :value="option.value"
            >
                {{ option.label }}
            </option>
        </select>
        <select
            v-if="filterMode === 'date'"
            id="game-date"
            v-model="selectedDate"
            aria-label="Game date"
            :disabled="!availableDates.length"
            class="h-10 rounded-md border border-input bg-background px-3 text-sm"
        >
            <option v-if="!availableDates.length" value="">
                No dates available
            </option>
            <option v-for="date in availableDates" :key="date" :value="date">
                {{ formatDateLabel(date) }}
            </option>
        </select>
        <div class="flex rounded-md bg-muted p-1" aria-label="Board view">
            <button
                type="button"
                :aria-pressed="betViewMode === 'all'"
                :class="[
                    'rounded px-3 py-1.5 text-sm',
                    betViewMode === 'all'
                        ? 'bg-background font-medium shadow-sm'
                        : 'text-muted-foreground',
                ]"
                @click="betViewMode = 'all'"
            >
                All games
            </button>
            <button
                type="button"
                :aria-pressed="betViewMode === 'recommended'"
                :class="[
                    'rounded px-3 py-1.5 text-sm',
                    betViewMode === 'recommended'
                        ? 'bg-background font-medium shadow-sm'
                        : 'text-muted-foreground',
                ]"
                @click="betViewMode = 'recommended'"
            >
                Bets
                <span class="ml-1 text-xs text-muted-foreground">{{
                    recommendedBetCount
                }}</span>
            </button>
        </div>
        <Button
            variant="ghost"
            :aria-expanded="showFilters"
            aria-controls="board-filters"
            @click="showFilters = !showFilters"
            ><SlidersHorizontal class="size-4" /><span>Filters</span></Button
        >
    </div>
</template>
