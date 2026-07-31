<script setup lang="ts">
import { CalendarX2, FilterX } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
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

const setDefaultSeasonWeekFilters = () => {
    if (filterMode.value !== 'seasonWeek') {
        return;
    }

    seasonType.value = 'Regular Season';
    week.value = props.config.sport === 'cfb' ? '0' : '1';
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
    fetchPage: fetchPredictions,
} = usePredictionList<PredictionListItem>(async (page) => {
    if (filterMode.value === 'date' && !selectedDate.value) {
        return { data: [], meta: null };
    }

    const payload = await api.predictions.index(props.config.sport, {
        query: buildQuery(page),
    });

    if (!payload) throw new Error('Failed to fetch predictions');

    return {
        data: payload.data.map(mapV2Prediction),
        meta: payload.meta.pagination ?? null,
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
        const currentYear = new Date().getFullYear();
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
        value_signal:
            prediction.value_signal &&
            typeof prediction.value_signal === 'object'
                ? (prediction.value_signal as PredictionListItem['value_signal'])
                : null,
        recommendation: prediction.recommendation ?? null,
        game: {
            id: Number(game?.id ?? prediction.game_id ?? 0),
            game_date: game?.game_date ?? '',
            game_time: game?.game_time ?? undefined,
            status: prediction.status ?? game?.status ?? '',
            home_score: numberValue(game?.home_score),
            away_score: numberValue(game?.away_score),
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
        return;
    }

    if (filterMode.value === 'seasonWeek') {
        setDefaultSeasonWeekFilters();
        fetchPredictions(1);
    }
});

const applyFilters = () => {
    fetchPredictions(1);
};

const clearFilters = () => {
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
        setDefaultSeasonWeekFilters();
    } else {
        seasonType.value = '';
        week.value = '';
    }

    fetchPredictions(1);
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
        return 'Try clearing season/week filters to view the full board.';
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

        if (filterMode.value === 'seasonWeek') {
            setDefaultSeasonWeekFilters();
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
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-2xl font-bold">{{ config.title }}</h2>
                    <span
                        v-if="showSpringTrainingBadge"
                        class="rounded-full border border-amber-200 bg-amber-100 px-2.5 py-0.5 text-[11px] font-semibold tracking-wide text-amber-800 uppercase dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-300"
                    >
                        Spring Training
                    </span>
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ config.subtitle }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2 text-xs text-muted-foreground">
                <span class="rounded-full border px-2.5 py-1">
                    {{ filteredPredictions.length }} shown
                </span>
                <span class="rounded-full border px-2.5 py-1">
                    {{ recommendedBetCount }} bets
                </span>
            </div>
        </div>

        <Card>
            <CardContent class="p-4">
                <div
                    class="mb-3 flex flex-wrap items-center justify-between gap-2"
                >
                    <div>
                        <h3 class="text-sm font-semibold">Slate Controls</h3>
                        <p class="text-xs text-muted-foreground">
                            Filter the board, then open a game for full matchup
                            context.
                        </p>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="h-8"
                        :disabled="loading"
                        @click="clearFilters"
                    >
                        Reset
                    </Button>
                </div>
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
                    <div class="min-w-[220px] flex-1">
                        <Label for="prediction-search">Search Matchup</Label>
                        <Input
                            id="prediction-search"
                            v-model="searchQuery"
                            placeholder="Team, school, or mascot..."
                            class="mt-1"
                        />
                    </div>
                    <div class="min-w-[220px]">
                        <Label>Bet View</Label>
                        <div
                            class="mt-1 grid h-10 grid-cols-2 rounded-md border border-input bg-background p-1"
                        >
                            <button
                                type="button"
                                :class="[
                                    'rounded px-3 text-sm font-medium transition',
                                    betViewMode === 'recommended'
                                        ? 'bg-primary text-primary-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                ]"
                                @click="betViewMode = 'recommended'"
                            >
                                Bets
                                <span class="ml-1 text-xs opacity-80">
                                    {{ recommendedBetCount }}
                                </span>
                            </button>
                            <button
                                type="button"
                                :class="[
                                    'rounded px-3 text-sm font-medium transition',
                                    betViewMode === 'all'
                                        ? 'bg-primary text-primary-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                ]"
                                @click="betViewMode = 'all'"
                            >
                                All
                                <span class="ml-1 text-xs opacity-80">
                                    {{ predictions.length }}
                                </span>
                            </button>
                        </div>
                    </div>
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

        <div v-else-if="filteredPredictions.length > 0" class="grid gap-4">
            <template
                v-for="prediction in filteredPredictions"
                :key="prediction.id"
            >
                <UnifiedPredictionCard
                    :prediction="prediction"
                    :href="gameHref(prediction)"
                    :sport="config.sport"
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
