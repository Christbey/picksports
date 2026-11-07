<script setup lang="ts">
import { CalendarDays, Search, RefreshCw } from 'lucide-vue-next';
import { useNflMatchupBoardContext } from '@/composables/useNflMatchupBoard';
const {
    refreshing,
    filtersLoading,
    availableSeasons,
    availableDates,
    selectedSeason,
    selectedDate,
    searchQuery,
    selectedFilter,
    visibleBoardFilters,
    formatDateLabel,
    refreshBoard,
} = useNflMatchupBoardContext();
</script>
<template>
    <section class="rounded-2xl border bg-card/90 p-3 shadow-sm">
        <div class="space-y-3">
            <div
                class="grid gap-2 lg:grid-cols-[minmax(260px,330px)_minmax(260px,1fr)]"
            >
                <div
                    class="grid grid-cols-[minmax(0,1fr)_90px] gap-2 sm:grid-cols-[180px_100px]"
                >
                    <label class="relative">
                        <CalendarDays
                            class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <select
                            v-model="selectedDate"
                            aria-label="Game date"
                            :disabled="filtersLoading"
                            class="h-10 w-full rounded-xl border bg-background pr-3 pl-9 text-sm font-medium"
                        >
                            <option
                                v-for="date in availableDates"
                                :key="date"
                                :value="date"
                            >
                                {{ formatDateLabel(date) }}
                            </option>
                        </select>
                    </label>

                    <select
                        v-model="selectedSeason"
                        aria-label="Season"
                        class="h-10 rounded-xl border bg-background px-3 text-sm font-medium"
                    >
                        <option
                            v-for="season in availableSeasons"
                            :key="season"
                            :value="String(season)"
                        >
                            {{ season }}
                        </option>
                    </select>
                </div>

                <div class="grid grid-cols-[minmax(0,1fr)_44px] gap-2">
                    <label class="relative">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <input
                            v-model="searchQuery"
                            aria-label="Search matchups"
                            type="search"
                            class="h-10 w-full rounded-xl border bg-background pr-3 pl-9 text-sm"
                            placeholder="Search matchup"
                        />
                    </label>
                    <button
                        type="button"
                        aria-label="Refresh predictions"
                        :disabled="refreshing || filtersLoading"
                        class="inline-flex h-10 items-center justify-center gap-2 rounded-xl border bg-background px-3 text-sm font-semibold transition hover:bg-muted"
                        @click="refreshBoard"
                    >
                        <RefreshCw
                            class="h-4 w-4"
                            :class="refreshing ? 'animate-spin' : ''"
                        />
                        <span class="sr-only">Refresh</span>
                    </button>
                </div>
            </div>

            <div
                class="flex gap-2 overflow-x-auto pb-1"
                aria-label="Prediction filters"
            >
                <button
                    v-for="filter in visibleBoardFilters"
                    :key="filter.key"
                    type="button"
                    :aria-pressed="selectedFilter === filter.key"
                    class="rounded-full border px-3 py-2 text-xs font-semibold whitespace-nowrap transition"
                    :class="
                        selectedFilter === filter.key
                            ? 'border-sky-500 bg-sky-500 text-white shadow-sm'
                            : 'bg-background/85 text-muted-foreground hover:border-sky-500/30 hover:bg-muted'
                    "
                    @click="selectedFilter = filter.key"
                >
                    {{ filter.label }}
                    <span class="ml-1 opacity-75">{{ filter.count }}</span>
                </button>
            </div>
        </div>
    </section>
</template>
