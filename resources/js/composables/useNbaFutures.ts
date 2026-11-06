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
import { computed, ref, watch, onScopeDispose } from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { inject, type InjectionKey } from 'vue';
type ForecastTeam = {
    id: number;
    abbreviation: string;
    location?: string | null;
    name?: string | null;
    conference?: string | null;
    division?: string | null;
};

type PlayoffForecast = {
    id: number;
    team_id: number;
    season: number;
    conference: string | null;
    conference_rank: number | null;
    projected_seed: number | null;
    playoff_make_probability: number;
    direct_playoff_probability: number;
    play_in_tournament_probability: number;
    division_win_probability: number;
    conference_finals_probability: number;
    nba_finals_probability: number;
    champion_probability: number;
    selection_score: number;
    team: ForecastTeam | null;
    market_odds?: MarketOdds | null;
    market_edge?: MarketEdge | null;
};
type ForecastMeta = {
    available_seasons?: number[];
    playoff_teams_per_conference?: number;
    play_in_teams_per_conference?: number;
    season?: number;
    requested_season?: number;
    fallback_applied?: boolean;
};

export function useNbaFutures() {
    const forecasts = ref<PlayoffForecast[]>([]);
    const loading = ref(true);
    const error = ref<string | null>(null);
    const notice = ref<string | null>(null);
    const selectedSeason = ref<number>(new Date().getFullYear());
    const sortMode = ref<'model' | 'edge'>('model');
    const onlyPlusEv = ref(false);
    const availableSeasons = ref<number[]>([]);
    const playoffTeamsPerConference = ref(8);
    const playInTeamsPerConference = ref(10);
    const activeRequestId = ref(0);
    let disposed = false;
    let activeAbortController: AbortController | null = null;
    const api = useApiV2Client();
    const availableSeasonOptions = computed(() =>
        availableSeasons.value.length > 0
            ? availableSeasons.value
            : [selectedSeason.value],
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
                    row.play_in_tournament_probability >= 0.15 &&
                    row.play_in_tournament_probability <= 0.8 &&
                    row.playoff_make_probability <= 0.9,
            )
            .sort(
                (a, b) =>
                    b.play_in_tournament_probability -
                    a.play_in_tournament_probability,
            )
            .slice(0, 12),
    );
    const conferenceBreakdown = computed(() => {
        const byConference = new Map<
            string,
            {
                conference: string;
                teamsTracked: number;
                projectedIn: number;
                expectedBids: number;
                projectedPlayIn: number;
                divisionLeadersProjectedIn: number;
                predictedWinner: string;
                winnerFinalsProbability: number;
            }
        >();

        const divisionLeaders = new Set<string>();
        const byConferenceDivision = new Map<string, PlayoffForecast[]>();
        for (const row of visibleForecasts.value) {
            const conference =
                (row.conference ?? row.team?.conference ?? 'Unknown').trim() ||
                'Unknown';
            const divisionName = row.team?.division ?? 'Unknown';
            const key = `${conference}::${divisionName}`;
            const current = byConferenceDivision.get(key) ?? [];
            current.push(row);
            byConferenceDivision.set(key, current);
        }

        for (const [key, rows] of byConferenceDivision.entries()) {
            const leader = [...rows].sort((a, b) => {
                if (b.playoff_make_probability !== a.playoff_make_probability) {
                    return (
                        b.playoff_make_probability - a.playoff_make_probability
                    );
                }
                const aRank = a.conference_rank ?? 99;
                const bRank = b.conference_rank ?? 99;
                return aRank - bRank;
            })[0];
            if (leader) {
                divisionLeaders.add(`${key}::${leader.team_id}`);
            }
        }

        for (const row of visibleForecasts.value) {
            const conference =
                (row.conference ?? row.team?.conference ?? 'Unknown').trim() ||
                'Unknown';
            const divisionName = row.team?.division ?? 'Unknown';
            const current = byConference.get(conference) ?? {
                conference,
                teamsTracked: 0,
                projectedIn: 0,
                expectedBids: 0,
                projectedPlayIn: 0,
                divisionLeadersProjectedIn: 0,
                predictedWinner: formatTeam(row.team, row.team_id),
                winnerFinalsProbability: row.nba_finals_probability,
            };

            current.teamsTracked += 1;
            current.projectedIn += row.playoff_make_probability >= 0.5 ? 1 : 0;
            current.projectedPlayIn +=
                row.play_in_tournament_probability >= 0.5 ? 1 : 0;
            current.expectedBids += row.playoff_make_probability;
            if (
                row.playoff_make_probability >= 0.5 &&
                divisionLeaders.has(
                    `${conference}::${divisionName}::${row.team_id}`,
                )
            ) {
                current.divisionLeadersProjectedIn += 1;
            }

            if (row.nba_finals_probability > current.winnerFinalsProbability) {
                current.predictedWinner = formatTeam(row.team, row.team_id);
                current.winnerFinalsProbability = row.nba_finals_probability;
            }

            byConference.set(conference, current);
        }

        return Array.from(byConference.values())
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
    const playInFieldSize = computed(() =>
        Math.max(
            0,
            playInTeamsPerConference.value -
                Math.max(1, playoffTeamsPerConference.value - 2),
        ),
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
        if (make >= 0.85) return 'Playoff Lock';
        if (make >= 0.55) return 'In Picture';
        if (make >= 0.35) return 'Bubble';
        return 'Longshot';
    };

    const fetchForecasts = async () => {
        if (disposed) return;
        const requestId = activeRequestId.value + 1;
        activeRequestId.value = requestId;

        if (activeAbortController) {
            activeAbortController.abort();
        }
        activeAbortController = new AbortController();

        loading.value = true;
        error.value = null;
        notice.value = null;

        try {
            const payload = await api.forecasts.index<PlayoffForecast>('nba', {
                query: { season: selectedSeason.value },
                init: {
                    signal: activeAbortController.signal,
                },
            });
            if (!payload) {
                throw new Error(
                    'Failed to load NBA futures data. Please refresh and sign in again if your session expired.',
                );
            }
            if (requestId !== activeRequestId.value) {
                return;
            }

            const meta = (payload.meta ?? {}) as ForecastMeta;

            forecasts.value = payload.data ?? [];
            availableSeasons.value = meta.available_seasons ?? [];
            playoffTeamsPerConference.value =
                meta.playoff_teams_per_conference ?? 8;
            playInTeamsPerConference.value =
                meta.play_in_teams_per_conference ?? 10;
            const resolvedSeason = Number(meta.season ?? selectedSeason.value);
            const requestedSeason = Number(
                meta.requested_season ?? selectedSeason.value,
            );
            const fallbackApplied = Boolean(meta.fallback_applied ?? false);

            if (
                availableSeasons.value.length > 0 &&
                !availableSeasons.value.includes(selectedSeason.value)
            ) {
                selectedSeason.value = availableSeasons.value[0];
            }

            if (
                forecasts.value.length &&
                fallbackApplied &&
                resolvedSeason !== requestedSeason
            ) {
                notice.value = `No rows for ${requestedSeason}; showing latest available season ${resolvedSeason}.`;
            }
        } catch (e) {
            if (e instanceof DOMException && e.name === 'AbortError') {
                return;
            }
            if (requestId !== activeRequestId.value) {
                return;
            }

            error.value =
                e instanceof Error
                    ? e.message
                    : 'An error occurred while loading NBA futures.';
            forecasts.value = [];
        } finally {
            if (requestId === activeRequestId.value) {
                loading.value = false;
            }
        }
    };
    onScopeDispose(() => {
        disposed = true;
        activeRequestId.value++;
        activeAbortController?.abort();
    });
    watch(
        selectedSeason,
        () => {
            void fetchForecasts();
        },
        { immediate: true },
    );
    return {
        loading,
        error,
        notice,
        selectedSeason,
        sortMode,
        onlyPlusEv,
        playoffTeamsPerConference,
        availableSeasonOptions,
        formatPct,
        formatEdgePoints,
        formatAmericanOdds,
        toPct,
        formatTeam,
        topByMake,
        topByTitle,
        bubbleWatch,
        conferenceBreakdown,
        projectedInCount,
        playInFieldSize,
        avgTitleOdds,
        topChampion,
        statusTag,
        statusTagClass,
        meterClass,
        forecasts,
        fetchForecasts,
    };
}
export const nbaFuturesKey: InjectionKey<ReturnType<typeof useNbaFutures>> =
    Symbol('NbaFutures');
export function useNbaFuturesContext() {
    const state = inject(nbaFuturesKey);
    if (!state) throw new Error('NbaFutures provider is missing.');
    return state;
}
