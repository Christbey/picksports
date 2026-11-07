<script setup lang="ts">
import { CalendarDays, RefreshCw, Search } from 'lucide-vue-next';
import { useMlbMatchupBoardContext } from '@/composables/useMlbMatchupBoard';
const {
    searchQuery,
    visibleMarketFilters,
    selectedFilter,
    formatDateLabel,
    refreshing,
    filtersLoading,
    availableSeasons,
    availableDates,
    selectedSeason,
    selectedDate,
    refreshBoard,
} = useMlbMatchupBoardContext();
</script>
<template>
    <section class="rounded-2xl border bg-card/90 p-3 shadow-sm">
        <div class="space-y-3">
            <div
                class="grid gap-2 lg:grid-cols-[minmax(260px,330px)_minmax(260px,1fr)]"
            >
                <div class="grid gap-2 sm:grid-cols-[180px_140px]">
                    <label class="relative">
                        <CalendarDays
                            class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <select
                            v-model="selectedDate"
                            aria-label="Matchup date"
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
                        aria-label="Matchup season"
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

                <div class="grid gap-2 sm:grid-cols-[minmax(180px,1fr)_auto]">
                    <label class="relative">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <input
                            v-model="searchQuery"
                            type="search"
                            class="h-10 w-full rounded-xl border bg-background pr-3 pl-9 text-sm"
                            placeholder="Search matchup"
                        />
                    </label>
                    <button
                        type="button"
                        class="inline-flex h-10 items-center justify-center gap-2 rounded-xl border bg-background px-3 text-sm font-semibold transition hover:bg-muted"
                        :disabled="refreshing || filtersLoading"
                        @click="refreshBoard"
                    >
                        <RefreshCw
                            class="h-4 w-4"
                            :class="refreshing ? 'animate-spin' : ''"
                        />
                        Refresh
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    v-for="filter in visibleMarketFilters"
                    :key="filter.key"
                    type="button"
                    class="rounded-full border px-3 py-2 text-xs font-semibold whitespace-nowrap transition"
                    :class="
                        selectedFilter === filter.key
                            ? 'border-emerald-500 bg-emerald-500 text-white shadow-sm'
                            : 'bg-background/85 text-muted-foreground hover:border-emerald-500/30 hover:bg-muted'
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
