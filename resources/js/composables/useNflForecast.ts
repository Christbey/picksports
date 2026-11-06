import { formatAmericanOdds } from '@/lib/futuresPresentation';
import { computed, ref, watch, inject, type InjectionKey } from 'vue';
import { useAsyncResource } from '@/composables/useAsyncResource';
import { useViewerAccess } from '@/composables/useViewerAccess';
import { useApiV2Client } from '@/composables/useApiV2Client';

type MarketOdds = {
    bookmaker: string;
    price: number | null;
    implied_probability: number | null;
    fetched_at: string | null;
    stale?: boolean;
};

type MarketEdge = {
    edge_probability: number | null;
    edge_percent_points: number | null;
    fair_price: number | null;
    market_price: number | null;
    has_edge: boolean;
};

type PlayoffForecast = {
    team_id: number;
    team_name: string;
    conference: string | null;
    division: string | null;
    projected_wins: number;
    projected_seed: number | null;
    division_winner_probability: number;
    make_playoffs_probability: number;
    conference_champion_probability: number;
    super_bowl_champion_probability: number;
    market_odds?: MarketOdds | null;
    market_edge?: MarketEdge | null;
};

type ForecastMeta = {
    season?: number;
    simulations?: number | null;
    warnings?: string[];
};

export function useNflForecast() {
    const { isAdmin } = useViewerAccess();
    const api = useApiV2Client();
    const currentYear = new Date().getFullYear();
    const selectedSeason = ref(currentYear);
    const sortMode = ref<'title' | 'playoffs' | 'wins' | 'edge'>('title');
    const onlyPlusEv = ref(false);
    const forecasts = ref<PlayoffForecast[]>([]);
    const simulations = ref<number | null>(null);
    const warnings = ref<string[]>([]);

    const seasonOptions = computed(() =>
        Array.from({ length: 5 }, (_, index) => currentYear - index),
    );

    const visibleForecasts = computed(() => {
        const rows = onlyPlusEv.value
            ? forecasts.value.filter(
                  (row) => (row.market_edge?.edge_probability ?? 0) > 0,
              )
            : forecasts.value;

        return [...rows].sort((left, right) => {
            if (sortMode.value === 'playoffs') {
                return (
                    right.make_playoffs_probability -
                    left.make_playoffs_probability
                );
            }
            if (sortMode.value === 'wins') {
                return right.projected_wins - left.projected_wins;
            }
            if (sortMode.value === 'edge') {
                return (
                    (right.market_edge?.edge_probability ?? -1) -
                    (left.market_edge?.edge_probability ?? -1)
                );
            }

            return (
                right.super_bowl_champion_probability -
                left.super_bowl_champion_probability
            );
        });
    });

    const topChampion = computed(
        () =>
            [...forecasts.value].sort(
                (left, right) =>
                    right.super_bowl_champion_probability -
                    left.super_bowl_champion_probability,
            )[0],
    );

    const playoffField = computed(
        () =>
            forecasts.value.filter(
                (forecast) => forecast.make_playoffs_probability >= 0.5,
            ).length,
    );

    const plusEvCount = computed(
        () =>
            forecasts.value.filter(
                (forecast) => (forecast.market_edge?.edge_probability ?? 0) > 0,
            ).length,
    );

    const conferenceLeaders = computed(() =>
        ['AFC', 'NFC']
            .map((conference) => {
                const teams = forecasts.value.filter(
                    (forecast) => forecast.conference === conference,
                );
                const champion = [...teams].sort(
                    (left, right) =>
                        right.conference_champion_probability -
                        left.conference_champion_probability,
                )[0];

                return {
                    conference,
                    champion,
                    expectedPlayoffTeams: teams.reduce(
                        (total, team) => total + team.make_playoffs_probability,
                        0,
                    ),
                };
            })
            .filter((row) => row.champion),
    );

    const formatPct = (value: number | null | undefined, digits = 1) =>
        value === null || value === undefined
            ? '-'
            : value === 0 && simulations.value
              ? '0%*'
              : `${(value * 100).toFixed(digits)}%`;

    const formatEdge = (value: number | null | undefined) => {
        if (value === null || value === undefined) return '-';
        const points = value * 100;
        return `${points >= 0 ? '+' : ''}${points.toFixed(1)}pp`;
    };

    const forecastRequest = useAsyncResource(async (signal) => {
        const payload = await api.forecasts.index<PlayoffForecast>('nfl', {
            query: { season: selectedSeason.value },
            init: { signal },
        });
        if (!payload) throw new Error('Failed to load NFL futures data.');
        return payload;
    });
    const loading = forecastRequest.isLoading;
    const error = forecastRequest.error;
    const fetchForecasts = () => forecastRequest.execute();
    watch(forecastRequest.data, (payload) => {
        if (!payload) return;
        forecasts.value = payload.data ?? [];
        const meta = (payload.meta ?? {}) as ForecastMeta;
        simulations.value = meta.simulations ?? null;
        warnings.value = meta.warnings ?? [];
    });
    watch(selectedSeason, () => void fetchForecasts(), { immediate: true });

    return {
        selectedSeason,
        sortMode,
        onlyPlusEv,
        forecasts,
        simulations,
        warnings,
        seasonOptions,
        visibleForecasts,
        topChampion,
        playoffField,
        plusEvCount,
        conferenceLeaders,
        formatPct,
        formatAmericanOdds,
        formatEdge,
        loading,
        error,
        isAdmin,
        fetchForecasts,
    };
}
export const nflForecastKey: InjectionKey<ReturnType<typeof useNflForecast>> =
    Symbol('nflForecast');
export function useNflForecastContext() {
    const state = inject(nflForecastKey);
    if (!state) throw new Error('NFL futures provider is missing.');
    return state;
}
