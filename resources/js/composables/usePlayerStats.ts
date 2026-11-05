import {
    computed,
    ref,
    watch,
    onMounted,
    onBeforeUnmount,
    toRefs,
    inject,
    type InjectionKey,
} from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { buildDefaultPlayerStatColumns } from '@/lib/playerStatsPresentation';
import type {
    PlayerStatsProps,
    PlayerLeaderboardEntry,
    NumericLeaderboardKey,
} from '@/types/player-stats';

export function usePlayerStats(props: PlayerStatsProps) {
    const players = ref<PlayerLeaderboardEntry[]>([]);
    const loading = ref(true);
    const error = ref<string | null>(null);
    const searchQuery = ref('');
    const selectedSeasonType = ref(props.config.defaultSeasonType ?? '');
    const sortBy = ref('points_per_game');
    const sortDesc = ref(true);
    const selectedCategory = ref<string>('all');
    const seasonReady = ref(false);
    const availableSeasons = ref<number[]>([]);
    const selectedSeason = ref('');
    const visibleLimit = ref(100);
    const api = useApiV2Client();
    let disposed = false;
    let leaderboardAbortController: AbortController | null = null;
    let seasonsAbortController: AbortController | null = null;

    const fetchAvailableSeasons = async () => {
        if (disposed) return;
        seasonsAbortController?.abort();
        const controller = new AbortController();
        seasonsAbortController = controller;
        const payload = await api.leaderboards.playerAvailableSeasons(
            props.config.sport,
            { init: { signal: controller.signal } },
        );
        if (disposed || controller.signal.aborted) return;
        if (!payload) {
            throw new Error('Failed to fetch available seasons');
        }

        availableSeasons.value = Array.isArray(payload.data)
            ? payload.data
                  .map((season) => Number(season))
                  .filter((season) => Number.isFinite(season))
            : [];

        if (!selectedSeason.value && availableSeasons.value.length > 0) {
            const currentYear = new Date().getFullYear();
            const preferredSeason = availableSeasons.value.includes(currentYear)
                ? currentYear
                : Math.max(...availableSeasons.value);

            selectedSeason.value = String(preferredSeason);
        }
    };

    const categoryOptions = computed(
        () => props.config.statCategoryOptions ?? [],
    );

    const activeCategory = computed(
        () =>
            categoryOptions.value.find(
                (category) => category.key === selectedCategory.value,
            ) ?? null,
    );

    const activeMinGames = computed(
        () => activeCategory.value?.minGames ?? props.config.minGames ?? 1,
    );

    const minimumGamesForRequest = computed(() => {
        const configuredMinimums = [
            props.config.minGames,
            ...categoryOptions.value.map((category) => category.minGames),
        ].filter(
            (value): value is number =>
                typeof value === 'number' && Number.isFinite(value),
        );

        return configuredMinimums.length > 0
            ? Math.max(1, Math.min(...configuredMinimums.map(Math.trunc)))
            : 1;
    });

    const sortOptions = computed(() => {
        const options = activeCategory.value?.sortOptions ??
            props.config.sortOptions ?? [
                { key: 'points_per_game', label: 'PPG' },
                { key: 'rebounds_per_game', label: 'RPG' },
                { key: 'assists_per_game', label: 'APG' },
            ];

        if (
            !activeCategory.value?.sortOptions &&
            !props.config.sortOptions &&
            props.config.showEpaColumns
        ) {
            options.push(
                { key: 'estimated_epa_per_game', label: 'EPA/G' },
                { key: 'estimated_epa_per_36', label: 'EPA/36' },
            );
        }

        return options;
    });

    const filteredPlayers = computed(() => {
        const eligiblePlayers = players.value.filter(
            (player) => player.games_played >= activeMinGames.value,
        );
        const categoryFilteredPlayers = activeCategory.value?.match
            ? eligiblePlayers.filter(
                  (player) => activeCategory.value?.match?.(player) ?? true,
              )
            : eligiblePlayers;

        if (!searchQuery.value) {
            return categoryFilteredPlayers;
        }

        const query = searchQuery.value.toLowerCase();
        return categoryFilteredPlayers.filter(
            (p) =>
                p.player?.full_name?.toLowerCase().includes(query) ||
                p.player?.team?.display_name?.toLowerCase().includes(query) ||
                p.player?.team?.abbreviation?.toLowerCase().includes(query),
        );
    });

    const sortedPlayers = computed(() => {
        const sorted = [...filteredPlayers.value];
        sorted.sort((a, b) => {
            const aVal = a[sortBy.value as NumericLeaderboardKey] ?? 0;
            const bVal = b[sortBy.value as NumericLeaderboardKey] ?? 0;
            return sortDesc.value ? bVal - aVal : aVal - bVal;
        });
        return sorted;
    });

    const visiblePlayers = computed(() =>
        sortedPlayers.value.slice(0, visibleLimit.value),
    );

    const fetchPlayers = async () => {
        if (disposed) return;
        leaderboardAbortController?.abort();
        const controller = new AbortController();
        leaderboardAbortController = controller;

        try {
            loading.value = true;
            error.value = null;

            const query: Record<string, string | number> = {};
            if (selectedSeason.value) {
                query.season = selectedSeason.value;
            }
            query.min_games = minimumGamesForRequest.value;
            if (selectedSeasonType.value) {
                query.season_type = selectedSeasonType.value;
            }

            const response = await api.leaderboards.players(
                props.config.sport,
                {
                    query,
                    init: { signal: controller.signal },
                },
            );
            if (
                disposed ||
                controller.signal.aborted ||
                leaderboardAbortController !== controller
            )
                return;
            if (!response) throw new Error('Failed to fetch player stats');

            players.value = (response.data ??
                []) as unknown as PlayerLeaderboardEntry[];
        } catch (e) {
            if (disposed || controller.signal.aborted) return;
            error.value = e instanceof Error ? e.message : 'An error occurred';
        } finally {
            if (!disposed && leaderboardAbortController === controller) {
                loading.value = false;
            }
        }
    };

    const toggleSort = (key: string) => {
        if (sortBy.value === key) {
            sortDesc.value = !sortDesc.value;
            return;
        }

        sortBy.value = key;
        sortDesc.value = true;
    };

    const defaultStatColumns = computed(() =>
        buildDefaultPlayerStatColumns(props.config.showEpaColumns),
    );

    const statColumns = computed(
        () =>
            activeCategory.value?.statColumns ??
            props.config.statColumns ??
            defaultStatColumns.value,
    );

    const activeSortLabel = computed(
        () =>
            sortOptions.value.find((option) => option.key === sortBy.value)
                ?.label ?? sortBy.value,
    );

    const selectedSeasonTypeLabel = computed(
        () =>
            props.config.seasonTypeOptions?.find(
                (option) => option.value === selectedSeasonType.value,
            )?.label ?? null,
    );

    const showSeasonTypeHeaderBadge = computed(
        () =>
            selectedSeasonType.value === '1' &&
            selectedSeasonTypeLabel.value !== null,
    );

    onMounted(() => {
        if (categoryOptions.value.length > 0) {
            selectedCategory.value = categoryOptions.value[0]?.key ?? 'all';
        }

        fetchAvailableSeasons()
            .catch(() => {
                if (!disposed) availableSeasons.value = [];
            })
            .then(() => {
                if (disposed) return;
                seasonReady.value = true;
                fetchPlayers();
            });
    });

    watch(selectedSeason, () => {
        if (!seasonReady.value) return;
        fetchPlayers();
    });

    watch(selectedSeasonType, () => {
        if (!seasonReady.value) return;
        fetchPlayers();
    });

    watch(activeCategory, (category) => {
        const categorySort =
            category?.defaultSortBy ?? sortOptions.value[0]?.key;
        if (categorySort) {
            sortBy.value = categorySort;
            sortDesc.value = true;
        }
    });

    watch([searchQuery, sortBy, sortDesc, activeCategory], () => {
        visibleLimit.value = 100;
    });

    onBeforeUnmount(() => {
        disposed = true;
        leaderboardAbortController?.abort();
        seasonsAbortController?.abort();
    });

    return {
        ...toRefs(props),
        players,
        loading,
        error,
        searchQuery,
        selectedSeasonType,
        sortBy,
        sortDesc,
        selectedCategory,
        availableSeasons,
        selectedSeason,
        visibleLimit,
        categoryOptions,
        sortOptions,
        sortedPlayers,
        visiblePlayers,
        toggleSort,
        statColumns,
        activeSortLabel,
        selectedSeasonTypeLabel,
        showSeasonTypeHeaderBadge,
    };
}
export const playerStatsKey: InjectionKey<ReturnType<typeof usePlayerStats>> =
    Symbol('playerStats');
export function usePlayerStatsContext() {
    const context = inject(playerStatsKey);
    if (!context)
        throw new Error('Player stats sections require their page context');
    return context;
}
