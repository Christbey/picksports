import { formatPct, formatAmericanOdds } from '@/lib/futuresPresentation';
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
    logo?: string | null;
};

type TournamentForecast = {
    id: number;
    team_id: number;
    season: number;
    projected_seed: number | null;
    tournament_make_probability: number;
    champion_probability: number;
    final_four_probability: number;
    title_game_probability: number;
    team: ForecastTeam | null;
    market_odds?: MarketOdds | null;
    market_edge?: MarketEdge | null;
    is_actual_field: boolean;
    is_first_four: boolean;
    actual_round: string | null;
    actual_region: string | null;
    actual_seed: number | null;
    is_eliminated?: boolean;
};

export function useCbbForecast() {
    const forecasts = ref<TournamentForecast[]>([]);
    const selectedSeason = ref<number | null>(null);
    const availableSeasons = ref<number[]>([]);
    const actualFieldSize = ref(0);
    const forecastMode = ref<string>('baseline');
    const snapshotAsOf = ref<string | null>(null);
    const api = useApiV2Client();

    const regionOrder = ['East', 'West', 'South', 'Midwest'];

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

    const actualFieldForecasts = computed(() =>
        forecasts.value
            .filter((row) => row.is_actual_field && !row.is_eliminated)
            .sort((a, b) => {
                const regionA = regionOrder.indexOf(a.actual_region ?? '');
                const regionB = regionOrder.indexOf(b.actual_region ?? '');
                if (regionA !== regionB)
                    return (
                        (regionA === -1 ? 999 : regionA) -
                        (regionB === -1 ? 999 : regionB)
                    );
                if ((a.actual_seed ?? 99) !== (b.actual_seed ?? 99))
                    return (a.actual_seed ?? 99) - (b.actual_seed ?? 99);
                return formatTeam(a.team, a.team_id).localeCompare(
                    formatTeam(b.team, b.team_id),
                );
            }),
    );

    const actualFieldKnown = computed(
        () =>
            actualFieldForecasts.value.length > 0 || actualFieldSize.value > 0,
    );
    const regionalField = computed(() =>
        regionOrder.map((region) => ({
            region,
            teams: actualFieldForecasts.value.filter(
                (row) => row.actual_region === region && !row.is_first_four,
            ),
        })),
    );

    const firstFourTeams = computed(() =>
        actualFieldForecasts.value
            .filter((row) => row.is_first_four)
            .sort((a, b) => {
                if ((a.actual_seed ?? 99) !== (b.actual_seed ?? 99))
                    return (a.actual_seed ?? 99) - (b.actual_seed ?? 99);
                return formatTeam(a.team, a.team_id).localeCompare(
                    formatTeam(b.team, b.team_id),
                );
            }),
    );

    const titleContenders = computed(() =>
        [...actualFieldForecasts.value]
            .sort((a, b) => b.champion_probability - a.champion_probability)
            .slice(0, 24),
    );

    const deepRunContenders = computed(() =>
        [...actualFieldForecasts.value]
            .sort((a, b) => {
                if (b.final_four_probability !== a.final_four_probability)
                    return b.final_four_probability - a.final_four_probability;
                return b.title_game_probability - a.title_game_probability;
            })
            .slice(0, 16),
    );

    const favorite = computed(() => titleContenders.value[0] ?? null);
    const plusEvTitleEdges = computed(() =>
        titleContenders.value
            .filter((row) => (row.market_edge?.edge_probability ?? 0) > 0)
            .slice(0, 8),
    );

    const forecastRequest = useAsyncResource(async (signal) => {
        const payload = await api.forecasts.index<TournamentForecast>('cbb', {
            query: { season: selectedSeason.value },
            init: { signal },
        });
        if (!payload)
            throw new Error('Failed to load CBB tournament forecast data.');
        return payload;
    });
    const loading = forecastRequest.isLoading;
    const error = forecastRequest.error;
    const fetchForecasts = () => forecastRequest.execute();
    watch(forecastRequest.data, (payload) => {
        if (!payload) return;
        const meta = (payload.meta ?? {}) as {
            available_seasons?: number[];
            actual_field_size?: number;
            mode?: string;
            snapshot_as_of?: string | null;
        };
        forecasts.value = payload.data ?? [];
        availableSeasons.value = meta.available_seasons ?? [];
        actualFieldSize.value = meta.actual_field_size ?? 0;
        forecastMode.value = meta.mode ?? 'baseline';
        snapshotAsOf.value = meta.snapshot_as_of ?? null;
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
        forecastMode,
        snapshotAsOf,
        availableSeasonOptions,
        formatPct,
        formatAmericanOdds,
        formatTeam,
        actualFieldForecasts,
        actualFieldKnown,
        regionalField,
        firstFourTeams,
        titleContenders,
        deepRunContenders,
        favorite,
        plusEvTitleEdges,
        loading,
        error,
        forecasts,
        fetchForecasts,
    };
}
export const cbbForecastKey: InjectionKey<ReturnType<typeof useCbbForecast>> =
    Symbol('cbbForecast');
export function useCbbForecastContext() {
    const state = inject(cbbForecastKey);
    if (!state) throw new Error('CBB tournament forecast provider is missing.');
    return state;
}
