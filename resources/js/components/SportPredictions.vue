<script setup lang="ts">
import {
    CalendarX2,
    FilterX,
    SlidersHorizontal,
    Search,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { currentSlateDate, footballSeason } from '@/lib/predictionPeriod';
import { predictionBoardLiveFields } from '@/lib/predictionBoardLive';
import { pregameBetCalls } from '@/lib/pregameBetCalls';
import UnifiedPredictionCard from '@/components/predictions/UnifiedPredictionCard.vue';
import SeasonSelect from '@/components/SeasonSelect.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { usePredictionList } from '@/composables/usePredictionList';
import {
    isMlbRegularSeasonType,
    isMlbSpringTrainingType,
} from '@/lib/mlbSeasonType';
import { isBetRecommendation } from '@/lib/predictionRecommendation';
import type {
    ApiV2Prediction,
    ApiV2Query,
    ApiV2TeamSummary,
    PaginationMeta,
    PredictionListGameTeam,
    PredictionListItem,
    SportPredictionsConfig,
} from '@/types';

const props = defineProps<{
    config: SportPredictionsConfig;
}>();

const filterMode = computed(() => props.config.filterMode ?? 'date');
const availableDates = ref<string[]>([]);
const selectedDate = ref('');
const today = ref('');
const seasonType = ref('');
const week = ref('');
const searchQuery = ref('');
const showFilters = ref(false);
const centralToday = () =>
    new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/Chicago',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
const betViewMode = ref<'recommended' | 'all'>('all');
const isBootstrapping = ref(true);
const api = useApiV2Client();
const availableSeasons = ref<number[]>([]);
const selectedSeason = ref('');

const weekOptions = computed(() => {
    if (!props.config.seasonWeekConfig || !seasonType.value) return [];

    if (seasonType.value === 'Regular Season') {
        const regularWeeks = Array.from(
            { length: props.config.seasonWeekConfig.regularSeasonWeeks },
            (_, i) => ({
                value: String(i + 1),
                label: `Week ${i + 1}`,
            }),
        );

        return props.config.sport === 'cfb'
            ? [{ value: '0', label: 'Week 0' }, ...regularWeeks]
            : regularWeeks;
    }

    return props.config.seasonWeekConfig.postseasonOptions;
});

const setDefaultSeasonWeekFilters = async () => {
    if (filterMode.value !== 'seasonWeek') return;
    const dates = await api.predictions.availableDates(props.config.sport, {
        query: selectedSeason.value ? { season: selectedSeason.value } : {},
    });
    if (!dates)
        throw new Error('Unable to load the current week. Please retry.');
    const date = currentSlateDate(dates.data, centralToday());
    seasonType.value = '';
    week.value = '';
    if (!date) return;
    const slate = await api.predictions.index(props.config.sport, {
        query: {
            season: selectedSeason.value,
            from_date: date,
            to_date: date,
            per_page: 1,
        },
    });
    if (!slate)
        throw new Error('Unable to load the current week. Please retry.');
    const game = slate.data[0]?.game;
    if (!game) return;
    const type = String(game.season_type ?? '');
    seasonType.value = ['2', 'Regular Season', 'regular'].includes(type)
        ? 'Regular Season'
        : ['3', 'Postseason', 'postseason'].includes(type)
          ? 'Postseason'
          : '';
    week.value = game.week == null ? '' : String(game.week);
};

const buildQuery = (page: number): ApiV2Query => {
    const params: ApiV2Query = {
        page,
    };

    if (filterMode.value === 'date' && selectedDate.value) {
        params.from_date = selectedDate.value;
        params.to_date = selectedDate.value;
    }

    if (selectedSeason.value) {
        params.season = selectedSeason.value;
    }

    if (filterMode.value === 'seasonWeek') {
        if (seasonType.value) {
            const mappedSeasonType = mapSeasonTypeParam(seasonType.value);
            if (mappedSeasonType) {
                params.season_type = mappedSeasonType;
            }
        }
        if (week.value !== '') params.week = week.value;
    }

    return params;
};

const mapSeasonTypeParam = (value: string): string => {
    if (value === 'Regular Season') {
        return '2';
    }

    if (value === 'Postseason') {
        return '3';
    }

    return value;
};

const {
    items: predictions,
    meta,
    loading,
    error,
    refreshError,
    fetchPage: fetchPredictions,
} = usePredictionList<PredictionListItem>(async (page) => {
    if (filterMode.value === 'date' && !selectedDate.value) {
        return { data: [], meta: null };
    }

    const payload = await api.predictions.index(props.config.sport, {
        query: buildQuery(page),
        init: { cache: 'no-store' },
    });

    if (!payload) throw new Error('Failed to fetch predictions');

    return {
        data: payload.data.map(mapV2Prediction),
        meta:
            (payload.meta.pagination as unknown as
                | PaginationMeta
                | undefined) ?? null,
    };
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

const fetchAvailableSeasons = async () => {
    const payload = await api.predictions.availableSeasons(props.config.sport);

    if (!payload) {
        throw new Error('Failed to fetch available seasons');
    }

    availableSeasons.value = Array.isArray(payload.data)
        ? payload.data
              .map((season) => Number(season))
              .filter((season) => Number.isFinite(season))
        : [];

    if (!selectedSeason.value && availableSeasons.value.length > 0) {
        const currentYear =
            filterMode.value === 'seasonWeek'
                ? footballSeason(centralToday())
                : new Date().getFullYear();
        const preferredSeason = availableSeasons.value.includes(currentYear)
            ? currentYear
            : Math.max(...availableSeasons.value);

        selectedSeason.value = String(preferredSeason);
    }
};

const fetchAvailableDates = async () => {
    const data = await api.predictions.availableDates(props.config.sport, {
        query: selectedSeason.value ? { season: selectedSeason.value } : {},
    });

    if (!data) throw new Error('Failed to fetch available dates');
    availableDates.value = Array.isArray(data.data) ? data.data : [];

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

const numberValue = (value: unknown): number | undefined => {
    if (value === null || value === undefined || value === '') {
        return undefined;
    }

    const numeric = Number(value);

    return Number.isFinite(numeric) ? numeric : undefined;
};

const teamPayload = (
    team: ApiV2TeamSummary | null | undefined,
): PredictionListGameTeam => ({
    abbreviation: team?.abbreviation ?? team?.short_display_name ?? '',
    school: team?.location ?? undefined,
    mascot: team?.name ?? undefined,
    location: team?.location ?? undefined,
    name: team?.display_name ?? team?.name ?? undefined,
    logo: team?.logo_url ?? undefined,
});

const mapV2Prediction = (prediction: ApiV2Prediction): PredictionListItem => {
    const projection = prediction.projection ?? {};
    const game = prediction.game;
    const homeWinProbability = numberValue(projection.home_win_probability);

    return {
        id: Number(prediction.id),
        ...predictionBoardLiveFields(prediction),
        game_id: numberValue(prediction.game_id),
        predicted_spread: numberValue(projection.predicted_spread),
        predicted_total: numberValue(projection.predicted_total),
        win_probability: homeWinProbability,
        home_win_probability: homeWinProbability,
        away_win_probability: numberValue(projection.away_win_probability),
        confidence_score: numberValue(projection.confidence_score),
        actual_spread: numberValue(prediction.actual_spread),
        actual_total: numberValue(prediction.actual_total),
        spread_error: numberValue(prediction.spread_error),
        total_error: numberValue(prediction.total_error),
        winner_correct:
            typeof prediction.winner_correct === 'boolean'
                ? prediction.winner_correct
                : undefined,
        graded_at:
            typeof prediction.graded_at === 'string'
                ? prediction.graded_at
                : undefined,
        created_at: prediction.created_at,
        updated_at: prediction.updated_at,
        model_bet_context: prediction.model_bet_context,
        value_signal:
            prediction.value_signal &&
            typeof prediction.value_signal === 'object'
                ? (prediction.value_signal as unknown as PredictionListItem['value_signal'])
                : null,
        cfb_signal_context:
            prediction.cfb_signal_context &&
            typeof prediction.cfb_signal_context === 'object'
                ? prediction.cfb_signal_context
                : null,
        recommendation: prediction.recommendation ?? null,
        game: {
            id: Number(game?.id ?? prediction.game_id ?? 0),
            game_date: game?.game_date ?? '',
            game_time: game?.game_time ?? undefined,
            status: prediction.status ?? game?.status ?? '',
            home_score: numberValue(game?.home_score),
            away_score: numberValue(game?.away_score),
            period: numberValue(game?.period),
            clock: typeof game?.clock === 'string' ? game.clock : undefined,
            inning: numberValue(game?.inning) ?? null,
            inning_half:
                typeof game?.inning_half === 'string' ? game.inning_half : null,
            balls: numberValue(game?.balls) ?? null,
            strikes: numberValue(game?.strikes) ?? null,
            outs: numberValue(game?.outs) ?? null,
            week: numberValue(game?.week),
            season_type:
                game?.season_type === null || game?.season_type === undefined
                    ? undefined
                    : String(game.season_type),
            home_team: teamPayload(game?.home_team),
            away_team: teamPayload(game?.away_team),
        },
    };
};

watch(selectedDate, () => {
    if (!isBootstrapping.value && filterMode.value === 'date') {
        fetchPredictions(1);
    }
});

watch(
    seasonType,
    () => {
        week.value = '';
    },
    { flush: 'sync' },
);

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
        return;
    }

    if (filterMode.value === 'seasonWeek') {
        try {
            loading.value = true;
            await setDefaultSeasonWeekFilters();
            await fetchPredictions(1);
        } catch (e) {
            error.value =
                e instanceof Error ? e.message : 'Unable to load week';
        } finally {
            loading.value = false;
        }
    }
});

const applyFilters = () => {
    fetchPredictions(1);
};

const clearFilters = async () => {
    searchQuery.value = '';
    betViewMode.value = 'all';

    if (filterMode.value === 'date') {
        if (availableDates.value.includes(today.value)) {
            selectedDate.value = today.value;
        } else if (availableDates.value.length > 0) {
            selectedDate.value = availableDates.value[0];
        }
        return;
    }

    if (filterMode.value === 'seasonWeek') {
        const current = footballSeason(centralToday());
        const preferred = availableSeasons.value.includes(current)
            ? current
            : Math.max(...availableSeasons.value);
        if (
            Number.isFinite(preferred) &&
            selectedSeason.value !== String(preferred)
        ) {
            selectedSeason.value = String(preferred);
            return;
        }
        try {
            loading.value = true;
            await setDefaultSeasonWeekFilters();
            await fetchPredictions(1);
        } catch (e) {
            error.value =
                e instanceof Error ? e.message : 'Unable to load week';
        } finally {
            loading.value = false;
        }
        return;
    }
    seasonType.value = '';
    week.value = '';
    await fetchPredictions(1);
};

const hasAppliedSeasonWeekFilters = computed(() => {
    if (filterMode.value !== 'seasonWeek') return false;
    return seasonType.value !== '' || week.value !== '';
});

const normalizedSearchQuery = computed(() =>
    searchQuery.value.trim().toLowerCase(),
);

const shouldShowAsRecommendedBet = (
    prediction: PredictionListItem,
): boolean => {
    if (prediction.model_bet_context != null) {
        return Object.values(pregameBetCalls(prediction, 'Home', 'Away')).some(
            (call) => call.startsWith('Bet '),
        );
    }

    if (props.config.sport === 'mlb') {
        return isBetRecommendation(prediction);
    }

    if (prediction.betting_value_summary?.has_playable_value === true) {
        return true;
    }

    if (prediction.value_signal?.has_playable_value === true) {
        return true;
    }

    if (
        props.config.sport !== 'nfl' &&
        prediction.betting_value?.some(
            (recommendation) => Number(recommendation.edge) > 0,
        )
    ) {
        return true;
    }

    const analysisClassification =
        prediction.prediction_analysis?.bet_classification
            ?.toLowerCase()
            .trim() ?? '';
    if (
        [
            'bet',
            'bettable_edge',
            'playable',
            'strong_play',
            'recommended',
        ].includes(analysisClassification)
    ) {
        return true;
    }

    const aiClassification = prediction.ai_analysis?.bet_classification
        ?.toLowerCase()
        .trim();

    return aiClassification === 'bet';
};

const recommendedBetCount = computed(
    () => predictions.value.filter(shouldShowAsRecommendedBet).length,
);

const filteredPredictions = computed(() => {
    let visiblePredictions = predictions.value;

    if (betViewMode.value === 'recommended') {
        visiblePredictions = visiblePredictions.filter(
            shouldShowAsRecommendedBet,
        );
    }

    if (!normalizedSearchQuery.value) {
        return visiblePredictions;
    }

    return visiblePredictions.filter((prediction) => {
        const game = prediction.game;
        const haystack = [
            game?.home_team?.abbreviation,
            game?.home_team?.school,
            game?.home_team?.mascot,
            game?.home_team?.location,
            game?.home_team?.name,
            game?.away_team?.abbreviation,
            game?.away_team?.school,
            game?.away_team?.mascot,
            game?.away_team?.location,
            game?.away_team?.name,
        ]
            .filter((value): value is string => Boolean(value))
            .join(' ')
            .toLowerCase();

        return haystack.includes(normalizedSearchQuery.value);
    });
});

const showSpringTrainingBadge = computed(() => {
    if (props.config.sport !== 'mlb' || predictions.value.length === 0) {
        return false;
    }

    // Keep the badge visible until this MLB slate is regular season.
    const hasSpringTraining = predictions.value.some((prediction) =>
        isMlbSpringTrainingType(prediction.game?.season_type),
    );
    if (hasSpringTraining) {
        return true;
    }

    return predictions.value.some(
        (prediction) => !isMlbRegularSeasonType(prediction.game?.season_type),
    );
});

const emptyStateTitle = computed(() => {
    if (betViewMode.value === 'recommended') {
        return 'No recommended bets match these filters';
    }

    if (normalizedSearchQuery.value) {
        return 'No predictions match this search';
    }

    if (filterMode.value === 'date' && availableDates.value.length === 0) {
        return 'No prediction dates available yet';
    }

    if (
        filterMode.value === 'seasonWeek' &&
        hasAppliedSeasonWeekFilters.value
    ) {
        return 'No predictions match these filters';
    }

    return 'No predictions available';
});

const emptyStateDescription = computed(() => {
    if (betViewMode.value === 'recommended') {
        return 'The model does not currently have a playable edge on this slate. Switch to All games to review every prediction.';
    }

    if (normalizedSearchQuery.value) {
        return `No games matched "${searchQuery.value}". Try team abbreviations, school names, or mascots.`;
    }

    if (filterMode.value === 'date' && availableDates.value.length === 0) {
        return 'We have not generated a slate for this sport yet. Run sync + prediction jobs, then refresh.';
    }

    if (filterMode.value === 'date' && selectedDate.value) {
        return `No games are available for ${formatDateLabel(selectedDate.value)}. Try another date.`;
    }

    if (
        filterMode.value === 'seasonWeek' &&
        hasAppliedSeasonWeekFilters.value
    ) {
        return 'Choose another week or return to the current week.';
    }

    return 'Check back after model runs complete.';
});

const showEmptyClearAction = computed(() => {
    if (betViewMode.value === 'recommended') return true;
    if (normalizedSearchQuery.value) return true;
    if (filterMode.value === 'seasonWeek')
        return hasAppliedSeasonWeekFilters.value;
    if (filterMode.value === 'date') return availableDates.value.length > 0;
    return false;
});

const gameHref = (prediction: PredictionListItem): string => {
    const gameId = prediction.game?.id ?? prediction.game_id;
    return `/${props.config.sport}/games/${gameId}`;
};

let refreshTimer: ReturnType<typeof setInterval> | undefined;
const refreshBoard = () => {
    if (
        props.config.sport !== 'cfb' ||
        document.hidden ||
        isBootstrapping.value
    )
        return;
    // Include scheduled games so the board transitions into live without a reload.
    if (predictions.value.every((p) => p.game.status === 'STATUS_FINAL'))
        return;
    void fetchPredictions(meta.value?.current_page ?? 1, true);
};
onMounted(async () => {
    refreshTimer = setInterval(refreshBoard, 30000);
    document.addEventListener('visibilitychange', refreshBoard);
    try {
        await fetchAvailableSeasons();

        if (filterMode.value === 'date') {
            await fetchAvailableDates();
        } else if (filterMode.value === 'seasonWeek') {
            await setDefaultSeasonWeekFilters();
        }

        isBootstrapping.value = false;
        await fetchPredictions(1);
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'An error occurred';
    } finally {
        isBootstrapping.value = false;
        loading.value = false;
    }
});
onBeforeUnmount(() => {
    if (refreshTimer) clearInterval(refreshTimer);
    document.removeEventListener('visibilitychange', refreshBoard);
});
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
