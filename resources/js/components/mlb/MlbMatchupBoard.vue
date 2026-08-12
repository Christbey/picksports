<script setup lang="ts">
import { CalendarDays, RefreshCw, Search } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import MlbMatchupCard from '@/components/mlb/MlbMatchupCard.vue';
import MlbMatchupDetailDrawer from '@/components/mlb/MlbMatchupDetailDrawer.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useApiV2Client } from '@/composables/useApiV2Client';
import {
    candidateRecommendation,
    getPredictionRecommendation,
} from '@/lib/predictionRecommendation';
import type {
    ApiV2CollectionResponse,
    ApiV2Prediction,
    ApiV2Record,
} from '@/types';
import type {
    MlbDailyPick,
    MlbDailyPicksPayload,
} from '@/types/mlb-daily-picks';

type MarketFilter =
    | 'all'
    | 'candidates'
    | 'moneyline'
    | 'run_line'
    | 'total'
    | 'first_inning'
    | 'first_5'
    | 'first_3'
    | 'player_prop'
    | 'tracked';

const api = useApiV2Client();

const loading = ref(true);
const refreshing = ref(false);
const error = ref<string | null>(null);
const bootstrapping = ref(true);
const predictions = ref<ApiV2Prediction[]>([]);
const dailyPayload = ref<MlbDailyPicksPayload['data'] | null>(null);
const availableSeasons = ref<number[]>([]);
const availableDates = ref<string[]>([]);
const selectedSeason = ref('');
const selectedDate = ref('');
const searchQuery = ref('');
const selectedFilter = ref<MarketFilter>('all');
const selectedPrediction = ref<ApiV2Prediction | null>(null);
const selectedCandidate = ref<MlbDailyPick | null>(null);
const selectedCandidates = ref<MlbDailyPick[]>([]);
const detailOpen = ref(false);
const detailLoading = ref(false);
const detailError = ref<string | null>(null);

const allCandidates = computed(() => dailyPayload.value?.candidates ?? []);
const summary = computed(() => dailyPayload.value?.summary ?? null);

const boardStats = computed(() => [
    {
        label: 'Games',
        value: summary.value?.slate_games ?? predictions.value.length,
    },
    {
        label: 'Priced',
        value: summary.value?.priced_games ?? 0,
    },
    {
        label: 'F3 priced',
        value: summary.value?.first_3_priced_games ?? 0,
    },
    {
        label: 'F5 priced',
        value: summary.value?.first_5_priced_games ?? 0,
    },
    {
        label: 'Candidates',
        value: summary.value?.candidate_count ?? candidateByGameId.value.size,
    },
    {
        label: 'Visible',
        value: filteredPredictions.value.length,
    },
]);

const visibleBoardStats = computed(() =>
    boardStats.value.filter(
        (stat) =>
            stat.label === 'Games' ||
            stat.label === 'Visible' ||
            stat.value > 0,
    ),
);

const candidatesByGameId = computed(() => {
    const map = new Map<number, MlbDailyPick[]>();

    for (const candidate of allCandidates.value) {
        const candidates = map.get(candidate.game_id) ?? [];
        candidates.push(candidate);
        map.set(candidate.game_id, candidates);
    }

    return map;
});

const candidateByGameId = computed(() => {
    const map = new Map<number, MlbDailyPick>();

    for (const [gameId, candidates] of candidatesByGameId.value) {
        const candidate = candidates[0];

        if (candidate) {
            map.set(gameId, candidate);
        }
    }

    return map;
});

const marketFilters = computed(() => [
    { key: 'all' as const, label: 'All', count: predictions.value.length },
    {
        key: 'candidates' as const,
        label: 'Candidates',
        count: candidateByGameId.value.size,
    },
    {
        key: 'moneyline' as const,
        label: 'Moneyline',
        count: countMarket('moneyline'),
    },
    {
        key: 'run_line' as const,
        label: 'Run Line',
        count: countMarket('run_line'),
    },
    { key: 'total' as const, label: 'Totals', count: countMarket('total') },
    {
        key: 'first_inning' as const,
        label: '1st Inning',
        count: countMarket('first_inning'),
    },
    { key: 'first_5' as const, label: 'F5', count: countMarket('first_5') },
    { key: 'first_3' as const, label: 'F3', count: countMarket('first_3') },
    {
        key: 'player_prop' as const,
        label: 'Props',
        count: countMarket('player_prop'),
    },
    {
        key: 'tracked' as const,
        label: 'Tracked',
        count: allCandidates.value.filter(
            (candidate) => candidate.is_tracking_only,
        ).length,
    },
]);

