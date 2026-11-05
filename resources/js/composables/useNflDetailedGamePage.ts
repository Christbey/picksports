import { computed } from 'vue';
import { useGameDepthCharts } from '@/composables/useGameDepthCharts';
import { formatNumber, getBetterValue } from '@/composables/useFormatters';
import { useNflGamePage } from '@/composables/useNflGamePage';
import { useSportGameLayout } from '@/composables/useSportGameLayout';
import { useGameViewTracking } from '@/composables/useGameViewTracking';

const formatSpread = (spread: number | string): string => {
    const numSpread = typeof spread === 'string' ? parseFloat(spread) : spread;
    if (Number.isNaN(numSpread)) return '-';
    return numSpread > 0 ? `+${numSpread.toFixed(1)}` : numSpread.toFixed(1);
};

const seasonTypeLabel = (seasonType: string | number | null): string => {
    const value = seasonType === null ? '' : String(seasonType);
    if (value === '1' || value === 'Preseason') return 'Preseason';
    if (value === '2' || value === 'Regular Season') return 'Regular Season';
    if (value === '3' || value === 'Postseason') return 'Postseason';
    return value;
};

export function useNflDetailedGamePage(gameId: number) {
    const { depthCharts } = useGameDepthCharts('nfl', gameId);
    const {
        game: currentGame,
        homeTeam,
        awayTeam,
        prediction,
        historicalPrediction,
        homeTeamStats,
        awayTeamStats,
        homeRecentGames,
        awayRecentGames,
        homeTrends,
        awayTrends,
        trendsLoading,
        loading,
        error,
        gameStatus,
        formatDate,
        homeLinescores,
        awayLinescores,
        broadcastNetworks,
        weekLabel,
        hasLivePrediction,
        livePredictionData,
        trendsSubtitle,
        topMatchupEdges,
        allTrendCategories,
        isLockedCategory,
        getRequiredTier,
        formatTierName,
        formatCategoryName,
        getNumericRecord,
        calculatePercentage,
    } = useNflGamePage(gameId);

    const awayLabel = computed(() => awayTeam.value?.abbreviation || null);
    const homeLabel = computed(() => homeTeam.value?.abbreviation || null);
    const awayRecord = computed(() =>
        getNumericRecord(awayRecentGames.value, currentGame.value.away_team_id),
    );
    const homeRecord = computed(() =>
        getNumericRecord(homeRecentGames.value, currentGame.value.home_team_id),
    );
    const predictionSectionProps = computed(() => ({
        section: 'prediction' as const,
        historicalPrediction: historicalPrediction.value,
        game: currentGame.value,
        prediction: prediction.value,
        awayLabel: awayLabel.value,
        homeLabel: homeLabel.value,
        formatNumber,
        formatSpread,
    }));
    const analysisSectionProps = computed(() => ({
        section: 'analysis' as const,
        game: currentGame.value,
        prediction: prediction.value,
        awayLabel: awayLabel.value,
        homeLabel: homeLabel.value,
        formatNumber,
        formatSpread,
        homeTeamStats: homeTeamStats.value,
        awayTeamStats: awayTeamStats.value,
        getBetterValue,
        calculatePercentage,
        hasLivePrediction: hasLivePrediction.value,
        livePredictionData: livePredictionData.value,
    }));
    const recentSectionProps = computed(() => ({
        section: 'recent' as const,
        awayLabel: awayLabel.value,
        homeLabel: homeLabel.value,
        formatNumber,
        formatSpread,
        hasLivePrediction: hasLivePrediction.value,
        awayRecord: awayRecord.value,
        homeRecord: homeRecord.value,
        awayRecentGames: awayRecentGames.value,
        homeRecentGames: homeRecentGames.value,
        awayTeamId: currentGame.value.away_team_id,
        homeTeamId: currentGame.value.home_team_id,
        gameHrefPrefix: '/nfl/games',
    }));

    const { pageProps } = useSportGameLayout({
        sport: 'nfl',
        gameId,
        teamLink: (id: number) => `/nfl/teams/${id}`,
        pageProps: {
            title: computed(
                () =>
                    `${awayTeam.value?.abbreviation || 'Away'} @ ${homeTeam.value?.abbreviation || 'Home'}`,
            ),
            loading,
            error,
            awayTeam,
            homeTeam,
            game: currentGame,
            gameStatus,
            formatDate: computed(
                () => (dateString: string | null) =>
                    formatDate(dateString || ''),
            ),
            venueLabel: computed(() => currentGame.value.venue),
            broadcastNetworks,
            showMatchupContext: computed(
                () => !!currentGame.value.matchup_context?.rows?.length,
            ),
            matchupContext: computed(
                () => currentGame.value.matchup_context ?? null,
            ),
            extraInfoItems: computed(() =>
                weekLabel.value
                    ? [
                          `${seasonTypeLabel(currentGame.value.season_type)} - ${weekLabel.value}`,
                      ]
                    : [],
            ),
            showScoreStatuses: [
                'STATUS_FINAL',
                'STATUS_IN_PROGRESS',
                'STATUS_HALFTIME',
            ],
            badgePulseStatuses: [],
            useTeamColorGlow: true,
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
            showTrends: computed(
                () => !!(homeTrends.value || awayTrends.value),
            ),
            trendsSubtitle,
            trendsLoading,
            topMatchupEdges,
            allTrendCategories,
            formatCategoryName,
            isLockedCategory,
            formatTierName,
            getRequiredTier,
            awayLabel,
            homeLabel,
            awayTrends,
            homeTrends,
        },
    });

    useGameViewTracking({
        gameId: () => currentGame.value.id,
        sport: () => 'nfl',
        awayTeam: () => awayTeam.value,
        homeTeam: () => homeTeam.value,
    });

    return {
        pageProps,
        predictionSectionProps,
        analysisSectionProps,
        recentSectionProps,
        depthCharts,
    };
}
