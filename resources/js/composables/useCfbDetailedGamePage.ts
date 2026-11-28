import { isFinalGameStatus } from '@/lib/gameStatus';
import { recentGamesBefore, recentGameQuery } from '@/lib/recentGameEvidence';
import { predictionHomeMargin } from '@/lib/predictionSpread';
import { optionalPredictionNumber } from '@/lib/predictionNormalization';
import { recentRecord } from '@/lib/gameOutcome';
import { ApiError } from '@/composables/useApiClient';
import { useAsyncPolling } from '@/composables/useAsyncPolling';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
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
import { useGameViewTracking } from '@/composables/useGameViewTracking';
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
    if (
        game.week === null ||
        game.week === undefined ||
        String(game.week) === '' ||
        !game.season_type
    )
        return '';

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
    const trendsLoading = ref(false);
    const loading = ref(true);
    const error = ref<string | null>(null);
    const requestController = new AbortController();

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

    const getNumericRecord = recentRecord;

    const load = async () => {
        try {
            loading.value = true;
            error.value = null;

            const requestOptions = {
                init: { signal: requestController.signal },
            };
            const [gameData, predictionData] = await Promise.all([
                api.games.show('cfb', gameId, requestOptions),
                api.predictions
                    .forGame('cfb', gameId, requestOptions)
                    .catch((caught: unknown) => {
                        if (caught instanceof ApiError && caught.status === 404)
                            return null;
                        throw caught;
                    }),
            ]);

            if (requestController.signal.aborted) return;
            if (gameData?.data) {
                const fullGame = gameData.data as unknown as NflPageGame;
                currentGame.value = fullGame;
                const fallback = fullGame as NflPageGame & {
                    homeTeam?: NflPageGame['home_team'];
                    awayTeam?: NflPageGame['away_team'];
                };
                homeTeam.value =
                    fullGame.home_team || fallback.homeTeam || null;
                awayTeam.value =
                    fullGame.away_team || fallback.awayTeam || null;
            }

            if (predictionData?.data) {
                const raw = predictionData.data as unknown as Record<
                    string,
                    unknown
                >;
                prediction.value = {
                    ...raw,
                    spread_convention: 'home_margin',
                    predicted_spread: predictionHomeMargin(raw) ?? '',
                    predicted_total:
                        optionalPredictionNumber(raw.predicted_total) ?? '',
                    win_probability:
                        optionalPredictionNumber(raw.win_probability) ?? '',
                } as unknown as NflPagePrediction;
            }

            loading.value = false;
            trendsLoading.value = true;

            const trendQuery = {
                games: 'season',
                season: currentGame.value.season,
                before_date: currentGame.value.game_date,
            };
            const supplemental: Promise<unknown>[] = [
                api.stats
                    .teams('cfb', {
                        query: { game_id: gameId, per_page: 100 },
                        init: { signal: requestController.signal },
                    })
                    .then((response) => {
                        if (requestController.signal.aborted) return;
                        const stats = flattenApiV2Stats(
                            response?.data,
                        ) as unknown as NflTeamStats[];
                        homeTeamStats.value =
                            stats.find((row) => row.team_type === 'home') ??
                            null;
                        awayTeamStats.value =
                            stats.find((row) => row.team_type === 'away') ??
                            null;
                    }),
            ];

            const recentQuery = recentGameQuery(currentGame.value);
            const addTeamContext = (
                team: number | string | null | undefined,
                recentGames: typeof homeRecentGames,
                trends: typeof homeTrends,
            ) => {
                if (!team) return;

                supplemental.push(
                    recentQuery
                        ? api.teams
                              .games('cfb', team, {
                                  query: recentQuery,
                                  init: { signal: requestController.signal },
                              })
                              .then((response) => {
                                  if (requestController.signal.aborted) return;
                                  recentGames.value = recentGamesBefore(
                                      (response?.data ??
                                          []) as unknown as RecentGameListItem[],
                                      currentGame.value,
                                      Number(team),
                                  );
                              })
                        : Promise.resolve(),
                    api.teams
                        .trends('cfb', team, {
                            query: trendQuery,
                            init: { signal: requestController.signal },
                        })
                        .then((response) => {
                            if (requestController.signal.aborted) return;
                            trends.value =
                                (response?.data as TeamTrendData | undefined) ??
                                null;
                        }),
                );
            };

            addTeamContext(homeTeam.value?.id, homeRecentGames, homeTrends);
            addTeamContext(awayTeam.value?.id, awayRecentGames, awayTrends);

            await Promise.allSettled(supplemental);
        } catch (e) {
            if (requestController.signal.aborted) return;
            if (e instanceof DOMException && e.name === 'AbortError') return;
            error.value = e instanceof Error ? e.message : 'An error occurred';
        } finally {
            if (!requestController.signal.aborted) {
                loading.value = false;
                trendsLoading.value = false;
            }
        }
    };

    const polling = useAsyncPolling(
        async () => {
            try {
                const response = await api.games.show('cfb', gameId, {
                    init: { signal: requestController.signal },
                });
                if (!requestController.signal.aborted && response?.data) {
                    currentGame.value = response.data as unknown as NflPageGame;
                }
            } catch {
                // Keep the last scoreboard until the next refresh.
            }
        },
        30_000,
        () => !document.hidden && !isFinalGameStatus(currentGame.value.status),
    );

    onMounted(async () => {
        await load();
        polling.start();
    });
    onBeforeUnmount(() => requestController.abort());

    const awayLabel = computed(() => awayTeam.value?.abbreviation || null);
    const homeLabel = computed(() => homeTeam.value?.abbreviation || null);
    const awayRecord = computed(() =>
        currentGame.value.starts_at
            ? getNumericRecord(
                  awayRecentGames.value,
                  currentGame.value.away_team_id,
              )
            : 'Recent record unavailable: kickoff timing unverified',
    );
    const homeRecord = computed(() =>
        currentGame.value.starts_at
            ? getNumericRecord(
                  homeRecentGames.value,
                  currentGame.value.home_team_id,
              )
            : 'Recent record unavailable: kickoff timing unverified',
    );

    const predictionSectionProps = computed(() => ({
        section: 'prediction' as const,
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
                'STATUS_FULL_TIME',
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
                    isFinalGameStatus(currentGame.value.status),
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
        sport: () => 'cfb',
        awayTeam: () => awayTeam.value,
        homeTeam: () => homeTeam.value,
    });

    return {
        pageProps,
        predictionSectionProps,
        analysisSectionProps,
        recentSectionProps,
    };
}
