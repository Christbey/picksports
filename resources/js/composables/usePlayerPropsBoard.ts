import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';
import type { Recommendation } from '@/lib/playerPropTypes';
import type {
    DateOption,
    GameOption,
    MarketOption,
    PlayerPropsFilters,
    PlayerPropsMeta,
    PlayerPropsResponse,
    PlayerPropsPageProps,
} from '@/types/player-props-board';

export function usePlayerPropsBoard(props: PlayerPropsPageProps) {
    const api = useApiV2Client();
    const selectedView = ref<'recommendations' | 'all'>(
        props.sport === 'NFL' && props.filters.view === 'all'
            ? 'all'
            : 'recommendations',
    );
    const selectedDate = ref(props.filters.date || '');
    const selectedGame = ref(
        props.filters.game == null ? '' : String(props.filters.game),
    );
    const selectedMarket = ref(props.filters.market || '');
    const recommendations = ref<Recommendation[]>([]);
    const dates = ref<DateOption[]>([]);
    const games = ref<GameOption[]>([]);
    const markets = ref<MarketOption[]>([]);
    const filtersReady = ref(false);
    let boardAbortController: AbortController | null = null;
    let mounted = false;
    let pathname = '';
    let optionsKey: string | null = null;
    const commonMarkets = computed(() => {
        const keys = [
            'player_pass_yds',
            'player_rush_yds',
            'player_reception_yds',
            'player_receptions',
            'player_anytime_td',
        ];
        return keys.flatMap((key) =>
            markets.value.filter((market) => market.value === key),
        );
    });
    const boardMeta = ref<PlayerPropsMeta | null>(null);
    const loading = ref(true);
    const error = ref<string | null>(null);
    const visibleLimit = ref(18);
    const playerSearch = ref('');
    const searchedRecommendations = computed(() => {
        const query = playerSearch.value.trim().toLowerCase();
        return recommendations.value.filter(
            (rec) =>
                !query ||
                [
                    rec.player.name,
                    rec.player.team,
                    rec.game.home_team,
                    rec.game.away_team,
                ].some((value) => value?.toLowerCase().includes(query)),
        );
    });

    const filteredGames = computed(() => {
        if (!selectedDate.value) {
            return games.value;
        }

        return games.value.filter((game) => game.date === selectedDate.value);
    });

    const hasActiveFilters = computed(
        () =>
            selectedDate.value !== '' ||
            selectedGame.value !== '' ||
            selectedMarket.value !== '',
    );

    const visibleRecommendations = computed(() =>
        searchedRecommendations.value.slice(0, visibleLimit.value),
    );

    const emptyStateTitle = computed(() => {
        const diagnostics = boardMeta.value?.diagnostics;
        if (!diagnostics) return 'No Recommendations Available';

        if ((diagnostics.raw_prop_count ?? 0) === 0) {
            return 'No Props Synced For This Filter';
        }

        if ((diagnostics.analyzed_prop_count ?? 0) === 0) {
            return 'Props Need Analysis';
        }

        return 'No Recommendation-Ready Props';
    });

    const emptyStateMessage = computed(() => {
        const diagnostics = boardMeta.value?.diagnostics;
        const warning = boardMeta.value?.warnings?.[0];
        if (warning) return warning;

        if (!diagnostics) {
            return 'Check back later or sync player props to see betting recommendations.';
        }

        if ((diagnostics.raw_prop_count ?? 0) === 0) {
            return 'No player props were found for the selected date, game, or market.';
        }

        if ((diagnostics.analyzed_prop_count ?? 0) === 0) {
            return 'Player props are synced, but the recommendation analysis has not run for this slate yet.';
        }

        return 'Props were analyzed, but none currently clear the recommendation threshold for this filter.';
    });

    const currentFilters = (): PlayerPropsFilters => ({
        date: selectedDate.value,
        game: selectedGame.value,
        market: selectedMarket.value,
    });
    const buildQueryParams = (): Record<string, string> => {
        const query: Record<string, string> = { limit: '75' };
        if (selectedDate.value) query.date = selectedDate.value;
        if (selectedGame.value) query.game = selectedGame.value;
        if (selectedMarket.value) query.market = selectedMarket.value;
        return query;
    };
    const updateBrowserUrl = () => {
        const url = new URL(window.location.href);
        const values = {
            ...currentFilters(),
            view: selectedView.value === 'all' ? 'all' : '',
        };
        for (const [key, value] of Object.entries(values)) {
            if (value != null && value !== '')
                url.searchParams.set(key, String(value));
            else url.searchParams.delete(key);
        }
        window.history.replaceState(
            window.history.state,
            '',
            `${url.pathname}${url.search}${url.hash}`,
        );
    };
    const loadBoard = async () => {
        boardAbortController?.abort();
        const controller = new AbortController();
        boardAbortController = controller;
        const view = selectedView.value;
        const key = JSON.stringify([props.sportSlug, currentFilters()]);
        error.value = null;
        if (view === 'all' && optionsKey === key) {
            loading.value = false;
            filtersReady.value = true;
            return;
        }
        loading.value = true;
        try {
            const payload = await api.playerProps.board<PlayerPropsResponse>(
                props.sportSlug,
                {
                    query: {
                        ...buildQueryParams(),
                        ...(view === 'all' ? { filters_only: true } : {}),
                    },
                    init: { signal: controller.signal },
                },
            );
            if (controller.signal.aborted || !mounted) return;
            if (!payload) throw new Error('Unable to load player props.');
            dates.value = payload.dates || [];
            games.value = payload.games || [];
            markets.value = payload.markets || [];
            selectedDate.value = payload.filters.date || '';
            selectedGame.value =
                payload.filters.game == null
                    ? ''
                    : String(payload.filters.game);
            selectedMarket.value = payload.filters.market || '';
            optionsKey = JSON.stringify([props.sportSlug, currentFilters()]);
            filtersReady.value = true;
            if (view === 'recommendations') {
                recommendations.value = payload.data || [];
                visibleLimit.value = 18;
                boardMeta.value = payload.meta || null;
            }
            updateBrowserUrl();
        } catch (caught) {
            if (controller.signal.aborted || !mounted) return;
            error.value =
                caught instanceof Error
                    ? caught.message
                    : 'Unable to load player props.';
            filtersReady.value = true;
        } finally {
            if (boardAbortController === controller) loading.value = false;
        }
    };
    const applyFilters = () => {
        updateBrowserUrl();
        void loadBoard();
    };
    const switchView = (view: 'recommendations' | 'all') => {
        if (
            view === selectedView.value ||
            (view === 'all' && props.sport !== 'NFL')
        )
            return;
        selectedView.value = view;
        applyFilters();
    };
    const onDateChange = () => {
        selectedGame.value = '';
        applyFilters();
    };
    const onGameChange = applyFilters;
    const onMarketChange = applyFilters;
    const clearFilters = () => {
        selectedDate.value = '';
        selectedGame.value = '';
        selectedMarket.value = '';
        applyFilters();
    };
    const restoreFilters = (filters: PlayerPropsFilters) => {
        selectedView.value =
            props.sport === 'NFL' && filters.view === 'all'
                ? 'all'
                : 'recommendations';
        selectedDate.value = filters.date || '';
        selectedGame.value = filters.game == null ? '' : String(filters.game);
        selectedMarket.value = filters.market || '';
        void loadBoard();
    };
    const onPopState = () => {
        if (window.location.pathname !== pathname) return;
        const query = new URL(window.location.href).searchParams;
        restoreFilters({
            view: query.get('view') === 'all' ? 'all' : 'recommendations',
            date: query.get('date'),
            game: query.get('game'),
            market: query.get('market'),
        });
    };
    watch(
        () => [props.sportSlug, props.filters],
        () => {
            if (mounted) restoreFilters(props.filters);
        },
        { deep: true },
    );
    onMounted(() => {
        mounted = true;
        pathname = window.location.pathname;
        window.addEventListener('popstate', onPopState);
        void loadBoard();
    });
    onBeforeUnmount(() => {
        mounted = false;
        boardAbortController?.abort();
        window.removeEventListener('popstate', onPopState);
    });
    return {
        selectedView,
        selectedDate,
        selectedGame,
        selectedMarket,
        recommendations,
        dates,
        games,
        markets,
        commonMarkets,
        boardMeta,
        loading,
        error,
        visibleLimit,
        playerSearch,
        searchedRecommendations,
        filteredGames,
        hasActiveFilters,
        visibleRecommendations,
        emptyStateTitle,
        emptyStateMessage,
        filtersReady,
        switchView,
        onDateChange,
        onGameChange,
        onMarketChange,
        clearFilters,
        loadBoard,
    };
}
