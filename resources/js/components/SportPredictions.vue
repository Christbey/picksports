<script setup lang="ts">
import { CalendarX2, FilterX } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import UnifiedPredictionCard from '@/components/predictions/UnifiedPredictionCard.vue';
import SeasonSelect from '@/components/SeasonSelect.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { usePredictionList } from '@/composables/usePredictionList';
import { useSeasonFilter } from '@/composables/useSeasonFilter';
import type { PredictionListItem, SportPredictionsConfig } from '@/types';

const props = defineProps<{
    config: SportPredictionsConfig;
}>();

const filterMode = computed(() => props.config.filterMode ?? 'date');
const availableDates = ref<string[]>([]);
const selectedDate = ref('');
const today = ref('');
const seasonType = ref('');
const week = ref('');
const isBootstrapping = ref(true);
const {
    availableSeasons,
    selectedSeason,
    fetchAvailableSeasons,
} = useSeasonFilter(() => `/api/v1/${props.config.sport}/predictions/available-seasons`);

const weekOptions = computed(() => {
    if (!props.config.seasonWeekConfig || !seasonType.value) return [];

    if (seasonType.value === 'Regular Season') {
        return Array.from(
            { length: props.config.seasonWeekConfig.regularSeasonWeeks },
            (_, i) => ({
                value: String(i + 1),
                label: `Week ${i + 1}`,
            }),
        );
    }

    return props.config.seasonWeekConfig.postseasonOptions;
});

const buildParams = (page: number): URLSearchParams => {
    const params = new URLSearchParams({ page: String(page) });

    if (filterMode.value === 'date' && selectedDate.value) {
        params.append('from_date', selectedDate.value);
        params.append('to_date', selectedDate.value);
    }

    if (selectedSeason.value) {
        params.append('season', selectedSeason.value);
    }

    if (filterMode.value === 'seasonWeek') {
        if (seasonType.value) params.append('season_type', seasonType.value);
        if (week.value) params.append('week', week.value);
    }

    return params;
};

const {
    items: predictions,
    meta,
    loading,
    error,
    fetchPage: fetchPredictions,
} = usePredictionList<PredictionListItem>(async (page) => {
    if (filterMode.value === 'date' && !selectedDate.value) {
        return { data: [], meta: null };
    }

    const response = await fetch(
        `/api/v1/${props.config.sport}/predictions?${buildParams(page)}`,
    );
    if (!response.ok) throw new Error('Failed to fetch predictions');
    return response.json();
});

const formatDateLabel = (dateStr: string) => {
    const [y, m, d] = dateStr.split('-').map(Number);
    const date = new Date(y, m - 1, d);
    const label = date.toLocaleDateString('en-US', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    });
    return dateStr === today.value ? `${label} (Today)` : label;
};

const fetchAvailableDates = async () => {
    const seasonQuery = selectedSeason.value
        ? `?season=${encodeURIComponent(selectedSeason.value)}`
        : '';
    const response = await fetch(
        `/api/v1/${props.config.sport}/predictions/available-dates${seasonQuery}`,
    );
    if (!response.ok) throw new Error('Failed to fetch available dates');
    const data = await response.json();
    availableDates.value = data.data;

    const now = new Date();
    if (props.config.useEasternTime) {
        const etDate = new Date(
            now.toLocaleString('en-US', { timeZone: 'America/New_York' }),
        );
        today.value = `${etDate.getFullYear()}-${String(etDate.getMonth() + 1).padStart(2, '0')}-${String(etDate.getDate()).padStart(2, '0')}`;
    } else {
        today.value = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    }

    if (availableDates.value.includes(today.value)) {
        selectedDate.value = today.value;
    } else if (availableDates.value.length > 0) {
        const futureDates = availableDates.value.filter((d) => d > today.value);
        const pastDates = availableDates.value.filter((d) => d < today.value);
        selectedDate.value =
            futureDates.length > 0
                ? futureDates[0]
                : pastDates[pastDates.length - 1];
    }
};

watch(selectedDate, () => {
    if (filterMode.value === 'date') fetchPredictions(1);
});

watch(seasonType, () => {
    week.value = '';
});

watch(selectedSeason, async () => {
    if (isBootstrapping.value) {
        return;
    }

    if (filterMode.value === 'date') {
        await fetchAvailableDates();
        if (selectedDate.value) {
            fetchPredictions(1);
        }
        return;
    }

    if (filterMode.value === 'none') {
        fetchPredictions(1);
    }
});

const applyFilters = () => {
    fetchPredictions(1);
};

