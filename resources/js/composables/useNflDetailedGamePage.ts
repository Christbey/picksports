import { computed } from 'vue';
import NFLTeamController from '@/actions/App/Http/Controllers/NFL/TeamController';
import { formatNumber, getBetterValue } from '@/composables/useFormatters';
import { useNflGamePage } from '@/composables/useNflGamePage';
import { useSportGameLayout } from '@/composables/useSportGameLayout';
import type { NflPageGame } from '@/types';

const formatSpread = (spread: number | string): string => {
    const numSpread = typeof spread === 'string' ? parseFloat(spread) : spread;
    if (Number.isNaN(numSpread)) return '-';
    return numSpread > 0 ? `+${numSpread.toFixed(1)}` : numSpread.toFixed(1);
};

export function useNflDetailedGamePage(game: NflPageGame) {
    const {
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
    } = useNflGamePage(game);

    const awayLabel = computed(() => awayTeam.value?.abbreviation || null);
    const homeLabel = computed(() => homeTeam.value?.abbreviation || null);
    const awayRecord = computed(() =>
        getNumericRecord(awayRecentGames.value, game.away_team_id),
    );
    const homeRecord = computed(() =>
        getNumericRecord(homeRecentGames.value, game.home_team_id),
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
        awayTeamId: game.away_team_id,
        homeTeamId: game.home_team_id,
        gameHrefPrefix: '/nfl/games',
    }));

    const { pageProps } = useSportGameLayout({
        sport: 'nfl',
        gameId: game.id,
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
            game,
            gameStatus,
            formatDate: computed(
                () => (dateString: string | null) => formatDate(dateString || ''),
            ),
            venueLabel: computed(() => game.venue),
            broadcastNetworks,
            extraInfoItems: computed(() =>
                weekLabel.value ? [`${game.season_type} - ${weekLabel.value}`] : [],
            ),
            showScoreStatuses: ['STATUS_FINAL', 'STATUS_IN_PROGRESS', 'STATUS_HALFTIME'],
            badgePulseStatuses: ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME'],
            useTeamColorGlow: true,
            showLinescore: computed(
                () =>
                    homeLinescores.value.length > 0
                    && awayLinescores.value.length > 0
                    && game.status === 'STATUS_FINAL',
            ),
            awayLinescores,
            homeLinescores,
            awayScore: computed(() => game.away_score),
            homeScore: computed(() => game.home_score),
            showTrends: computed(() => !!(homeTrends.value || awayTrends.value)),
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

    return {
        pageProps,
        predictionSectionProps,
        analysisSectionProps,
        recentSectionProps,
    };
}
