import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { numberValue } from '@/lib/mlbMatchupPresentation';
import { todayKey, gameSortKey } from '@/lib/mlbBoardPresentation';
import type { ApiV2Prediction } from '@/types';
import type {
    MlbDailyPick,
    MlbDailyPicksPayload,
} from '@/types/mlb-daily-picks';

export function useMlbMatchupBoardData() {
    const api = useApiV2Client();
    const loading = ref(true),
        refreshing = ref(false),
        filtersLoading = ref(true),
        error = ref<string | null>(null),
        boardStale = ref(false);
    const predictions = ref<ApiV2Prediction[]>([]),
        dailyPayload = ref<MlbDailyPicksPayload['data'] | null>(null);
    const availableSeasons = ref<number[]>([]),
        availableDates = ref<string[]>([]),
        selectedSeason = ref(''),
        selectedDate = ref('');
    const selectedPrediction = ref<ApiV2Prediction | null>(null),
        selectedCandidate = ref<MlbDailyPick | null>(null),
        selectedCandidates = ref<MlbDailyPick[]>([]);
    const detailOpen = ref(false),
        detailLoading = ref(false),
        detailError = ref<string | null>(null);
    let filtersReady = false;
    let disposed = false,
        settingDefaultSeason = false,
        loadedQuery: string | null = null;
    let filterRequest: AbortController | undefined,
        boardRequest: AbortController | undefined,
        detailRequest: AbortController | undefined;
    const closeDetails = () => {
        detailRequest?.abort();
        detailOpen.value = false;
        detailLoading.value = false;
        detailError.value = null;
    };
    watch(
        detailOpen,
        (open) => {
            if (!open) {
                detailRequest?.abort();
                detailLoading.value = false;
            }
        },
        { flush: 'sync' },
    );
    async function selectMatchup(
        prediction: ApiV2Prediction,
        candidate: MlbDailyPick | null,
    ) {
        if (disposed) return;
        detailRequest?.abort();
        const controller = new AbortController();
        detailRequest = controller;
        selectedPrediction.value = prediction;
        selectedCandidate.value = candidate;
        const gameId = numberValue(prediction.game_id ?? prediction.game?.id);
        selectedCandidates.value =
            gameId === null
                ? []
                : (dailyPayload.value?.candidates ?? []).filter(
                      (row) => row.game_id === gameId,
                  );
        detailOpen.value = true;
        detailError.value = null;
        detailLoading.value = gameId !== null;
        if (gameId === null) return;
        const active = () =>
            !disposed &&
            !controller.signal.aborted &&
            detailRequest === controller &&
            detailOpen.value;
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
                    init: { signal: controller.signal },
                },
            );
            if (!active()) return;
            if (!payload?.data)
                throw new Error(
                    'Full market and signal details are temporarily unavailable.',
                );
            selectedCandidates.value = payload.data.candidates;
            selectedCandidate.value =
                payload.data.candidates.find(
                    (option) => option.id === candidate?.id,
                ) ??
                payload.data.candidates[0] ??
                null;
        } catch (cause) {
            if (active())
                detailError.value =
                    cause instanceof Error
                        ? cause.message
                        : 'Full market and signal details are temporarily unavailable.';
        } finally {
            if (active()) detailLoading.value = false;
        }
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
            dailyPayload.value = null;
            refreshing.value = false;
            loading.value = false;
            return;
        }
        const key = JSON.stringify([season, date]);
        if (loadedQuery !== key) {
            predictions.value = [];
            dailyPayload.value = null;
        }
        refreshing.value = true;
        error.value = null;
        boardStale.value = false;
        try {
            const [predictionResult, picksResult] = await Promise.allSettled([
                api.predictions.index('mlb', {
                    query: {
                        from_date: date,
                        to_date: date,
                        page: 1,
                        per_page: 100,
                        ...(season ? { season } : {}),
                    },
                    init: { signal: controller.signal },
                }),
                api.dailyPicks.index<MlbDailyPicksPayload>('mlb', {
                    query: { date, compact: 1, ...(season ? { season } : {}) },
                    init: { signal: controller.signal },
                }),
            ]);
            if (!active()) return;
            if (predictionResult.status === 'rejected')
                throw predictionResult.reason;
            const predictionPayload = predictionResult.value;
            if (!predictionPayload)
                throw new Error('Unable to load MLB predictions.');
            const picksPayload =
                picksResult.status === 'fulfilled' ? picksResult.value : null;
            const periodModelsByGame =
                picksPayload?.data?.period_models_by_game ?? {};
            predictions.value = (predictionPayload.data ?? [])
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
            loadedQuery = key;
            if (!picksPayload)
                error.value =
                    'Candidate details could not be loaded. Sportsbook availability shown on each matchup still comes from the prediction market snapshot.';
        } catch (cause) {
            if (active()) {
                error.value =
                    cause instanceof Error
                        ? cause.message
                        : 'Unable to load MLB board';
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
        dailyPayload.value = null;
        selectedDate.value = '';
        try {
            if (reloadSeasons) {
                const payload = await api.predictions.availableSeasons('mlb', {
                    init: { signal: controller.signal },
                });
                if (!active()) return;
                if (!payload)
                    throw new Error(
                        'Unable to load MLB seasons. Please try again.',
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
            const payload = await api.predictions.availableDates('mlb', {
                query: selectedSeason.value
                    ? { season: selectedSeason.value }
                    : {},
                init: { signal: controller.signal },
            });
            if (!active()) return;
            if (!payload)
                throw new Error('Unable to load MLB dates. Please try again.');
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
                        : 'Unable to load MLB board';
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
        detailRequest?.abort();
    });
    return {
        loading,
        refreshing,
        filtersLoading,
        error,
        boardStale,
        predictions,
        dailyPayload,
        availableSeasons,
        availableDates,
        selectedSeason,
        selectedDate,
        selectedPrediction,
        selectedCandidate,
        selectedCandidates,
        detailOpen,
        detailLoading,
        detailError,
        selectMatchup,
        loadBoard,
        loadFilters,
        refreshBoard,
    };
}
