import type { MarketOdds, MarketEdge } from '@/types/futures';
import {
    formatPct,
    formatEdgePoints,
    formatAmericanOdds,
    toPct,
    formatTeam,
    statusTagClass,
    meterClass,
} from '@/lib/futuresPresentation';
import { computed, onMounted, ref, watch, onScopeDispose } from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { inject, type InjectionKey } from 'vue';
type ForecastTeam = {
    id: number;
    abbreviation: string;
    location?: string | null;
    name?: string | null;
    league?: string | null;
};

type PlayoffForecast = {
    id: number;
    team_id: number;
    season: number;
    league: string | null;
    league_rank: number | null;
    projected_seed: number | null;
    playoff_make_probability: number;
    division_win_probability: number;
    division_series_probability: number;
    league_championship_series_probability: number;
    league_championship_probability: number;
    pennant_probability: number;
    world_series_probability: number;
    champion_probability: number;
    selection_score: number;
    team: ForecastTeam | null;
    market_odds?: MarketOdds | null;
    market_edge?: MarketEdge | null;
};

export function useMlbFutures() {
    const forecasts = ref<PlayoffForecast[]>([]);
    const loading = ref(true);
    const error = ref<string | null>(null);
    const selectedSeason = ref<number | null>(null);
    const sortMode = ref<'model' | 'edge'>('model');
    const onlyPlusEv = ref(false);
    const availableSeasons = ref<number[]>([]);
    const api = useApiV2Client();
    let latestForecastRequest = 0;
    let disposed = false;
    let activeAbortController: AbortController | null = null;
    const availableSeasonOptions = computed(() =>
        availableSeasons.value.length > 0
            ? availableSeasons.value
            : [selectedSeason.value ?? new Date().getFullYear()],
    );

    const visibleForecasts = computed(() =>
        onlyPlusEv.value
            ? forecasts.value.filter(
                  (row) => (row.market_edge?.edge_probability ?? 0) > 0,
              )
            : forecasts.value,
    );
    const edgeValue = (row: PlayoffForecast) =>
        row.market_edge?.edge_probability ?? Number.NEGATIVE_INFINITY;
    const topByMake = computed(() =>
        [...visibleForecasts.value]
            .sort((a, b) => {
                if (sortMode.value === 'edge') {
                    return edgeValue(b) - edgeValue(a);
                }

                return b.playoff_make_probability - a.playoff_make_probability;
            })
            .slice(0, 20),
    );
    const topByTitle = computed(() =>
        [...visibleForecasts.value]
            .sort((a, b) => b.champion_probability - a.champion_probability)
            .slice(0, 20),
    );
    const bubbleWatch = computed(() =>
        [...visibleForecasts.value]
            .filter(
                (row) =>
                    row.playoff_make_probability >= 0.25 &&
                    row.playoff_make_probability < 0.75,
            )
            .sort(
                (a, b) =>
                    b.playoff_make_probability - a.playoff_make_probability,
            )
            .slice(0, 12),
    );
    const leagueBreakdown = computed(() => {
        const byLeague = new Map<
            string,
            {
                league: string;
                teamsTracked: number;
                projectedIn: number;
                expectedBids: number;
                predictedWinner: string;
                winnerPennantProbability: number;
            }
        >();

        for (const row of visibleForecasts.value) {
            const league =
                (row.league ?? row.team?.league ?? 'Unknown').trim() ||
                'Unknown';
            const current = byLeague.get(league) ?? {
                league,
                teamsTracked: 0,
                projectedIn: 0,
                expectedBids: 0,
                predictedWinner: formatTeam(row.team, row.team_id),
                winnerPennantProbability:
                    row.pennant_probability ??
                    row.league_championship_probability,
            };

            current.teamsTracked += 1;
            current.projectedIn += row.playoff_make_probability >= 0.5 ? 1 : 0;
            current.expectedBids += row.playoff_make_probability;

            if (
                (row.pennant_probability ??
                    row.league_championship_probability) >
                current.winnerPennantProbability
            ) {
                current.predictedWinner = formatTeam(row.team, row.team_id);
                current.winnerPennantProbability =
                    row.pennant_probability ??
                    row.league_championship_probability;
            }

            byLeague.set(league, current);
        }

        return Array.from(byLeague.values())
            .map((row) => ({
                ...row,
                expectedBids: Number(row.expectedBids.toFixed(2)),
            }))
            .sort((a, b) => b.projectedIn - a.projectedIn);
    });
    const projectedInCount = computed(
        () =>
            visibleForecasts.value.filter(
                (f) => f.playoff_make_probability >= 0.5,
            ).length,
    );
    const avgTitleOdds = computed(() => {
        if (visibleForecasts.value.length === 0) return 0;
        return (
            visibleForecasts.value.reduce(
                (sum, row) => sum + row.champion_probability,
                0,
            ) / visibleForecasts.value.length
        );
    });
    const topChampion = computed(() => topByTitle.value[0] ?? null);
    const statusTag = (row: PlayoffForecast) => {
        const make = row.playoff_make_probability;
        if (make >= 0.85) return 'High Probability';
        if (make >= 0.55) return 'In Picture';
        if (make >= 0.35) return 'Bubble';
        return 'Longshot';
    };

    const fetchForecasts = async () => {
        if (disposed || !selectedSeason.value) return;
        const request = ++latestForecastRequest;
        activeAbortController?.abort();
        activeAbortController = new AbortController();
        loading.value = true;
        error.value = null;

        try {
            const payload = await api.forecasts.index<PlayoffForecast>('mlb', {
                query: { season: selectedSeason.value },
                init: { signal: activeAbortController.signal },
            });
            if (!payload) {
                throw new Error('Failed to load MLB futures data');
            }
            if (request !== latestForecastRequest) return;
            const meta = (payload.meta ?? {}) as {
                available_seasons?: number[];
            };
            forecasts.value = payload.data ?? [];
            availableSeasons.value = meta.available_seasons ?? [];
            if (
                availableSeasons.value.length > 0 &&
                selectedSeason.value !== null &&
                !availableSeasons.value.includes(selectedSeason.value)
            ) {
                selectedSeason.value = availableSeasons.value[0];
            }
        } catch (e) {
            if (request !== latestForecastRequest) return;
            error.value =
                e instanceof Error
                    ? e.message
                    : 'An error occurred while loading MLB futures.';
            forecasts.value = [];
        } finally {
            if (request === latestForecastRequest) {
                loading.value = false;
            }
        }
    };
    onScopeDispose(() => {
        disposed = true;
        latestForecastRequest++;
        activeAbortController?.abort();
    });
    watch(selectedSeason, () => {
        fetchForecasts();
    });
    onMounted(() => {
        selectedSeason.value = new Date().getFullYear();
    });
    return {
        loading,
        error,
        selectedSeason,
        sortMode,
        onlyPlusEv,
        availableSeasonOptions,
        formatPct,
        formatEdgePoints,
        formatAmericanOdds,
        toPct,
        formatTeam,
        topByMake,
        topByTitle,
        bubbleWatch,
        leagueBreakdown,
        projectedInCount,
        avgTitleOdds,
        topChampion,
        statusTag,
        statusTagClass,
        meterClass,
        forecasts,
        fetchForecasts,
    };
}
export const mlbFuturesKey: InjectionKey<ReturnType<typeof useMlbFutures>> =
    Symbol('MlbFutures');
export function useMlbFuturesContext() {
    const state = inject(mlbFuturesKey);
    if (!state) throw new Error('MlbFutures provider is missing.');
    return state;
}
