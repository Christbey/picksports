import { computed } from 'vue';
import { useBasketballGamePage } from '@/composables/useBasketballGamePage';
import { formatNumber, getBetterValue } from '@/composables/useFormatters';
import { formatVenueLabel } from '@/composables/useGameDataUtils';
import { useSportGameLayout } from '@/composables/useSportGameLayout';
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
    game: Game;
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
    game.status === 'STATUS_FINAL'
    && homeLinescores.length > 0
    && awayLinescores.length > 0;

export function useBasketballDetailedGamePage(
    options: UseBasketballDetailedGamePageOptions,
) {
    const {
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
        game: options.game,
        sortTopPerformers: options.sortTopPerformers,
        metricFromResponse: defaultMetricFromResponse,
        subtitleText: options.subtitleText,
    });

    const resolveVenueLabel = options.venueLabel ?? defaultVenueLabel;
    const resolveShowLinescore = options.showLinescore ?? defaultShowLinescore;

    const { config, pageProps } = useSportGameLayout({
        sport: options.sport,
        gameId: options.game.id,
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
            game: options.game,
            gameStatus,
            formatDate,
            awayRecentForm,
            homeRecentForm,
            venueLabel: computed(() => resolveVenueLabel(options.game)),
            broadcastNetworks,
            showLinescore: computed(() =>
                resolveShowLinescore(
                    options.game,
                    homeLinescores.value,
                    awayLinescores.value,
                )),
            awayLinescores,
            homeLinescores,
            awayScore: computed(() => options.game.away_score),
            homeScore: computed(() => options.game.home_score),
            showPredictionSummary: computed(
                () =>
                    (options.showPredictionSummary ?? true)
                    && !!prediction.value,
            ),
            prediction,
            awayLabel: computed(() => awayTeam.value?.abbreviation || null),
            homeLabel: computed(() => homeTeam.value?.abbreviation || null),
            formatNumber,
            showTrends: options.showTrends ?? true,
            trendsSubtitle,
            trendsLoading,
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
        gameStatus: options.game.status,
        awayLabel: awayTeam.value?.abbreviation || null,
        homeLabel: homeTeam.value?.abbreviation || null,
        homeTeamId: options.game.home_team_id,
        homeTeamStats: homeTeamStats.value,
        awayTeamStats: awayTeamStats.value,
        topPerformers: topPerformers.value,
        performersMode:
            config.topPerformersMode
            || (options.sport === 'cbb' ? 'table' : 'list'),
        homeMetrics: homeMetrics.value,
        awayMetrics: awayMetrics.value,
        metricsTitle:
            config.metricsTitle
            || (options.sport === 'cbb'
                ? 'Team Metrics Comparison'
                : 'Team Stats Comparison'),
        formatNumber,
        getBetterValue,
    }));

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
