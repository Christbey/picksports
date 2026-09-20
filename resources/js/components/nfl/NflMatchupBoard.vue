<script setup lang="ts">
import { CalendarDays, RefreshCw, Search } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import NflMatchupCard from '@/components/nfl/NflMatchupCard.vue';
import NflMatchupDetailDrawer from '@/components/nfl/NflMatchupDetailDrawer.vue';
import NflSignalsPanel from '@/components/nfl/NflSignalsPanel.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { nflBoardPresentation } from '@/lib/nflBoardPresentation';
import type {
    ApiV2CollectionResponse,
    ApiV2Prediction,
    ApiV2Record,
} from '@/types';

type BoardFilter =
    | 'all'
    | 'bets'
    | 'watchlist'
    | 'spread'
    | 'total'
    | 'winner'
    | 'finals';

const api = useApiV2Client();

const loading = ref(true);
const refreshing = ref(false);
const error = ref<string | null>(null);
const bootstrapping = ref(true);
const predictions = ref<ApiV2Prediction[]>([]);
const availableSeasons = ref<number[]>([]);
const availableDates = ref<string[]>([]);
const selectedSeason = ref('');
const selectedDate = ref('');
const searchQuery = ref('');
const selectedFilter = ref<BoardFilter>('all');
const selectedPrediction = ref<ApiV2Prediction | null>(null);
const detailOpen = ref(false);
const showInsights = ref(false);

const boardFilters = computed(() => [
    { key: 'all' as const, label: 'All', count: predictions.value.length },
    { key: 'bets' as const, label: 'Bets', count: countFilter('bets') },
    {
        key: 'watchlist' as const,
        label: 'Watchlist',
        count: countFilter('watchlist'),
    },
    {
        key: 'spread' as const,
        label: 'Spread edges',
        count: countFilter('spread'),
    },
    { key: 'total' as const, label: 'Totals', count: countFilter('total') },
    {
        key: 'winner' as const,
        label: 'ML signals',
        count: countFilter('winner'),
    },
    { key: 'finals' as const, label: 'Finals', count: countFilter('finals') },
]);

const visibleBoardFilters = computed(() =>
    boardFilters.value.filter(
        (filter) =>
            filter.key === 'all' ||
            filter.key === selectedFilter.value ||
            filter.count > 0,
    ),
);

const normalizedSearch = computed(() => searchQuery.value.trim().toLowerCase());

const filteredPredictions = computed(() => {
    return predictions.value.filter((prediction) => {
        if (
            selectedFilter.value !== 'all' &&
            !filterMatches(prediction, selectedFilter.value)
        ) {
            return false;
        }

        if (!normalizedSearch.value) return true;

        return searchHaystack(prediction).includes(normalizedSearch.value);
    });
});

const quietEmptyState = computed(() => {
    if (predictions.value.length === 0) {
        return {
            title: 'No NFL board found for this date.',
            body: 'Choose another date or rerun the NFL sync and prediction pipeline.',
        };
    }

    if (selectedFilter.value !== 'all') {
        return {
            title: 'No matchups match this board filter.',
            body: 'Use All to review the full slate while the signal layer keeps scoring edges.',
        };
    }

    return {
        title: 'No matchups match this search.',
        body: 'Try a team abbreviation, city, or matchup code.',
    };
});

function record(value: unknown): ApiV2Record {
    return value && typeof value === 'object' ? (value as ApiV2Record) : {};
}

function proLayer(prediction: ApiV2Prediction): ApiV2Record {
    return record(prediction.pro_signal_layer);
}

function marketScores(prediction: ApiV2Prediction): ApiV2Record {
    return record(proLayer(prediction).market_scores);
}

function analysis(prediction: ApiV2Prediction): ApiV2Record {
    return record(prediction.prediction_analysis);
}

function classification(prediction: ApiV2Prediction): string {
    return String(
        analysis(prediction).bet_classification ??
            proLayer(prediction).bet_classification ??
            proLayer(prediction).classification ??
            '',
    )
        .trim()
        .toLowerCase();
}

function scoreTier(
    prediction: ApiV2Prediction,
    market: 'winner' | 'spread' | 'total',
): string {
    return String(record(marketScores(prediction)[market]).tier ?? '')
        .trim()
        .toLowerCase();
}

