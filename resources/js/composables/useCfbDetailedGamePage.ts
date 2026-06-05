import { computed, onMounted, ref, watch } from 'vue';
import { fetchJson } from '@/composables/useApiClient';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { flattenApiV2Stats } from '@/composables/useApiV2StatsAdapter';
import {
    formatNumber,
    getBetterValue,
    useGameStatus,
} from '@/composables/useFormatters';
import { formatDateLong } from '@/composables/useFormatters';
import {
    calculatePercentage,
    parseBroadcastNetworks,
    parseLinescores,
} from '@/composables/useGameDataUtils';
import { useSportGameLayoutFromConfig } from '@/composables/useSportGameLayout';
import { useTeamTrends } from '@/composables/useTeamTrends';
import { trackViewItem } from '@/lib/analytics';
import { getCfbPostseasonLabel } from '@/lib/cfbPostseason';
import type {
    NflPageGame,
    NflPagePrediction,
    NflTeamStats,
    RecentGameListItem,
    TeamTrendData,
} from '@/types';

const fallbackGame = (gameId: number): NflPageGame => ({
    id: gameId,
    status: 'STATUS_SCHEDULED',
    game_date: null,
    away_score: null,
    home_score: null,
    home_team_id: 0,
    away_team_id: 0,
    season: 0,
    season_type: '',
    week: 0,
    game_time: '',
    venue: '',
});

const formatSpread = (spread: number | string): string => {
    const numSpread = typeof spread === 'string' ? parseFloat(spread) : spread;
    if (Number.isNaN(numSpread)) return '-';
    return numSpread > 0 ? `+${numSpread.toFixed(1)}` : numSpread.toFixed(1);
};

const weekLabelForGame = (game: NflPageGame): string => {
    if (!game.week || !game.season_type) return '';

    const seasonType = String(game.season_type);
    if (seasonType === 'Regular Season' || seasonType === '2') {
        return `Week ${game.week}`;
    }

    return (
        getCfbPostseasonLabel(game.postseason_round, game.week) ||
        `Postseason Week ${game.week}`
    );
};

const seasonTypeLabel = (seasonType: string): string => {
    if (seasonType === '2' || seasonType === 'Regular Season')
        return 'Regular Season';
    if (seasonType === '3' || seasonType === 'Postseason') return 'Postseason';

    return seasonType;
};

