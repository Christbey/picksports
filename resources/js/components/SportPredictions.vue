<script setup lang="ts">
import {
    CalendarX2,
    FilterX,
    SlidersHorizontal,
    Search,
} from 'lucide-vue-next';
import UnifiedPredictionCard from '@/components/predictions/UnifiedPredictionCard.vue';
import SeasonSelect from '@/components/SeasonSelect.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import {
    usePredictionBoard,
    type PredictionsBoardProps,
} from '@/composables/usePredictionBoard';
const props = defineProps<PredictionsBoardProps>();
const board = usePredictionBoard(props);
const {
    filterMode,
    availableDates,
    selectedDate,
    seasonType,
    week,
    searchQuery,
    showFilters,
    betViewMode,
    availableSeasons,
    selectedSeason,
    weekOptions,
    formatDateLabel,
    applyFilters,
    clearFilters,
    recommendedBetCount,
    filteredPredictions,
    showSpringTrainingBadge,
    emptyStateTitle,
    emptyStateDescription,
    showEmptyClearAction,
    gameHref,
    predictions,
    meta,
    loading,
    error,
    refreshError,
    fetchPredictions,
} = board;
</script>

<template>
    <div class="space-y-4">
        <p v-if="refreshError" role="status" class="text-sm text-amber-600">
            {{ refreshError }}
        </p>
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
                    <span v-if="showSpringTrainingBadge">
                        · Spring training</span
                    >
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

        <div
            class="flex flex-wrap items-center gap-3 border-y border-border py-3"
        >
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
                <option
                    v-for="date in availableDates"
                    :key="date"
                    :value="date"
                >
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
                ><SlidersHorizontal class="size-4" /><span
                    >Filters</span
                ></Button
            >
        </div>
        <div
            v-if="showFilters"
            id="board-filters"
            class="flex flex-wrap items-end gap-4 rounded-lg bg-muted/40 p-4"
        >
            <SeasonSelect
                id="predictions-season"
                v-model="selectedSeason"
                :options="availableSeasons"
                :disabled="loading"
            />
            <div v-if="filterMode === 'seasonWeek'">
                <Label for="season-type">Season type</Label>
                <select
                    id="season-type"
                    v-model="seasonType"
                    :disabled="loading"
                    class="mt-1 block h-10 rounded-md border border-input bg-background px-3 text-sm"
                    @change="applyFilters"
                >
                    <option value="">All season types</option>
                    <option value="Regular Season">Regular season</option>
                    <option value="Postseason">Postseason</option>
                </select>
            </div>
            <Button variant="ghost" :disabled="loading" @click="clearFilters"
                >Reset</Button
            >
        </div>

        <Alert v-if="error" variant="destructive">
            <AlertDescription>{{ error }}</AlertDescription>
        </Alert>

        <div v-if="loading" class="grid gap-4">
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
            v-else-if="filteredPredictions.length > 0"
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

        <Card v-else class="border-dashed">
            <CardContent
                class="flex flex-col items-center gap-3 py-12 text-center"
            >
                <div class="rounded-full bg-muted p-3 text-muted-foreground">
                    <CalendarX2
                        v-if="
                            filterMode === 'date' && availableDates.length === 0
                        "
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

        <!-- Pagination -->
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
    </div>
</template>
