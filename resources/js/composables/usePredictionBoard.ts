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
import { currentSlateDate, footballSeason } from '@/lib/predictionPeriod';
import { mapV2Prediction } from '@/lib/predictionBoardPresentation';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { usePredictionList } from '@/composables/usePredictionList';
import type {
    ApiV2Query,
    PaginationMeta,
    PredictionListItem,
    SportPredictionsConfig,
} from '@/types';
export type PredictionsBoardProps = { config: SportPredictionsConfig };

export function usePredictionBoard(props: PredictionsBoardProps) {
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
        refreshing,
        retryPage,
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
            const futureDates = availableDates.value.filter(
                (d) => d > today.value,
            );
            const pastDates = availableDates.value.filter(
                (d) => d < today.value,
            );
            selectedDate.value =
                futureDates.length > 0
                    ? futureDates[0]
                    : pastDates[pastDates.length - 1];
        }
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
            isBootstrapping.value
        )
            return;
        // Include scheduled games so the board transitions into live without a reload.
        if (predictions.value.every((p) => p.game.status === 'STATUS_FINAL'))
            return;
        void fetchPredictions(meta.value?.current_page ?? 1, true);
    };
    let initialized = false;
    const initializeBoard = async () => {
        isBootstrapping.value = true;
        loading.value = true;
        error.value = null;
        try {
            await fetchAvailableSeasons();

            if (filterMode.value === 'date') {
                await fetchAvailableDates();
            } else if (filterMode.value === 'seasonWeek') {
                await setDefaultSeasonWeekFilters();
            }

            initialized = true;
            isBootstrapping.value = false;
            await fetchPredictions(1);
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'An error occurred';
        } finally {
            isBootstrapping.value = false;
            loading.value = false;
        }
    };
    const retryPredictions = async () => {
        if (loading.value || refreshing.value) return;
        if (!initialized) await initializeBoard();
        else await retryPage();
    };
    onMounted(() => {
        refreshTimer = setInterval(refreshBoard, 30000);
        document.addEventListener('visibilitychange', refreshBoard);
        void initializeBoard();
    });
    onBeforeUnmount(() => {
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