export function useCfbDetailedGamePage(gameId: number) {
    const api = useApiV2Client();
    const currentGame = ref<NflPageGame>(fallbackGame(gameId));
    const homeTeam = ref<any | null>(null);
    const awayTeam = ref<any | null>(null);
    const prediction = ref<NflPagePrediction | null>(null);
    const homeTeamStats = ref<NflTeamStats | null>(null);
    const awayTeamStats = ref<NflTeamStats | null>(null);
    const homeRecentGames = ref<RecentGameListItem[]>([]);
    const awayRecentGames = ref<RecentGameListItem[]>([]);
    const homeTrends = ref<TeamTrendData | null>(null);
    const awayTrends = ref<TeamTrendData | null>(null);
    const loading = ref(true);
    const error = ref<string | null>(null);

    const gameStatus = useGameStatus(() => currentGame.value.status);
    const formatDate = (dateString: string | null): string =>
        formatDateLong(dateString || '');
    const homeLinescores = computed(() =>
        parseLinescores(currentGame.value.home_linescores),
    );
    const awayLinescores = computed(() =>
        parseLinescores(currentGame.value.away_linescores),
    );
    const broadcastNetworks = computed(() =>
        parseBroadcastNetworks(currentGame.value.broadcast_networks),
    );
    const weekLabel = computed(() => weekLabelForGame(currentGame.value));

    const hasLivePrediction = computed(() => false);
    const livePredictionData = computed(() => undefined);
    const trendsSubtitle = computed(
        () =>
            `${currentGame.value.season} Season (${homeTrends.value?.sample_size || awayTrends.value?.sample_size || 0} games)`,
    );

    const {
        topMatchupEdges,
        allTrendCategories,
        isLockedCategory,
        getRequiredTier,
        formatTierName,
        formatTrendCategoryName: formatCategoryName,
    } = useTeamTrends(homeTrends, awayTrends);

    const getNumericRecord = (
        games: RecentGameListItem[],
        teamId: number,
    ): string => {
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

            const [gameData, predictionData, teamStatsData] = await Promise.all(
                [
                    api.games.show('cfb', gameId),
                    fetchJson<{
                        data: NflPagePrediction | NflPagePrediction[];
                    }>(`/api/v1/cfb/games/${gameId}/prediction`),
                    api.stats.teams('cfb', {
                        query: { game_id: gameId, per_page: 100 },
                    }),
                ],
            );

            if (gameData?.data) {
                const fullGame = gameData.data as NflPageGame;
                currentGame.value = fullGame;
                const fallback = fullGame as NflPageGame & {
                    homeTeam?: NflPageGame['home_team'];
                    awayTeam?: NflPageGame['away_team'];
                };
                homeTeam.value =
                    fullGame.home_team || fallback.homeTeam || null;
                awayTeam.value =
                    fullGame.away_team || fallback.awayTeam || null;

                if (fullGame.home_team?.id || fallback.homeTeam?.id) {
                    const homeTeamId =
                        fullGame.home_team?.id || fallback.homeTeam?.id;
                    const [homeGamesData, homeTrendsData] = await Promise.all([
                        api.teams.games('cfb', homeTeamId),
                        api.teams.trends('cfb', homeTeamId, {
                            query: {
                                games: 'season',
                                season: currentGame.value.season,
                                before_date: currentGame.value.game_date,
                            },
                        }),
                    ]);

                    if (homeGamesData?.data) {
                        homeRecentGames.value = (homeGamesData.data || [])
                            .filter(
                                (g) =>
                                    g.status === 'STATUS_FINAL' &&
                                    g.id !== currentGame.value.id,
                            )
                            .slice(0, 5);
                    }

                    if (homeTrendsData?.data) {
                        homeTrends.value = homeTrendsData.data as TeamTrendData;
                    }
                }

                if (fullGame.away_team?.id || fallback.awayTeam?.id) {
                    const awayTeamId =
                        fullGame.away_team?.id || fallback.awayTeam?.id;
                    const [awayGamesData, awayTrendsData] = await Promise.all([
                        api.teams.games('cfb', awayTeamId),
                        api.teams.trends('cfb', awayTeamId, {
                            query: {
                                games: 'season',
                                season: currentGame.value.season,
                                before_date: currentGame.value.game_date,
                            },
                        }),
                    ]);

                    if (awayGamesData?.data) {
                        awayRecentGames.value = (awayGamesData.data || [])
                            .filter(
                                (g) =>
                                    g.status === 'STATUS_FINAL' &&
                                    g.id !== currentGame.value.id,
                            )
                            .slice(0, 5);
                    }

                    if (awayTrendsData?.data) {
                        awayTrends.value = awayTrendsData.data as TeamTrendData;
                    }
                }
            }

            if (predictionData?.data) {
                prediction.value = Array.isArray(predictionData.data)
                    ? (predictionData.data[0] ?? null)
                    : predictionData.data;
            }

            if (teamStatsData?.data) {
                const stats = flattenApiV2Stats(
                    teamStatsData.data,
                ) as NflTeamStats[];
                homeTeamStats.value =
                    stats.find((s) => s.team_type === 'home') || null;
                awayTeamStats.value =
                    stats.find((s) => s.team_type === 'away') || null;
            }
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'An error occurred';
        } finally {
            loading.value = false;
        }
    };

    onMounted(load);

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
        gameHrefPrefix: '/cfb/games',
    }));

    const { pageProps } = useSportGameLayoutFromConfig({
        gameId,
        config: {
            sport: 'cfb',
            sportLabel: 'CFB',
            predictionsHref: '/cfb/predictions',
            gameHrefPrefix: '/cfb/games',
            teamLink: () => '/cfb/predictions',
            gradientClass:
                'bg-gradient-to-r from-green-600 to-green-800 dark:from-green-800 dark:to-green-950',
            projectedLabel: 'Projected points',
            linescoreTitle: 'Quarter by Quarter',
            linescoreUsePeriodNumbers: true,
            trendsEmptyText: 'No trends available for this matchup',
        },
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
            formatDate: computed(() => formatDate),
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
            badgePulseStatuses: ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME'],
            linkTeams: false,
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

    watch(
        () => [
            currentGame.value.id,
            awayTeam.value?.abbreviation ?? awayTeam.value?.name ?? null,
            homeTeam.value?.abbreviation ?? homeTeam.value?.name ?? null,
        ],
        () => {
            const away =
                awayTeam.value?.abbreviation ?? awayTeam.value?.name ?? 'Away';
            const home =
                homeTeam.value?.abbreviation ?? homeTeam.value?.name ?? 'Home';

            trackViewItem({
                itemId: currentGame.value.id,
                itemName: `${away} @ ${home}`,
                sport: 'cfb',
                homeTeam: home,
                awayTeam: away,
            });
        },
        { immediate: true },
    );

    return {
        pageProps,
        predictionSectionProps,
        analysisSectionProps,
        recentSectionProps,
    };
}
