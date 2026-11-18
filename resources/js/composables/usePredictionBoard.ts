import { usePredictionBoardView } from '@/composables/usePredictionBoardView';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
    toRefs,
    inject,
    type InjectionKey,
} from 'vue';
import { todayKey, boardDateOptions } from '@/lib/matchupBoardPresentation';
import { currentSlateDate, footballSeason } from '@/lib/predictionPeriod';
import { createBoardDelivery } from '@/lib/predictionBoardDelivery';
import { mapV2Prediction } from '@/lib/predictionBoardPresentation';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { usePredictionList } from '@/composables/usePredictionList';
import type {
    ApiV2GameSummary,
    ApiV2Meta,
    ApiV2Query,
    PaginationMeta,
    PredictionListItem,
    SportPredictionsConfig,
} from '@/types';
export type PredictionsBoardProps = { config: SportPredictionsConfig };

export function usePredictionBoard(props: PredictionsBoardProps) {
    const filterMode = computed(() => props.config.filterMode ?? 'date');
    const usesSingleRequestStartup = computed(
        () => props.config.sport === 'cfb' && filterMode.value === 'seasonWeek',
    );
    const availableDates = ref<string[]>([]);
    const selectedDate = ref('');
    const today = ref('');
    const seasonType = ref('');
    const week = ref('');
    const searchQuery = ref('');
    const showFilters = ref(false);
    const centralToday = todayKey;
    const betViewMode = ref<'recommended' | 'all'>('all');
    const isBootstrapping = ref(true);
    const api = useApiV2Client();
    const availableSeasons = ref<number[]>([]);
    const selectedSeason = ref('');
    let initialized = false;
    let periodRequest = 0;
    let periodLoading = false;
    let periodReady = false;
    let disposed = false;

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

    const setSeasonWeek = (
        game?: Pick<ApiV2GameSummary, 'season_type' | 'week'> | null,
    ) => {
        const type = String(game?.season_type ?? '');
        seasonType.value = ['2', 'Regular Season', 'regular'].includes(type)
            ? 'Regular Season'
            : ['3', 'Postseason', 'postseason'].includes(type)
              ? 'Postseason'
              : '';
        week.value = game?.week == null ? '' : String(game.week);
    };

    const setDefaultSeasonWeekFilters = async (active: () => boolean) => {
        const season = selectedSeason.value;
        if (filterMode.value !== 'seasonWeek') return;
        const dates = await api.predictions.availableDates(props.config.sport, {
            query: season ? { season } : {},
        });
        if (!active()) return;
        if (!dates)
            throw new Error('Unable to load the current week. Please retry.');
        const date = currentSlateDate(dates.data, centralToday());
        setSeasonWeek();
        if (!date) return;
        const slate = await api.predictions.index(props.config.sport, {
            query: {
                season,
                from_date: date,
                to_date: date,
                per_page: 1,
            },
        });
        if (!active()) return;
        if (!slate)
            throw new Error('Unable to load the current week. Please retry.');
        setSeasonWeek(slate.data[0]?.game);
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

    const resolveStartup = (page: number, meta: ApiV2Meta): ApiV2Query => {
        const startup = meta.board_startup;
        if (!Array.isArray(startup?.seasons) || !startup.period)
            throw new Error('Unable to load the current week. Please retry.');

        availableSeasons.value = startup.seasons;
        selectedSeason.value =
            startup.period.season == null ? '' : String(startup.period.season);
        setSeasonWeek(startup.period);
        initialized = true;

        return buildQuery(page);
    };

    const loadBoard = createBoardDelivery(
        (query) =>
            api.predictions.index(props.config.sport, {
                query,
                init: { cache: 'no-store' },
            }),
        () => props.config.sport === 'cfb',
    );

    const {
        items: predictions,
        meta,
        loading,
        error,
        refreshError,
        refreshing,
        retryPage,
        reset: resetPredictions,
        fetchPage: fetchPredictions,
    } = usePredictionList<PredictionListItem>(
        async (page, background = false) => {
            if (filterMode.value === 'date' && !selectedDate.value) {
                return { data: [], meta: null };
            }
            const payload =
                !initialized && usesSingleRequestStartup.value
                    ? await loadBoard({ page }, false, (meta) =>
                          resolveStartup(page, meta),
                      )
                    : await loadBoard(buildQuery(page), background);
            if (!payload) throw new Error('Failed to fetch predictions');
            return {
                data: payload.data.map(mapV2Prediction),
                meta:
                    (payload.meta.pagination as unknown as
                        | PaginationMeta
                        | undefined) ?? null,
            };
        },
    );

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
        const payload = await api.predictions.availableSeasons(
            props.config.sport,
        );

        if (!payload) {
            throw new Error('Failed to fetch available seasons');
        }

        availableSeasons.value = Array.isArray(payload.data)
            ? payload.data
                  .map((season) => Number(season))
                  .filter((season) => Number.isFinite(season))
            : [];

        if (!selectedSeason.value && availableSeasons.value.length > 0) {
            const currentYear = ['cfb', 'nfl'].includes(props.config.sport)
                ? footballSeason(centralToday())
                : Number(centralToday().slice(0, 4));
            const preferredSeason = availableSeasons.value.includes(currentYear)
                ? currentYear
                : Math.max(...availableSeasons.value);

            selectedSeason.value = String(preferredSeason);
        }
    };

    const fetchAvailableDates = async (active: () => boolean) => {
        const season = selectedSeason.value;
        const data = await api.predictions.availableDates(props.config.sport, {
            query: season ? { season } : {},
        });

        if (!active()) return;
        if (!data) throw new Error('Failed to fetch available dates');
        availableDates.value = boardDateOptions(
            Array.isArray(data.data) ? data.data : [],
        );
        today.value = centralToday();
        selectedDate.value = today.value;
    };

    watch(
        selectedDate,
        () => {
            if (
                !isBootstrapping.value &&
                !periodLoading &&
                periodReady &&
                filterMode.value === 'date'
            ) {
                fetchPredictions(1);
            }
        },
        { flush: 'sync' },
    );

    watch(
        seasonType,
        () => {
            week.value = '';
        },
        { flush: 'sync' },
    );

    const loadPeriod = async () => {
        const request = ++periodRequest;
        const active = () => !disposed && request === periodRequest;
        periodLoading = true;
        periodReady = false;
        resetPredictions();
        availableDates.value = [];
        selectedDate.value = '';
        try {
            if (filterMode.value === 'date') {
                await fetchAvailableDates(active);
            } else if (filterMode.value === 'seasonWeek') {
                await setDefaultSeasonWeekFilters(active);
            }
            if (!active()) return;
            periodReady = true;
            periodLoading = false;
            await fetchPredictions(1);
        } catch (e) {
            if (active()) {
                error.value =
                    e instanceof Error ? e.message : 'Unable to load season';
            }
        } finally {
            if (active()) {
                periodLoading = false;
                if (!periodReady) loading.value = false;
            }
        }
    };

    watch(
        selectedSeason,
        () => {
            if (!isBootstrapping.value) void loadPeriod();
        },
        { flush: 'sync' },
    );

    const applyFilters = () => {
        if (periodReady && !periodLoading) void fetchPredictions(1);
    };

    const clearFilters = async () => {
        searchQuery.value = '';
        betViewMode.value = 'all';

        if (filterMode.value === 'date') {
            today.value = centralToday();
            availableDates.value = boardDateOptions(availableDates.value);
            selectedDate.value = today.value;
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
            await loadPeriod();
            return;
        }
        seasonType.value = '';
        week.value = '';
        await fetchPredictions(1);
    };

    const {
        recommendedBetCount,
        filteredPredictions,
        showSpringTrainingBadge,
        emptyStateTitle,
        emptyStateDescription,
        showEmptyClearAction,
    } = usePredictionBoardView(props, {
        predictions,
        filterMode,
        searchQuery,
        betViewMode,
        availableDates,
        selectedDate,
        seasonType,
        week,
        formatDateLabel,
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
            isBootstrapping.value ||
            periodLoading ||
            !periodReady
        )
            return;
        // Include scheduled games so the board transitions into live without a reload.
        if (predictions.value.every((p) => p.game.status === 'STATUS_FINAL'))
            return;
        void fetchPredictions(meta.value?.current_page ?? 1, true);
    };
    const initializeBoard = async () => {
        isBootstrapping.value = true;
        loading.value = true;
        error.value = null;
        try {
            if (usesSingleRequestStartup.value) {
                await fetchPredictions(1);
                periodReady = initialized;
                return;
            }

            await fetchAvailableSeasons();

            if (disposed) return;
            initialized = true;
            isBootstrapping.value = false;
            await loadPeriod();
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'An error occurred';
            loading.value = false;
        } finally {
            isBootstrapping.value = false;
        }
    };
    const retryPredictions = async () => {
        if (loading.value || refreshing.value) return;
        if (!initialized) await initializeBoard();
        else if (!periodReady) await loadPeriod();
        else await retryPage();
    };
    onMounted(() => {
        refreshTimer = setInterval(refreshBoard, 30000);
        document.addEventListener('visibilitychange', refreshBoard);
        void initializeBoard();
    });
    onBeforeUnmount(() => {
        disposed = true;
        periodRequest++;
        resetPredictions();
        if (refreshTimer) clearInterval(refreshTimer);
        document.removeEventListener('visibilitychange', refreshBoard);
    });

    return {
        ...toRefs(props),
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
        refreshing,
        retryPredictions,
        fetchPredictions,
    };
}
export const predictionBoardKey: InjectionKey<
    ReturnType<typeof usePredictionBoard>
> = Symbol('predictionBoard');
export function usePredictionBoardContext() {
    const board = inject(predictionBoardKey);
    if (!board)
        throw new Error(
            'Prediction board sections require their board context',
        );
    return board;
}