const clearFilters = () => {
    if (filterMode.value === 'date') {
        if (availableDates.value.includes(today.value)) {
            selectedDate.value = today.value;
        } else if (availableDates.value.length > 0) {
            selectedDate.value = availableDates.value[0];
        }
        return;
    }

    seasonType.value = '';
    week.value = '';
    fetchPredictions(1);
};

const hasAppliedSeasonWeekFilters = computed(() => {
    if (filterMode.value !== 'seasonWeek') return false;
    return seasonType.value !== '' || week.value !== '';
});

const emptyStateTitle = computed(() => {
    if (filterMode.value === 'date' && availableDates.value.length === 0) {
        return 'No prediction dates available yet';
    }

    if (filterMode.value === 'seasonWeek' && hasAppliedSeasonWeekFilters.value) {
        return 'No predictions match these filters';
    }

    return 'No predictions available';
});

const emptyStateDescription = computed(() => {
    if (filterMode.value === 'date' && availableDates.value.length === 0) {
        return 'We have not generated a slate for this sport yet. Run sync + prediction jobs, then refresh.';
    }

    if (filterMode.value === 'date' && selectedDate.value) {
        return `No games are available for ${formatDateLabel(selectedDate.value)}. Try another date.`;
    }

    if (filterMode.value === 'seasonWeek' && hasAppliedSeasonWeekFilters.value) {
        return 'Try clearing season/week filters to view the full board.';
    }

    return 'Check back after model runs complete.';
});

const showEmptyClearAction = computed(() => {
    if (filterMode.value === 'seasonWeek') return hasAppliedSeasonWeekFilters.value;
    if (filterMode.value === 'date') return availableDates.value.length > 0;
    return false;
});

const gameHref = (prediction: PredictionListItem): string => {
    const gameId = prediction.game?.id ?? prediction.game_id;
    return `/${props.config.sport}/games/${gameId}`;
};

onMounted(async () => {
    try {
        await fetchAvailableSeasons();

        if (filterMode.value === 'date') {
            await fetchAvailableDates();
            if (!selectedDate.value) {
                await fetchPredictions(1);
            }
            return;
        }

        await fetchPredictions(1);
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'An error occurred';
    } finally {
        isBootstrapping.value = false;
    }
});
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold">{{ config.title }}</h2>
                <p class="text-sm text-muted-foreground">
                    {{ config.subtitle }}
                </p>
            </div>
        </div>

        <Card>
            <CardContent class="pt-6">
                <div class="flex flex-wrap items-end gap-4">
                    <SeasonSelect
                        id="predictions-season"
                        v-model="selectedSeason"
                        :options="availableSeasons"
                        class="min-w-[180px] flex-1"
                    />
                    <div
                        v-if="filterMode === 'date'"
                        class="min-w-[200px] flex-1"
                    >
                        <Label for="game-date">Game Date</Label>
                        <select
                            id="game-date"
                            v-model="selectedDate"
                            :disabled="availableDates.length === 0"
                            class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <option v-if="availableDates.length === 0" value="">
                                Loading dates...
                            </option>
                            <option
                                v-for="date in availableDates"
                                :key="date"
                                :value="date"
                            >
                                {{ formatDateLabel(date) }}
                            </option>
                        </select>
                    </div>
                    <template v-else-if="filterMode === 'seasonWeek'">
                        <div class="min-w-[200px] flex-1">
                            <Label for="season-type">Season Type</Label>
                            <select
                                id="season-type"
                                v-model="seasonType"
                                class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <option value="">All Season Types</option>
                                <option value="Regular Season">
                                    Regular Season
                                </option>
                                <option value="Postseason">Postseason</option>
                            </select>
                        </div>
                        <div class="min-w-[200px] flex-1">
                            <Label for="week">Week</Label>
                            <select
                                id="week"
                                v-model="week"
                                :disabled="!seasonType"
                                class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <option value="">All Weeks</option>
                                <option
                                    v-for="option in weekOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <Button @click="applyFilters" :disabled="loading">
                                Apply Filters
                            </Button>
                            <Button
                                @click="clearFilters"
                                variant="outline"
                                :disabled="loading"
                            >
                                Clear
                            </Button>
                        </div>
                    </template>
                </div>
            </CardContent>
        </Card>

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

        <div v-else-if="predictions.length > 0" class="grid gap-4">
            <template v-for="prediction in predictions" :key="prediction.id">
                <UnifiedPredictionCard
                    :prediction="prediction"
                    :href="gameHref(prediction)"
                    :sport="config.sport"
                />
            </template>
        </div>

        <Card v-else class="border-dashed">
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