const visibleMarketFilters = computed(() =>
    marketFilters.value.filter(
        (filter) => filter.key === 'all' || filter.count > 0,
    ),
);

const normalizedSearch = computed(() => searchQuery.value.trim().toLowerCase());

const filteredPredictions = computed(() => {
    return predictions.value.filter((prediction) => {
        const candidate = candidateForPrediction(
            prediction,
            selectedFilter.value,
        );

        if (selectedFilter.value === 'candidates' && !candidate) {
            return false;
        }

        if (selectedFilter.value === 'tracked') {
            if (!candidate?.is_tracking_only) return false;
        } else if (!['all', 'candidates'].includes(selectedFilter.value)) {
            if (!candidate) return false;
        }

        if (!normalizedSearch.value) return true;

        return searchHaystack(prediction, candidate).includes(
            normalizedSearch.value,
        );
    });
});

const quietEmptyState = computed(() => {
    if (predictions.value.length === 0) {
        return {
            title: 'No matchup board found for this date.',
            body: 'Choose another date or rerun the MLB sync and prediction pipeline.',
        };
    }

    if (selectedFilter.value !== 'all') {
        return {
            title: 'No matchups match this market filter.',
            body: 'Use All to review the full slate while the candidate engine keeps scoring markets.',
        };
    }

    return {
        title: 'No matchups match this search.',
        body: 'Try a team abbreviation, city, or matchup code.',
    };
});

function numberValue(value: unknown): number | null {
    if (value === null || value === undefined || value === '') return null;

    const numeric = Number(value);

    return Number.isFinite(numeric) ? numeric : null;
}

function candidateForPrediction(
    prediction: ApiV2Prediction,
    filter: MarketFilter = selectedFilter.value,
): MlbDailyPick | null {
    const gameId = numberValue(prediction.game_id ?? prediction.game?.id);

    if (gameId == null) return null;

    const candidates = candidatesByGameId.value.get(gameId) ?? [];

    if (filter === 'tracked') {
        return (
            candidates.find((candidate) => candidate.is_tracking_only) ?? null
        );
    }

    if (!['all', 'candidates'].includes(filter)) {
        return (
            candidates.find((candidate) =>
                marketMatches(prediction, candidate, filter),
            ) ?? null
        );
    }

    return candidates[0] ?? null;
}

function predictionMarketType(prediction: ApiV2Prediction): string {
    return String(
        candidateRecommendation(prediction)?.market_type ??
            getPredictionRecommendation(prediction)?.market_type ??
            prediction.market_aware_projection?.label ??
            '',
    ).toLowerCase();
}

function marketMatches(
    prediction: ApiV2Prediction | null,
    candidate: MlbDailyPick | null,
    filter: MarketFilter,
): boolean {
    const marketType = (
        candidate?.market_type ??
        (prediction ? predictionMarketType(prediction) : '')
    )
        .toLowerCase()
        .replaceAll('-', '_')
        .replaceAll(' ', '_');

    if (filter === 'total') return marketType.includes('total');
    if (filter === 'first_inning') return marketType.includes('first_inning');
    if (filter === 'first_5')
        return marketType.includes('first_5') || marketType.includes('f5');
    if (filter === 'first_3')
        return marketType.includes('first_3') || marketType.includes('f3');
    if (filter === 'player_prop') return marketType.includes('prop');

    return marketType.includes(filter);
}

function countMarket(filter: MarketFilter): number {
    const gameIds = new Set(
        allCandidates.value
            .filter((candidate) => marketMatches(null, candidate, filter))
            .map((candidate) => candidate.game_id),
    );

    return gameIds.size;
}

