import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { todayKey, gameSortKey } from '@/lib/matchupBoardPresentation';
import type { ApiV2Prediction } from '@/types';
export function useNflMatchupBoardData() {
    const api = useApiV2Client();
    const loading = ref(true),
        refreshing = ref(false),
        filtersLoading = ref(true),
        error = ref<string | null>(null),
        boardStale = ref(false);
    const predictions = ref<ApiV2Prediction[]>([]);
    const availableSeasons = ref<number[]>([]),
        availableDates = ref<string[]>([]),
        selectedSeason = ref(''),
        selectedDate = ref('');
    const selectedPrediction = ref<ApiV2Prediction | null>(null),
        detailOpen = ref(false);
    let filtersReady = false;
    let disposed = false,
        settingDefaultSeason = false,
        loadedQuery: string | null = null;
    let filterRequest: AbortController | undefined,
        boardRequest: AbortController | undefined;
    const closeDetails = () => {
        detailOpen.value = false;
        selectedPrediction.value = null;
    };
    function selectMatchup(prediction: ApiV2Prediction) {
        if (disposed) return;
        selectedPrediction.value = prediction;
        detailOpen.value = true;
    }
    async function loadBoard() {
        if (disposed || filtersLoading.value) return;
        boardRequest?.abort();
        const controller = new AbortController();
        boardRequest = controller;
        const active = () =>
            !disposed &&
            !controller.signal.aborted &&
            boardRequest === controller;
        const date = selectedDate.value,
            season = selectedSeason.value;
        if (!date) {
            predictions.value = [];
            refreshing.value = false;
            loading.value = false;
            return;
        }
        const key = JSON.stringify([season, date]);
        refreshing.value = true;
        error.value = null;
        boardStale.value = false;
        try {
            const payload = await api.predictions.index('nfl', {
                query: {
                    from_date: date,
                    to_date: date,
                    page: 1,
                    per_page: 100,
                    ...(season ? { season } : {}),
                },
                init: { signal: controller.signal },
            });
            if (!active()) return;
            if (!payload)
                throw new Error(
                    'Unable to load NFL predictions. Please try again.',
                );
            predictions.value = (payload.data ?? []).sort((a, b) =>
                gameSortKey(a).localeCompare(gameSortKey(b)),
            );
            loadedQuery = key;
            if (selectedPrediction.value) {
                selectedPrediction.value =
                    predictions.value.find(
                        (row) => row.id === selectedPrediction.value?.id,
                    ) ?? null;
                if (!selectedPrediction.value) detailOpen.value = false;
            }
        } catch (cause) {
            if (active()) {
                if (loadedQuery !== key) predictions.value = [];
                error.value =
                    cause instanceof Error
                        ? cause.message
                        : 'Unable to load NFL board';
                boardStale.value =
                    loadedQuery === key && predictions.value.length > 0;
            }
        } finally {
            if (active()) {
                refreshing.value = false;
                loading.value = false;
            }
        }
    }
    async function loadFilters(reloadSeasons = false) {
        if (disposed) return;
        filterRequest?.abort();
        boardRequest?.abort();
        closeDetails();
        const controller = new AbortController();
        filterRequest = controller;
        const active = () =>
            !disposed &&
            !controller.signal.aborted &&
            filterRequest === controller;
        filtersLoading.value = true;
        filtersReady = false;
        refreshing.value = false;
        error.value = null;
        boardStale.value = false;
        predictions.value = [];
        selectedDate.value = '';
        try {
            if (reloadSeasons) {
                const payload = await api.predictions.availableSeasons('nfl', {
                    init: { signal: controller.signal },
                });
                if (!active()) return;
                if (!payload)
                    throw new Error(
                        'Unable to load NFL seasons. Please try again.',
                    );
                availableSeasons.value = Array.isArray(payload.data)
                    ? payload.data.map(Number).filter(Number.isFinite)
                    : [];
                if (!selectedSeason.value && availableSeasons.value.length) {
                    const currentYear = new Date().getFullYear();
                    settingDefaultSeason = true;
                    selectedSeason.value = String(
                        availableSeasons.value.includes(currentYear)
                            ? currentYear
                            : Math.max(...availableSeasons.value),
                    );
                    settingDefaultSeason = false;
                }
            }
            const payload = await api.predictions.availableDates('nfl', {
                query: selectedSeason.value
                    ? { season: selectedSeason.value }
                    : {},
                init: { signal: controller.signal },
            });
            if (!active()) return;
            if (!payload)
                throw new Error('Unable to load NFL dates. Please try again.');
            availableDates.value = Array.isArray(payload.data)
                ? payload.data
                : [];
            if (availableDates.value.length) {
                const today = todayKey(),
                    future = availableDates.value.filter(
                        (date) => date > today,
                    ),
                    past = availableDates.value.filter((date) => date < today);
                selectedDate.value = availableDates.value.includes(today)
                    ? today
                    : (future[0] ??
                      past[past.length - 1] ??
                      availableDates.value[0]);
            }
            filtersReady = true;
        } catch (cause) {
            if (active()) {
                error.value =
                    cause instanceof Error
                        ? cause.message
                        : 'Unable to load NFL board';
                loading.value = false;
            }
        } finally {
            if (active()) {
                filtersLoading.value = false;
            }
        }
        if (active() && filtersReady) await loadBoard();
    }
    const refreshBoard = () => (filtersReady ? loadBoard() : loadFilters(true));
    watch(
        selectedSeason,
        () => {
            if (!settingDefaultSeason) void loadFilters();
        },
        { flush: 'sync' },
    );
    watch(
        selectedDate,
        () => {
            if (filtersLoading.value) return;
            closeDetails();
            void loadBoard();
        },
        { flush: 'sync' },
    );
    onMounted(() => void loadFilters(true));
    onBeforeUnmount(() => {
        disposed = true;
        filterRequest?.abort();
        boardRequest?.abort();
    });
    return {
        loading,
        refreshing,
        filtersLoading,
        error,
        boardStale,
        predictions,
        availableSeasons,
        availableDates,
        selectedSeason,
        selectedDate,
        selectedPrediction,
        detailOpen,
        selectMatchup,
        loadBoard,
        loadFilters,
        refreshBoard,
    };
}
