import { computed, watch } from 'vue';
import MLBTeamController from '@/actions/App/Http/Controllers/MLB/TeamController';
import { formatNumber } from '@/composables/useFormatters';
import {
    formatVenueLabel,
    getWinLossRecord,
} from '@/composables/useGameDataUtils';
import { useMlbGamePage } from '@/composables/useMlbGamePage';
import { useSportGameLayout } from '@/composables/useSportGameLayout';
import { trackViewItem } from '@/lib/analytics';
import { isMlbSpringTrainingType } from '@/lib/mlbSeasonType';

export function useMlbDetailedGamePage(gameId: number) {
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
        trendsSubtitle,
        homeMatchupTeam,
        awayMatchupTeam,
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

    return { pageProps, recentSectionProps };
}
