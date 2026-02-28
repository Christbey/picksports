import { computed, onMounted, ref } from 'vue';
import type { LivePredictionData } from '@/components/BettingAnalysisCard.vue';
import { fetchJson } from '@/composables/useApiClient';
import { formatDateLong, useGameStatus } from '@/composables/useFormatters';
import { calculatePercentage, parseBroadcastNetworks, parseLinescores } from '@/composables/useGameDataUtils';
import { useTeamTrends } from '@/composables/useTeamTrends';
import type { NflPageGame, NflPagePrediction, NflPageTeam, NflTeamStats, RecentGameListItem, TeamTrendData } from '@/types';

export function useNflGamePage(game: NflPageGame) {
    const homeTeam = ref<NflPageTeam | null>(null);
    const awayTeam = ref<NflPageTeam | null>(null);
    const prediction = ref<NflPagePrediction | null>(null);
    const homeTeamStats = ref<NflTeamStats | null>(null);
    const awayTeamStats = ref<NflTeamStats | null>(null);
    const homeRecentGames = ref<RecentGameListItem[]>([]);
    const awayRecentGames = ref<RecentGameListItem[]>([]);
    const homeTrends = ref<TeamTrendData | null>(null);
    const awayTrends = ref<TeamTrendData | null>(null);
    const loading = ref(true);
    const error = ref<string | null>(null);

    const gameStatus = useGameStatus(() => game.status);
    const formatDate = (dateString: string, timeString?: string): string => {
        void timeString;
        return formatDateLong(dateString);
    };
    const homeLinescores = computed(() => parseLinescores(game.home_linescores));
    const awayLinescores = computed(() => parseLinescores(game.away_linescores));
    const broadcastNetworks = computed(() => parseBroadcastNetworks(game.broadcast_networks));
    const weekLabel = computed(() => {
        if (!game.week || !game.season_type) return '';

        if (game.season_type === 'Regular Season') {
            return `Week ${game.week}`;
        }

        const playoffRounds: Record<number, string> = {
            1: 'Wild Card',
            2: 'Divisional',
            3: 'Conference Championship',
            5: 'Super Bowl',
        };

        return playoffRounds[game.week] || `Playoff Week ${game.week}`;
    });

    const hasLivePrediction = computed(() => prediction.value?.live_win_probability !== null && prediction.value?.live_win_probability !== undefined);
    const livePredictionData = computed((): LivePredictionData | undefined => {
        if (!hasLivePrediction.value || !prediction.value) return undefined;
        return {
            isLive: true,
            homeScore: game.home_score,
            awayScore: game.away_score,
            status: game.status,
            liveWinProbability: prediction.value.live_win_probability as number | null,
            livePredictedSpread: prediction.value.live_predicted_spread as number | null,
            livePredictedTotal: prediction.value.live_predicted_total as number | null,
            liveSecondsRemaining: prediction.value.live_seconds_remaining,
            preGameWinProbability: Number(prediction.value.win_probability),
            preGamePredictedSpread: Number(prediction.value.predicted_spread),
            preGamePredictedTotal: Number(prediction.value.predicted_total),
        };
    });

    const trendsSubtitle = computed(() => `${game.season} Season (${homeTrends.value?.sample_size || awayTrends.value?.sample_size || 0} games)`);

    const {
        allTrendCategories,
        isLockedCategory,
        getRequiredTier,
        formatTierName,
        formatTrendCategoryName: formatCategoryName,
    } = useTeamTrends(homeTrends, awayTrends);

    const getNumericRecord = (games: RecentGameListItem[], teamId: number): string => {
        const wins = games.filter((g) => {
            const isHome = g.home_team_id === teamId;
            const teamScore = isHome ? g.home_score : g.away_score;
            const oppScore = isHome ? g.away_score : g.home_score;
            return teamScore && oppScore && teamScore > oppScore;
        }).length;
        const losses = games.length - wins;
        return `${wins}-${losses}`;
    };

    const load = async () => {
        try {
            loading.value = true;
            error.value = null;

            const [gameData, predictionData, teamStatsData] = await Promise.all([
                fetchJson<{ data: NflPageGame }>(`/api/v1/nfl/games/${game.id}`),
                fetchJson<{ data: NflPagePrediction | NflPagePrediction[] }>(`/api/v1/nfl/games/${game.id}/prediction`),
                fetchJson<{ data: NflTeamStats[] }>(`/api/v1/nfl/games/${game.id}/team-stats`),
            ]);

            if (gameData?.data) {
                const fullGame = gameData.data;
                const fallbackGame = fullGame as NflPageGame & { homeTeam?: NflPageGame['home_team']; awayTeam?: NflPageGame['away_team'] };
                homeTeam.value = fullGame.home_team || fallbackGame.homeTeam || null;
                awayTeam.value = fullGame.away_team || fallbackGame.awayTeam || null;

                if (fullGame.home_team?.id || fallbackGame.homeTeam?.id) {
                    const homeTeamId = fullGame.home_team?.id || fallbackGame.homeTeam?.id;
                    const [homeGamesData, homeTrendsData] = await Promise.all([
                        fetchJson<{ data: RecentGameListItem[] }>(`/api/v1/nfl/teams/${homeTeamId}/games`),
                        fetchJson<TeamTrendData>(`/api/v1/nfl/teams/${homeTeamId}/trends?games=21&season=${game.season}&before_date=${game.game_date}`),
                    ]);
                    if (homeGamesData?.data) {
                        homeRecentGames.value = (homeGamesData.data || [])
                            .filter((g) => g.status === 'STATUS_FINAL' && g.id !== game.id)
                            .slice(0, 5);
                    }
                    if (homeTrendsData) {
                        homeTrends.value = homeTrendsData;
                    }
                }

                if (fullGame.away_team?.id || fallbackGame.awayTeam?.id) {
                    const awayTeamId = fullGame.away_team?.id || fallbackGame.awayTeam?.id;
                    const [awayGamesData, awayTrendsData] = await Promise.all([
                        fetchJson<{ data: RecentGameListItem[] }>(`/api/v1/nfl/teams/${awayTeamId}/games`),
                        fetchJson<TeamTrendData>(`/api/v1/nfl/teams/${awayTeamId}/trends?games=21&season=${game.season}&before_date=${game.game_date}`),
                    ]);
                    if (awayGamesData?.data) {
                        awayRecentGames.value = (awayGamesData.data || [])
                            .filter((g) => g.status === 'STATUS_FINAL' && g.id !== game.id)
                            .slice(0, 5);
                    }
                    if (awayTrendsData) {
                        awayTrends.value = awayTrendsData;
                    }
                }
            }

            if (predictionData?.data) {
                prediction.value = Array.isArray(predictionData.data) ? (predictionData.data[0] ?? null) : predictionData.data;
            }

            if (teamStatsData?.data) {
                const stats = teamStatsData.data || [];
                homeTeamStats.value = stats.find((s) => s.team_type === 'home') || null;
                awayTeamStats.value = stats.find((s) => s.team_type === 'away') || null;
            }
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'An error occurred';
        } finally {
            loading.value = false;
        }
    };

    onMounted(load);

    return {
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
    };
}
