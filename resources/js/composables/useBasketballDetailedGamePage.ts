import { computed, watch } from 'vue';
import { useBasketballGamePage } from '@/composables/useBasketballGamePage';
import { formatNumber, getBetterValue } from '@/composables/useFormatters';
import { formatVenueLabel } from '@/composables/useGameDataUtils';
import { useSportGameLayout } from '@/composables/useSportGameLayout';
import { trackViewItem } from '@/lib/analytics';
import type {
    ApiEnvelope,
    Game,
    GamePageHrefLike,
    LineScoreEntry,
    SportGamePageConfig,
    TeamMetric,
    TopPerformer,
} from '@/types';

interface UseBasketballDetailedGamePageOptions {
    sport: 'nba' | 'cbb' | 'wnba' | 'wcbb';
    gameId: number;
    teamLink: (id: number) => GamePageHrefLike;
    sortTopPerformers?: (players: TopPerformer[]) => TopPerformer[];
    subtitleText?: (sampleSize: number) => string;
    venueLabel?: (game: Game) => string | null;
    showLinescore?: (
        game: Game,
        homeLinescores: LineScoreEntry[],
        awayLinescores: LineScoreEntry[],
    ) => boolean;
    showPredictionSummary?: boolean;
    showTrends?: boolean;
    configOverrides?: Partial<Omit<SportGamePageConfig, 'sport' | 'teamLink'>>;
}

const defaultMetricFromResponse = (
    payload: ApiEnvelope<TeamMetric | TeamMetric[] | null>,
): TeamMetric | null => {
    if (Array.isArray(payload?.data)) return payload.data[0] || null;
    return payload?.data || null;
};

const defaultVenueLabel = (game: Game): string | null => {
    return formatVenueLabel(game.venue_name, game.venue_city);
};

const defaultShowLinescore = (
    game: Game,
    homeLinescores: LineScoreEntry[],
    awayLinescores: LineScoreEntry[],
): boolean =>
    game.status === 'STATUS_FINAL' &&
    homeLinescores.length > 0 &&
    awayLinescores.length > 0;

export function useBasketballDetailedGamePage(
    options: UseBasketballDetailedGamePageOptions,
) {
    const {
        game,
        homeTeam,
        awayTeam,
        prediction,
        homeMetrics,
        awayMetrics,
        homeTeamStats,
        awayTeamStats,
        topPerformers,
        homeTrends,
        awayTrends,
        trendsLoading,
        loading,
        error,
        topMatchupEdges,
        allTrendCategories,
        isLockedCategory,
        getRequiredTier,
        formatTierName,
        formatCategoryName,
        gameStatus,
        formatDate,
        broadcastNetworks,
        homeLinescores,
        awayLinescores,
        homeRecentForm,
        awayRecentForm,
        trendsSubtitle,
    } = useBasketballGamePage({
        sport: options.sport,
        gameId: options.gameId,
        sortTopPerformers: options.sortTopPerformers,
        metricFromResponse: defaultMetricFromResponse,
        subtitleText: options.subtitleText,
    });

    const fallbackGame: Game = {
        id: options.gameId,
        espn_id: null,
        home_team_id: 0,
        away_team_id: 0,
        season: 0,
        season_type: null,
        week: null,
        game_date: null,
        game_time: null,
        status: 'STATUS_SCHEDULED',
        period: null,
        home_score: null,
        away_score: null,
        home_linescores: null,
        away_linescores: null,
        broadcast_networks: null,
        created_at: null,
        updated_at: null,
    };
    const currentGame = computed(() => game.value ?? fallbackGame);
    const resolveVenueLabel = options.venueLabel ?? defaultVenueLabel;
    const resolveShowLinescore = options.showLinescore ?? defaultShowLinescore;

    const { config, pageProps } = useSportGameLayout({
        sport: options.sport,
        gameId: options.gameId,
        teamLink: options.teamLink,
        configOverrides: options.configOverrides,
        pageProps: {
            title: computed(
                () =>
                    `${awayTeam.value?.name || 'Away'} @ ${homeTeam.value?.name || 'Home'}`,
            ),
            loading,
            error,
            awayTeam,
            homeTeam,
            game: currentGame,
            gameStatus,
            formatDate,
            awayRecentForm,
            homeRecentForm,
            venueLabel: computed(() => resolveVenueLabel(currentGame.value)),
            broadcastNetworks,
            showLinescore: computed(() =>
                resolveShowLinescore(
                    currentGame.value,
                    homeLinescores.value,
                    awayLinescores.value,
                ),
            ),
            awayLinescores,
            homeLinescores,
            awayScore: computed(() => currentGame.value.away_score),
            homeScore: computed(() => currentGame.value.home_score),
            showPredictionSummary: computed(
                () =>
                    (options.showPredictionSummary ?? true) &&
                    !!prediction.value,
            ),
            prediction,
            awayLabel: computed(() => awayTeam.value?.abbreviation || null),
            homeLabel: computed(() => homeTeam.value?.abbreviation || null),
            formatNumber,
            showTrends: options.showTrends ?? true,
            trendsSubtitle,
            trendsLoading,
            topMatchupEdges,
            allTrendCategories,
            formatCategoryName,
            isLockedCategory,
            formatTierName,
            getRequiredTier,
            awayTrends,
            homeTrends,
        },
    });

    const insightsProps = computed(() => ({
        gameStatus: currentGame.value.status,
        awayLabel: awayTeam.value?.abbreviation || null,
        homeLabel: homeTeam.value?.abbreviation || null,
        homeTeamId: currentGame.value.home_team_id,
        homeTeamStats: homeTeamStats.value,
        awayTeamStats: awayTeamStats.value,
        topPerformers: topPerformers.value,
        performersMode:
            config.topPerformersMode ||
            (options.sport === 'cbb' ? 'table' : 'list'),
        homeMetrics: homeMetrics.value,
        awayMetrics: awayMetrics.value,
        metricsTitle:
            config.metricsTitle ||
            (options.sport === 'cbb'
                ? 'Team Metrics Comparison'
                : 'Team Stats Comparison'),
        formatNumber,
        getBetterValue,
    }));

    watch(
        () => [
            options.gameId,
            awayTeam.value?.abbreviation ?? awayTeam.value?.name ?? null,
            homeTeam.value?.abbreviation ?? homeTeam.value?.name ?? null,
        ],
        () => {
            const awayLabel =
                awayTeam.value?.abbreviation ?? awayTeam.value?.name ?? 'Away';
            const homeLabel =
                homeTeam.value?.abbreviation ?? homeTeam.value?.name ?? 'Home';

            trackViewItem({
                itemId: options.gameId,
                itemName: `${awayLabel} @ ${homeLabel}`,
                sport: options.sport,
                homeTeam: homeLabel,
                awayTeam: awayLabel,
            });
        },
        { immediate: true },
    );

    return {
        config,
        pageProps,
        insightsProps,
        homeTeam,
        awayTeam,
        homeMetrics,
        awayMetrics,
        homeTeamStats,
        awayTeamStats,
        topPerformers,
        prediction,
    };
}