function filterMatches(
    prediction: ApiV2Prediction,
    filter: BoardFilter,
): boolean {
    const view = nflBoardPresentation(prediction);
    const spreadEdge = view.spreadEdge;
    const totalEdge = view.totalEdge;
    const cls = classification(prediction);

    if (filter === 'bets')
        return (
            cls === 'bet' &&
            view.researchStatus === 'reviewed' &&
            view.researchDecision === 'candidate'
        );
    if (filter === 'watchlist') {
        return (
            cls.includes('watchlist') ||
            cls === 'lean' ||
            scoreTier(prediction, 'winner') === 'watchlist'
        );
    }
    if (filter === 'spread') {
        return (
            scoreTier(prediction, 'spread') === 'watchlist' ||
            (spreadEdge !== null && Math.abs(spreadEdge) >= 2.5)
        );
    }
    if (filter === 'total') {
        return (
            scoreTier(prediction, 'total') === 'watchlist' ||
            (totalEdge !== null && Math.abs(totalEdge) >= 2)
        );
    }
    if (filter === 'winner') {
        return ['lean', 'watchlist', 'official_candidate'].includes(
            scoreTier(prediction, 'winner'),
        );
    }
    if (filter === 'finals') {
        return String(prediction.status ?? prediction.game?.status ?? '')
            .toLowerCase()
            .includes('final');
    }

    return true;
}

function countFilter(filter: BoardFilter): number {
    return predictions.value.filter((prediction) =>
        filterMatches(prediction, filter),
    ).length;
}

function teamText(team: unknown): string {
    const payload = record(team);

    return [
        payload.abbreviation,
        payload.short_display_name,
        payload.display_name,
        payload.location,
        payload.name,
    ]
        .filter((value) => typeof value === 'string' && value.length > 0)
        .join(' ');
}

function searchHaystack(prediction: ApiV2Prediction): string {
    return [
        prediction.game?.short_name,
        teamText(prediction.game?.away_team),
        teamText(prediction.game?.home_team),
        classification(prediction),
        scoreTier(prediction, 'winner'),
        scoreTier(prediction, 'spread'),
        scoreTier(prediction, 'total'),
        ...(Array.isArray(proLayer(prediction).reason_codes)
            ? (proLayer(prediction).reason_codes as unknown[]).map((code) =>
                  String(code),
              )
            : []),
    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();
}

function formatDateLabel(date: string): string {
    if (!date) return 'Select date';

    const [year, month, day] = date.split('-').map(Number);
    const displayDate = new Date(year, month - 1, day);

    return displayDate.toLocaleDateString('en-US', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    });
}

