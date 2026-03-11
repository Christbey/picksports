import { computed, onMounted, ref } from 'vue';
import { fetchJson } from '@/composables/useApiClient';
import { formatDateLong, useGameStatus } from '@/composables/useFormatters';
import { getRecentForm, parseBroadcastNetworks, parseLinescores } from '@/composables/useGameDataUtils';
import { useTeamTrends } from '@/composables/useTeamTrends';
import type { MlbPageGame, MlbPagePrediction, MlbPageTeam, TeamTrendData } from '@/types';

interface MlbMatchupTeam extends MlbPageTeam {
    logo: string | null;
    display_name: string;
}

const fallbackGame = (gameId: number): MlbPageGame => ({
    id: gameId,
    status: 'STATUS_SCHEDULED',
    game_date: null,
    away_score: null,
    home_score: null,
    home_team_id: 0,
    away_team_id: 0,
    home_linescores: null,
    away_linescores: null,
    inning: null,
    inning_half: null,
    venue_name: null,
    venue_city: null,
    venue_state: null,
    broadcast_networks: null,
    season: 0,
    season_type: '',
});

export function useMlbGamePage(gameId: number) {
    const currentGame = ref<MlbPageGame>(fallbackGame(gameId));
    const homeTeam = ref<MlbPageTeam | null>(null);
    const awayTeam = ref<MlbPageTeam | null>(null);
    const prediction = ref<MlbPagePrediction | null>(null);
    const homeRecentGames = ref<MlbPageGame[]>([]);
    const awayRecentGames = ref<MlbPageGame[]>([]);
    const homeTrends = ref<TeamTrendData | null>(null);
    const awayTrends = ref<TeamTrendData | null>(null);
    const trendsLoading = ref(false);
    const loading = ref(true);
    const error = ref<string | null>(null);

    const gameStatus = useGameStatus(() => currentGame.value.status);
    const formatDate = (dateString: string | null): string => formatDateLong(dateString);
    const broadcastNetworks = computed(() => parseBroadcastNetworks(currentGame.value.broadcast_networks));
    const homeLinescores = computed(() => parseLinescores(currentGame.value.home_linescores));
    const awayLinescores = computed(() => parseLinescores(currentGame.value.away_linescores));
    const homeRecentForm = computed(() => (homeTeam.value ? getRecentForm(homeRecentGames.value, homeTeam.value.id) : ''));
    const awayRecentForm = computed(() => (awayTeam.value ? getRecentForm(awayRecentGames.value, awayTeam.value.id) : ''));
    const trendsSubtitle = computed(() => {
        const sampleSize = homeTrends.value?.sample_size || awayTrends.value?.sample_size || 20;
        return `Based on current season form (${sampleSize} games before this matchup)`;
    });
    const homeMatchupTeam = computed<MlbMatchupTeam | null>(() => (
        homeTeam.value
            ? { ...homeTeam.value, logo: homeTeam.value.logo_url, display_name: `${homeTeam.value.location} ${homeTeam.value.name}`.trim() }
            : null
    ));
    const awayMatchupTeam = computed<MlbMatchupTeam | null>(() => (
        awayTeam.value
            ? { ...awayTeam.value, logo: awayTeam.value.logo_url, display_name: `${awayTeam.value.location} ${awayTeam.value.name}`.trim() }
            : null
    ));

    const {
        allTrendCategories,
        isLockedCategory,
        getRequiredTier,
        formatTierName,
        formatTrendCategoryName: formatCategoryName,
    } = useTeamTrends(homeTrends, awayTrends);

    const load = async () => {
        try {
            loading.value = true;
            error.value = null;

            const [gameData, predictionData] = await Promise.all([
                fetchJson<{ data: MlbPageGame }>(`/api/v1/mlb/games/${gameId}`),
                fetchJson<{ data: MlbPagePrediction }>(`/api/v1/mlb/games/${gameId}/prediction`),
            ]);

            if (gameData?.data) {
                currentGame.value = gameData.data;
            }

            const [homeTeamData, awayTeamData] = await Promise.all([
                fetchJson<{ data: MlbPageTeam }>(`/api/v1/mlb/teams/${currentGame.value.home_team_id}`),
                fetchJson<{ data: MlbPageTeam }>(`/api/v1/mlb/teams/${currentGame.value.away_team_id}`),
            ]);

            if (homeTeamData?.data) {
                homeTeam.value = homeTeamData.data;
            }

            if (awayTeamData?.data) {
                awayTeam.value = awayTeamData.data;
            }

            if (predictionData?.data) {
                prediction.value = predictionData.data;
            }

            const teamRequests: Promise<void>[] = [];

            if (homeTeam.value?.id) {
                teamRequests.push(
                    fetchJson<{ data: MlbPageGame[] }>(`/api/v1/mlb/teams/${homeTeam.value.id}/games`)
                        .then((gamesData) => {
                            if (!gamesData?.data) return;
                            homeRecentGames.value = gamesData.data
                                .filter((g) => g.status === 'STATUS_FINAL' && g.id !== currentGame.value.id)
                                .slice(0, 5);
                        }),
                );
            }

            if (awayTeam.value?.id) {
                teamRequests.push(
                    fetchJson<{ data: MlbPageGame[] }>(`/api/v1/mlb/teams/${awayTeam.value.id}/games`)
                        .then((gamesData) => {
                            if (!gamesData?.data) return;
                            awayRecentGames.value = gamesData.data
                                .filter((g) => g.status === 'STATUS_FINAL' && g.id !== currentGame.value.id)
                                .slice(0, 5);
                        }),
                );
            }

            if (homeTeam.value?.id || awayTeam.value?.id) {
                trendsLoading.value = true;
                const beforeDate = currentGame.value.game_date || '';

                if (homeTeam.value?.id) {
                    teamRequests.push(
                        fetchJson<TeamTrendData>(`/api/v1/mlb/teams/${homeTeam.value.id}/trends?games=season&season=${currentGame.value.season}&before_date=${beforeDate}`)
                            .then((data) => { homeTrends.value = data; })
                            .catch(() => { homeTrends.value = null; }),
                    );
                }

                if (awayTeam.value?.id) {
                    teamRequests.push(
                        fetchJson<TeamTrendData>(`/api/v1/mlb/teams/${awayTeam.value.id}/trends?games=season&season=${currentGame.value.season}&before_date=${beforeDate}`)
                            .then((data) => { awayTrends.value = data; })
                            .catch(() => { awayTrends.value = null; }),
                    );
                }
            }

            if (teamRequests.length > 0) {
                await Promise.all(teamRequests);
            }
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'An error occurred';
        } finally {
            loading.value = false;
            trendsLoading.value = false;
        }
    };

    onMounted(load);

    return {
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
        homeRecentGames,
        awayRecentGames,
        homeRecentForm,
        awayRecentForm,
        trendsSubtitle,
        homeMatchupTeam,
        awayMatchupTeam,
        allTrendCategories,
        isLockedCategory,
        getRequiredTier,
        formatTierName,
        formatCategoryName,
    };
}
