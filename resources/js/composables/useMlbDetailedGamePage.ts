import { computed, watch } from 'vue';
import MLBTeamController from '@/actions/App/Http/Controllers/MLB/TeamController';
import { formatNumber } from '@/composables/useFormatters';
import { formatVenueLabel, getWinLossRecord } from '@/composables/useGameDataUtils';
import { useMlbGamePage } from '@/composables/useMlbGamePage';
import { useSportGameLayout } from '@/composables/useSportGameLayout';
import { trackViewItem } from '@/lib/analytics';
import { isMlbSpringTrainingType } from '@/lib/mlbSeasonType';
import type { MlbPageGame } from '@/types';

export function useMlbDetailedGamePage(game: MlbPageGame) {
    const {
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
    } = useMlbGamePage(game);

    const awayLabel = computed(() => awayTeam.value?.abbreviation || null);
    const homeLabel = computed(() => homeTeam.value?.abbreviation || null);
    const awayRecord = computed(() =>
        getWinLossRecord(awayRecentGames.value, game.away_team_id),
    );
    const homeRecord = computed(() =>
        getWinLossRecord(homeRecentGames.value, game.home_team_id),
    );
    const contextBadgeLabel = computed(() =>
        isMlbSpringTrainingType(game.season_type) ? 'Spring Training' : null,
    );

    const { pageProps } = useSportGameLayout({
        sport: 'mlb',
        gameId: game.id,
        teamLink: (id: number) => MLBTeamController.url(id),
        pageProps: {
            title: computed(
                () => `${awayTeam.value?.name || 'Away'} @ ${homeTeam.value?.name || 'Home'}`,
            ),
            loading,
            error,
            awayTeam: awayMatchupTeam,
            homeTeam: homeMatchupTeam,
            game,
            gameStatus,
            formatDate,
            awayRecentForm,
            homeRecentForm,
            venueLabel: computed(() => formatVenueLabel(game.venue_name, game.venue_city)),
            broadcastNetworks,
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
        awayTeamId: game.away_team_id,
        homeTeamId: game.home_team_id,
        gameHrefPrefix: '/mlb/games',
    }));

    watch(
        () => [
            game.id,
            awayTeam.value?.abbreviation ?? awayTeam.value?.name ?? null,
            homeTeam.value?.abbreviation ?? homeTeam.value?.name ?? null,
        ],
        () => {
            const awayLabel = awayTeam.value?.abbreviation ?? awayTeam.value?.name ?? 'Away';
            const homeLabel = homeTeam.value?.abbreviation ?? homeTeam.value?.name ?? 'Home';

            trackViewItem({
                itemId: game.id,
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
