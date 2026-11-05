import {
    formatPct,
    formatAmericanOdds,
    formatEdgePoints,
    toPct,
    meterClass,
} from '@/lib/futuresPresentation';
import type { MarketOdds, MarketEdge } from '@/types/futures';
import {
    computed,
    onMounted,
    ref,
    watch,
    inject,
    type InjectionKey,
} from 'vue';
import { useAsyncResource } from '@/composables/useAsyncResource';
import { useApiV2Client } from '@/composables/useApiV2Client';

type ForecastTeam = {
    id: number;
    abbreviation: string;
    school?: string | null;
    mascot?: string | null;
    conference?: string | null;
};

type TournamentForecast = {
    id: number;
    team_id: number;
    season: number;
    projected_seed: number | null;
    auto_bid_probability: number;
    at_large_probability: number;
    tournament_make_probability: number;
    first_four_probability: number;
    first_four_auto_probability: number;
    first_four_at_large_probability: number;
    bid_thief_probability: number;
    champion_probability: number;
    final_four_probability: number;
    title_game_probability: number;
    team: ForecastTeam | null;
    market_odds?: MarketOdds | null;
    market_edge?: MarketEdge | null;
};

export function useWcbbForecast() {
    const forecasts = ref<TournamentForecast[]>([]);
    const selectedSeason = ref<number | null>(null);
    const sortMode = ref<'model' | 'edge'>('model');
    const onlyPlusEv = ref(false);
    const availableSeasons = ref<number[]>([]);
    const api = useApiV2Client();

    const availableSeasonOptions = computed(() =>
        availableSeasons.value.length > 0
            ? availableSeasons.value
            : [selectedSeason.value ?? new Date().getFullYear()],
    );

    const formatTeam = (team: ForecastTeam | null, fallback: number) => {
        if (!team) return `Team ${fallback}`;
        const full = `${team.school ?? ''} ${team.mascot ?? ''}`.trim();
        return full || team.abbreviation || `Team ${fallback}`;
    };

    const visibleForecasts = computed(() =>
        onlyPlusEv.value
            ? forecasts.value.filter(
                  (row) => (row.market_edge?.edge_probability ?? 0) > 0,
              )
            : forecasts.value,
    );

    const edgeValue = (row: TournamentForecast) =>
        row.market_edge?.edge_probability ?? Number.NEGATIVE_INFINITY;
    const topByMake = computed(() =>
        [...visibleForecasts.value]
            .sort((a, b) => {
                if (sortMode.value === 'edge') {
                    return edgeValue(b) - edgeValue(a);
                }

                return (
                    b.tournament_make_probability -
                    a.tournament_make_probability
                );
            })
            .slice(0, 24),
    );
    const topByTitle = computed(() =>
        [...visibleForecasts.value]
            .sort((a, b) => b.champion_probability - a.champion_probability)
            .slice(0, 24),
    );
    const bubbleRisk = computed(() =>
        [...visibleForecasts.value]
            .filter(
                (f) =>
                    f.tournament_make_probability > 0.05 &&
                    f.tournament_make_probability < 0.95,
            )
            .sort((a, b) => b.first_four_probability - a.first_four_probability)
            .slice(0, 20),
    );

    const conferenceBreakdown = computed(() => {
        const byConference = new Map<
            string,
            {
                conference: string;
                teamsTracked: number;
                projectedIn: number;
                expectedBids: number;
                avgMakeProbability: number;
                predictedWinner: string;
                predictedWinnerAutoBidProbability: number;
            }
        >();

        for (const row of visibleForecasts.value) {
            const conference =
                (row.team?.conference ?? 'Unknown').trim() || 'Unknown';
            const current = byConference.get(conference) ?? {
                conference,
                teamsTracked: 0,
                projectedIn: 0,
                expectedBids: 0,
                avgMakeProbability: 0,
                predictedWinner: formatTeam(row.team, row.team_id),
                predictedWinnerAutoBidProbability: row.auto_bid_probability,
            };

            current.teamsTracked += 1;
            current.projectedIn +=
                row.tournament_make_probability >= 0.5 ? 1 : 0;
            current.expectedBids += row.tournament_make_probability;

            if (
                row.auto_bid_probability >
                current.predictedWinnerAutoBidProbability
            ) {
                current.predictedWinner = formatTeam(row.team, row.team_id);
                current.predictedWinnerAutoBidProbability =
                    row.auto_bid_probability;
            }

            byConference.set(conference, current);
        }

        return Array.from(byConference.values())
            .map((row) => ({
                ...row,
                expectedBids: Number(row.expectedBids.toFixed(2)),
                avgMakeProbability:
                    row.teamsTracked > 0
                        ? row.expectedBids / row.teamsTracked
                        : 0,
                predictedWinnerAutoBidProbability: Number(
                    row.predictedWinnerAutoBidProbability.toFixed(4),
                ),
            }))
            .sort((a, b) => {
                if (b.projectedIn !== a.projectedIn)
                    return b.projectedIn - a.projectedIn;
                if (b.expectedBids !== a.expectedBids)
                    return b.expectedBids - a.expectedBids;
                return a.conference.localeCompare(b.conference);
            });
    });

    const projectedInCount = computed(
        () =>
            visibleForecasts.value.filter(
                (f) => f.tournament_make_probability >= 0.5,
            ).length,
    );
    const bubbleCount = computed(
        () =>
            visibleForecasts.value.filter(
                (f) =>
                    f.tournament_make_probability >= 0.35 &&
                    f.tournament_make_probability < 0.75,
            ).length,
    );
    const avgBidThiefRisk = computed(() => {
        if (visibleForecasts.value.length === 0) return 0;
        return (
            visibleForecasts.value.reduce(
                (sum, row) => sum + row.bid_thief_probability,
                0,
            ) / visibleForecasts.value.length
        );
    });
    const topChampion = computed(() => topByTitle.value[0] ?? null);

    const statusTag = (row: TournamentForecast) => {
        const make = row.tournament_make_probability;
        if (make >= 0.85) return 'Lock';
        if (make >= 0.55) return 'Likely In';
        if (make >= 0.35) return 'Bubble';
        if (make >= 0.12) return 'Outside';
        return 'Longshot';
    };

    const statusTagClass = (row: TournamentForecast) => {
        const make = row.tournament_make_probability;
        if (make >= 0.85)
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200';
        if (make >= 0.55)
            return 'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-200';
        if (make >= 0.35)
            return 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200';
        if (make >= 0.12)
            return 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-200';
        return 'bg-zinc-200 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200';
    };

    const forecastRequest = useAsyncResource(async (signal) => {
        const payload = await api.forecasts.index<TournamentForecast>('wcbb', {
            query: { season: selectedSeason.value },
            init: { signal },
        });
        if (!payload)
            throw new Error('Failed to load WCBB tournament forecast data.');
        return payload;
    });
    const loading = forecastRequest.isLoading;
    const error = forecastRequest.error;
    const fetchForecasts = () => forecastRequest.execute();
    watch(forecastRequest.data, (payload) => {
        if (!payload) return;
        const meta = (payload.meta ?? {}) as { available_seasons?: number[] };
        forecasts.value = payload.data ?? [];
        availableSeasons.value = meta.available_seasons ?? [];
        if (
            availableSeasons.value.length > 0 &&
            selectedSeason.value !== null &&
            !availableSeasons.value.includes(selectedSeason.value)
        ) {
            selectedSeason.value = availableSeasons.value[0];
        }
    });
    watch(selectedSeason, () => void fetchForecasts());
    onMounted(() => {
        selectedSeason.value = new Date().getFullYear();
    });

    return {
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
        bubbleRisk,
        conferenceBreakdown,
        projectedInCount,
        bubbleCount,
        avgBidThiefRisk,
        topChampion,
        statusTag,
        statusTagClass,
        meterClass,
        loading,
        error,
        forecasts,
        fetchForecasts,
    };
}
export const wcbbForecastKey: InjectionKey<ReturnType<typeof useWcbbForecast>> =
    Symbol('wcbbForecast');
export function useWcbbForecastContext() {
    const state = inject(wcbbForecastKey);
    if (!state)
        throw new Error('WCBB tournament forecast provider is missing.');
    return state;
}
