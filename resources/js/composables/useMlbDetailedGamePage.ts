import { computed, watch } from 'vue';
import { formatNumber } from '@/composables/useFormatters';
import { useGameDepthCharts } from '@/composables/useGameDepthCharts';
import {
    formatVenueLabel,
    getWinLossRecord,
} from '@/composables/useGameDataUtils';
import { useMlbGamePage } from '@/composables/useMlbGamePage';
import { useSportGameLayout } from '@/composables/useSportGameLayout';
import { trackViewItem } from '@/lib/analytics';
import { isMlbSpringTrainingType } from '@/lib/mlbSeasonType';
import MLBTeamController from '@/actions/App/Http/Controllers/MLB/TeamController';

export function useMlbDetailedGamePage(gameId: number) {
    const { depthCharts } = useGameDepthCharts('mlb', gameId);
    const {
        game: currentGame,
        homeTeam,
        awayTeam,
        prediction,
        homeTrends,
        awayTrends,
        trendsLoading,
        loading,
        error,
        gameStatus,
        formatDate,
        broadcastNetworks,
        homeLinescores,
        awayLinescores,
        homeRecentForm,
        awayRecentForm,
        homeRecentGames,
        awayRecentGames,
        homeMetrics,
        awayMetrics,
        trendsSubtitle,
        homeMatchupTeam,
        awayMatchupTeam,
        topMatchupEdges,
        allTrendCategories,
        isLockedCategory,
        getRequiredTier,
        formatTierName,
        formatCategoryName,
    } = useMlbGamePage(gameId);

    const awayLabel = computed(() => awayTeam.value?.abbreviation || null);
    const homeLabel = computed(() => homeTeam.value?.abbreviation || null);
    const awayRecord = computed(() =>
        getWinLossRecord(awayRecentGames.value, currentGame.value.away_team_id),
    );
    const homeRecord = computed(() =>
        getWinLossRecord(homeRecentGames.value, currentGame.value.home_team_id),
    );
    const contextBadgeLabel = computed(() =>
        isMlbSpringTrainingType(currentGame.value.season_type)
            ? 'Spring Training'
            : null,
    );
    const awayStarterName = computed(
        () => currentGame.value.away_starting_pitcher?.full_name ?? null,
    );
    const homeStarterName = computed(
        () => currentGame.value.home_starting_pitcher?.full_name ?? null,
    );
    const awayStarterRating = computed(
        () => currentGame.value.away_starting_pitcher?.elo_rating ?? null,
    );
    const homeStarterRating = computed(
        () => currentGame.value.home_starting_pitcher?.elo_rating ?? null,
    );
    const awayStarterSource = computed(
        () => currentGame.value.away_starting_pitcher_source ?? null,
    );
    const homeStarterSource = computed(
        () => currentGame.value.home_starting_pitcher_source ?? null,
    );
    const awayStarterConfidence = computed(
        () => currentGame.value.away_starting_pitcher_confidence ?? null,
    );
    const homeStarterConfidence = computed(
        () => currentGame.value.home_starting_pitcher_confidence ?? null,
    );
    const awayStarterForecast = computed(
        () => currentGame.value.away_starting_pitcher_forecast ?? null,
    );
    const homeStarterForecast = computed(
        () => currentGame.value.home_starting_pitcher_forecast ?? null,
    );

    const { pageProps } = useSportGameLayout({
        sport: 'mlb',
        gameId,
        teamLink: (id: number) => MLBTeamController.url(id),
        pageProps: {
            title: computed(
                () =>
                    `${awayTeam.value?.name || 'Away'} @ ${homeTeam.value?.name || 'Home'}`,
            ),
            loading,
            error,
            awayTeam: awayMatchupTeam,
            homeTeam: homeMatchupTeam,
            game: currentGame,
            gameStatus,
            formatDate,
            awayRecentForm,
            homeRecentForm,
            venueLabel: computed(() =>
                formatVenueLabel(
                    currentGame.value.venue_name,
                    currentGame.value.venue_city,
                ),
            ),
            broadcastNetworks,
            showMatchupContext: computed(
                () => !!currentGame.value.matchup_context?.rows?.length,
            ),
            matchupContext: computed(
                () => currentGame.value.matchup_context ?? null,
            ),
            showLinescore: computed(
                () =>
                    homeLinescores.value.length > 0 &&
                    awayLinescores.value.length > 0 &&
                    currentGame.value.status === 'STATUS_FINAL',
            ),
            awayLinescores,
            homeLinescores,
            awayScore: computed(() => currentGame.value.away_score),
            homeScore: computed(() => currentGame.value.home_score),
            periodPrefix: '',
            showPredictionSummary: computed(() => !!prediction.value),
            prediction,
            awayLabel,
            homeLabel,
            formatNumber,
            showTrends: true,
            contextBadgeLabel,
            awayStarterName,
            homeStarterName,
            awayStarterRating,
            homeStarterRating,
            awayStarterSource,
            homeStarterSource,
            awayStarterConfidence,
            homeStarterConfidence,
            awayStarterForecast,
            homeStarterForecast,
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

    const recentSectionProps = computed(() => ({
        section: 'recent' as const,
        awayLabel: awayLabel.value,
        homeLabel: homeLabel.value,
        awayRecord: awayRecord.value,
        homeRecord: homeRecord.value,
        awayRecentGames: awayRecentGames.value,
        homeRecentGames: homeRecentGames.value,
        awayTeamId: currentGame.value.away_team_id,
        homeTeamId: currentGame.value.home_team_id,
        gameHrefPrefix: '/mlb/games',
        awayMetrics: awayMetrics.value,
        homeMetrics: homeMetrics.value,
    }));

    watch(
        () => [
            currentGame.value.id,
            awayTeam.value?.abbreviation ?? awayTeam.value?.name ?? null,
            homeTeam.value?.abbreviation ?? homeTeam.value?.name ?? null,
        ],
        () => {
            const awayLabel =
                awayTeam.value?.abbreviation ?? awayTeam.value?.name ?? 'Away';
            const homeLabel =
                homeTeam.value?.abbreviation ?? homeTeam.value?.name ?? 'Home';

            trackViewItem({
                itemId: currentGame.value.id,
                itemName: `${awayLabel} @ ${homeLabel}`,
                sport: 'mlb',
                homeTeam: homeLabel,
                awayTeam: awayLabel,
            });
        },
        { immediate: true },
    );

    return { pageProps, recentSectionProps, depthCharts };
}