function todayKey(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

function gameSortKey(prediction: ApiV2Prediction): string {
    const game = prediction.game;
    const date = String(game?.game_date ?? '');
    const datePart = date.includes('T')
        ? date.split('T')[0]
        : date.split(' ')[0];
    const timePart = String(game?.game_time ?? '23:59:59');

    return `${datePart} ${timePart} ${game?.short_name ?? ''}`;
}

function selectMatchup(prediction: ApiV2Prediction): void {
    selectedPrediction.value = prediction;
    detailOpen.value = true;
}

async function fetchAvailableFilters(): Promise<void> {
    const seasonsPayload = await api.predictions.availableSeasons('nfl');
    availableSeasons.value = Array.isArray(seasonsPayload?.data)
        ? seasonsPayload.data
              .map((season) => Number(season))
              .filter((season) => Number.isFinite(season))
        : [];

    if (!selectedSeason.value && availableSeasons.value.length > 0) {
        const currentYear = new Date().getFullYear();
        selectedSeason.value = String(
            availableSeasons.value.includes(currentYear)
                ? currentYear
                : Math.max(...availableSeasons.value),
        );
    }

    await fetchAvailableDates();
}

async function fetchAvailableDates(): Promise<void> {
    const datesPayload = await api.predictions.availableDates('nfl', {
        query: selectedSeason.value ? { season: selectedSeason.value } : {},
    });

    availableDates.value = Array.isArray(datesPayload?.data)
        ? datesPayload.data
        : [];

    if (!selectedDate.value && availableDates.value.length > 0) {
        const today = todayKey();
        const futureDates = availableDates.value.filter((date) => date > today);
        const pastDates = availableDates.value.filter((date) => date < today);

        selectedDate.value = availableDates.value.includes(today)
            ? today
            : (futureDates[0] ??
              pastDates[pastDates.length - 1] ??
              availableDates.value[0]);
    }
}

async function loadBoard(): Promise<void> {
    if (!selectedDate.value) {
        predictions.value = [];
        loading.value = false;
        refreshing.value = false;

        return;
    }

    refreshing.value = true;
    error.value = null;

    try {
        const query = {
            from_date: selectedDate.value,
            to_date: selectedDate.value,
            page: 1,
            per_page: 100,
            ...(selectedSeason.value ? { season: selectedSeason.value } : {}),
        };

        const predictionPayload = await api.predictions.index('nfl', {
            query,
        });

        predictions.value = (
            (
                predictionPayload as ApiV2CollectionResponse<ApiV2Prediction> | null
            )?.data ?? []
        ).sort((a, b) => gameSortKey(a).localeCompare(gameSortKey(b)));
        if (selectedPrediction.value) {
            selectedPrediction.value =
                predictions.value.find(
                    (p) => p.id === selectedPrediction.value?.id,
                ) ?? null;
            if (!selectedPrediction.value) detailOpen.value = false;
        }
    } catch (e) {
        error.value =
            e instanceof Error ? e.message : 'Unable to load NFL board';
    } finally {
        refreshing.value = false;
        loading.value = false;
    }
}

async function refreshBoard(): Promise<void> {
    await loadBoard();
}

watch(selectedSeason, async () => {
    if (bootstrapping.value) return;

    selectedDate.value = '';
    await fetchAvailableDates();
    await loadBoard();
});

watch(selectedDate, async () => {
    if (bootstrapping.value) return;

    await loadBoard();
});

onMounted(async () => {
    try {
        await fetchAvailableFilters();
        await loadBoard();
    } catch (e) {
        error.value =
            e instanceof Error ? e.message : 'Unable to load NFL board';
        loading.value = false;
    } finally {
        bootstrapping.value = false;
    }
});
</script>

<template>
    <section class="space-y-5">
        <header>
            <h1 class="text-xl font-semibold">NFL predictions</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Model winners and spread leans. Research status is separate from
                bet approval.
            </p>
        </header>
        <div v-if="loading" class="space-y-4">
            <Skeleton class="h-20 w-full rounded-2xl" />
            <Skeleton class="h-28 w-full rounded-2xl" />
            <div class="grid gap-3 xl:grid-cols-2">
                <Skeleton class="h-44 rounded-2xl" />
                <Skeleton class="h-44 rounded-2xl" />
            </div>
        </div>

        <template v-else>
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
                                :disabled="refreshing"
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
                            <span class="ml-1 opacity-75">{{
                                filter.count
                            }}</span>
                        </button>
                    </div>
                </div>
            </section>

            <div
                v-if="error"
                class="rounded-2xl border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive"
            >
                {{ error }}
            </div>

            <section class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold">
                            {{ formatDateLabel(selectedDate) }} ·
                            {{ filteredPredictions.length }}
                            {{
                                filteredPredictions.length === 1
                                    ? 'game'
                                    : 'games'
                            }}
                        </h2>
                    </div>
                </div>

                <div
                    v-if="filteredPredictions.length === 0"
                    class="rounded-2xl border border-dashed bg-card/70 p-6"
                >
                    <div class="font-semibold">{{ quietEmptyState.title }}</div>
                    <p class="mt-1 text-sm leading-6 text-muted-foreground">
                        {{ quietEmptyState.body }}
                    </p>
                </div>

                <div v-else class="grid gap-3 xl:grid-cols-2">
                    <NflMatchupCard
                        v-for="prediction in filteredPredictions"
                        :key="prediction.id"
                        :prediction="prediction"
                        @select="selectMatchup"
                    />
                </div>
            </section>

            <section class="rounded-xl border bg-card">
                <button
                    type="button"
                    class="flex min-h-11 w-full items-center justify-between p-3 text-left text-sm font-medium"
                    :aria-expanded="showInsights"
                    aria-controls="nfl-more-insights"
                    @click="showInsights = !showInsights"
                >
                    More insights
                    <span aria-hidden="true">{{
                        showInsights ? '−' : '+'
                    }}</span>
                </button>
                <div
                    v-if="showInsights"
                    id="nfl-more-insights"
                    class="border-t p-3"
                >
                    <p class="mb-3 text-xs text-muted-foreground">
                        Season-wide context; not limited to the selected game
                        date.
                    </p>
                    <NflSignalsPanel
                        :key="selectedSeason"
                        :season="selectedSeason"
                    />
                </div>
            </section>

            <NflMatchupDetailDrawer
                v-model:open="detailOpen"
                :prediction="selectedPrediction"
            />
        </template>
    </section>
</template>