function teamText(team: unknown): string {
    const payload = (team ?? {}) as ApiV2Record;

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

function searchHaystack(
    prediction: ApiV2Prediction,
    candidate: MlbDailyPick | null,
): string {
    return [
        prediction.game?.short_name,
        teamText(prediction.game?.away_team),
        teamText(prediction.game?.home_team),
        candidate?.label,
        candidate?.side,
        candidate?.market_type,
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

async function selectMatchup(
    prediction: ApiV2Prediction,
    candidate: MlbDailyPick | null,
): Promise<void> {
    selectedPrediction.value = prediction;
    selectedCandidate.value = candidate;
    const gameId = numberValue(prediction.game_id ?? prediction.game?.id);
    selectedCandidates.value =
        gameId === null ? [] : (candidatesByGameId.value.get(gameId) ?? []);
    detailOpen.value = true;
    detailError.value = null;

    if (gameId === null) return;

    detailLoading.value = true;

    try {
        const payload = await api.dailyPicks.index<MlbDailyPicksPayload>(
            'mlb',
            {
                query: {
                    date: selectedDate.value,
                    game_id: gameId,
                    ...(selectedSeason.value
                        ? { season: selectedSeason.value }
                        : {}),
                },
            },
        );

        if (!payload?.data) {
            detailError.value =
                'Full market and signal details are temporarily unavailable.';

            return;
        }

        if (
            numberValue(
                selectedPrediction.value?.game_id ??
                    selectedPrediction.value?.game?.id,
            ) !== gameId
        ) {
            return;
        }

        selectedCandidates.value = payload.data.candidates;
        selectedCandidate.value =
            payload.data.candidates.find(
                (option) => option.id === candidate?.id,
            ) ??
            payload.data.candidates[0] ??
            null;
    } catch (e) {
        detailError.value =
            e instanceof Error
                ? e.message
                : 'Full market and signal details are temporarily unavailable.';
    } finally {
        detailLoading.value = false;
    }
}

async function fetchAvailableFilters(): Promise<void> {
    const seasonsPayload = await api.predictions.availableSeasons('mlb');
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
    const datesPayload = await api.predictions.availableDates('mlb', {
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
    if (!selectedDate.value) return;

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

        const [predictionPayload, picksPayload] = await Promise.all([
            api.predictions.index('mlb', { query }),
            api.dailyPicks.index<MlbDailyPicksPayload>('mlb', {
                query: {
                    date: selectedDate.value,
                    compact: 1,
                    ...(selectedSeason.value
                        ? { season: selectedSeason.value }
                        : {}),
                },
            }),
        ]);

        if (!predictionPayload) {
            throw new Error('Unable to load MLB predictions.');
        }

        const periodModelsByGame =
            picksPayload?.data?.period_models_by_game ?? {};
        predictions.value = (
            (
                predictionPayload as ApiV2CollectionResponse<ApiV2Prediction> | null
            )?.data ?? []
        )
            .map((prediction) => {
                const gameId = numberValue(
                    prediction.game_id ?? prediction.game?.id,
                );

                return {
                    ...prediction,
                    period_models:
                        gameId === null
                            ? []
                            : (periodModelsByGame[String(gameId)] ?? []),
                };
            })
            .sort((a, b) => gameSortKey(a).localeCompare(gameSortKey(b)));
        dailyPayload.value = picksPayload?.data ?? null;
        if (!picksPayload) {
            error.value =
                'Candidate details could not be loaded. Sportsbook availability shown on each matchup still comes from the prediction market snapshot.';
        }
    } catch (e) {
        error.value =
            e instanceof Error ? e.message : 'Unable to load MLB board';
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
            e instanceof Error ? e.message : 'Unable to load MLB board';
        loading.value = false;
    } finally {
        bootstrapping.value = false;
    }
});
</script>

<template>
    <section class="space-y-5">
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
                        <div class="grid gap-2 sm:grid-cols-[180px_140px]">
                            <label class="relative">
                                <CalendarDays
                                    class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                                />
                                <select
                                    v-model="selectedDate"
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

                        <div
                            class="grid gap-2 sm:grid-cols-[minmax(180px,1fr)_auto]"
                        >
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
                        <h2 class="text-xl font-bold tracking-normal">
                            Matchups
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            One card per game. Open a matchup for model, market,
                            reason, and risk detail.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="stat in visibleBoardStats"
                            :key="stat.label"
                            class="rounded-full border bg-card px-3 py-1 text-xs font-semibold text-muted-foreground"
                        >
                            {{ stat.label }} {{ stat.value }}
                        </span>
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
                    <MlbMatchupCard
                        v-for="prediction in filteredPredictions"
                        :key="prediction.id"
                        :prediction="prediction"
                        :candidate="
                            candidateForPrediction(prediction, selectedFilter)
                        "
                        :candidate-count="
                            candidatesByGameId.get(
                                numberValue(
                                    prediction.game_id ?? prediction.game?.id,
                                ) ?? -1,
                            )?.length ?? 0
                        "
                        @select="selectMatchup"
                    />
                </div>
            </section>

            <MlbMatchupDetailDrawer
                v-model:open="detailOpen"
                :prediction="selectedPrediction"
                :candidate="selectedCandidate"
                :candidates="selectedCandidates"
                :loading-details="detailLoading"
                :detail-error="detailError"
                @select-candidate="selectedCandidate = $event"
            />
        </template>
    </section>
</template>
