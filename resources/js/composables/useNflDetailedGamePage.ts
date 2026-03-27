import { computed, watch } from 'vue';
import { useGameDepthCharts } from '@/composables/useGameDepthCharts';
import { formatNumber, getBetterValue } from '@/composables/useFormatters';
import { useNflGamePage } from '@/composables/useNflGamePage';
import { useSportGameLayout } from '@/composables/useSportGameLayout';
import { trackViewItem } from '@/lib/analytics';
import NFLTeamController from '@/actions/App/Http/Controllers/NFL/TeamController';

const formatSpread = (spread: number | string): string => {
    const numSpread = typeof spread === 'string' ? parseFloat(spread) : spread;
    if (Number.isNaN(numSpread)) return '-';
    return numSpread > 0 ? `+${numSpread.toFixed(1)}` : numSpread.toFixed(1);
};

export function useNflDetailedGamePage(gameId: number) {
    const { depthCharts } = useGameDepthCharts('nfl', gameId);
    const {
        game: currentGame,
        homeTeam,
        awayTeam,
        prediction,
        homeTeamStats,
        awayTeamStats,
        homeRecentGames,
        awayRecentGames,
        homeTrends,
        awayTrends,
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
        prediction: prediction.value,
        awayLabel: awayLabel.value,
        homeLabel: homeLabel.value,
        formatNumber,
        formatSpread,
    }));
    const analysisSectionProps = computed(() => ({
        section: 'analysis' as const,
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
        teamLink: (id: number) => NFLTeamController.url(id),
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
                    ? [`${currentGame.value.season_type} - ${weekLabel.value}`]
                    : [],
            ),
            showScoreStatuses: [
                'STATUS_FINAL',
                'STATUS_IN_PROGRESS',
                'STATUS_HALFTIME',
            ],
            badgePulseStatuses: ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME'],
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
            trendsLoading: false,
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
                sport: 'nfl',
                homeTeam: homeLabel,
                awayTeam: awayLabel,
            });
        },
        { immediate: true },
    );

    return {
        pageProps,
        predictionSectionProps,
        analysisSectionProps,
        recentSectionProps,
        depthCharts,
    };
}
