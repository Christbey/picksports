import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { flattenApiV2Stats } from '@/composables/useApiV2StatsAdapter';
import { formatDateLong, useGameStatus } from '@/composables/useFormatters';
import {
    calculatePercentage,
    parseBroadcastNetworks,
    parseLinescores,
} from '@/composables/useGameDataUtils';
import { useTeamTrends } from '@/composables/useTeamTrends';
import {
    NFL_LIVE_STATUSES,
    liveSnapshotWarning,
    type NflLiveSnapshot,
} from '@/lib/nflLiveSnapshot';
import type {
    LivePredictionData,
    NflPageGame,
    NflPagePrediction,
    NflPageTeam,
    NflTeamStats,
    PredictionAnalysisSummary,
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

const toNumber = (value: unknown): number => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
};

const toOptionalNumber = (value: unknown): number | null => {
    if (value === null || value === undefined || value === '') return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
};

const normalizeDepthChartContext = (
    rawContext: unknown,
): NflPagePrediction['depth_chart_context'] => {
    if (!rawContext || typeof rawContext !== 'object') return null;

    const source = rawContext as Record<string, unknown>;
    const type =
        source.type === 'injury_weighting' || source.type === 'starter_fallback'
            ? source.type
            : null;

    if (!type) return null;

    return {
        type,
        applied:
            typeof source.applied === 'boolean' ? source.applied : undefined,
        home_out_weighted: toOptionalNumber(source.home_out_weighted),
        away_out_weighted: toOptionalNumber(source.away_out_weighted),
        home_questionable_weighted: toOptionalNumber(
            source.home_questionable_weighted,
        ),
        away_questionable_weighted: toOptionalNumber(
            source.away_questionable_weighted,
        ),
        spread_adjustment: toOptionalNumber(source.spread_adjustment),
        total_adjustment: toOptionalNumber(source.total_adjustment),
        win_probability_adjustment: toOptionalNumber(
            source.win_probability_adjustment,
        ),
        injury_model_source:
            typeof source.injury_model_source === 'string'
                ? source.injury_model_source
                : null,
        injury_spread_model_source:
            typeof source.injury_spread_model_source === 'string'
                ? source.injury_spread_model_source
                : null,
        injury_total_model_source:
            typeof source.injury_total_model_source === 'string'
                ? source.injury_total_model_source
                : null,
        home_pitcher_source:
            typeof source.home_pitcher_source === 'string'
                ? source.home_pitcher_source
                : null,
        away_pitcher_source:
            typeof source.away_pitcher_source === 'string'
                ? source.away_pitcher_source
                : null,
        home_depth_chart_fallback_used:
            typeof source.home_depth_chart_fallback_used === 'boolean'
                ? source.home_depth_chart_fallback_used
                : undefined,
        away_depth_chart_fallback_used:
            typeof source.away_depth_chart_fallback_used === 'boolean'
                ? source.away_depth_chart_fallback_used
                : undefined,
        probable_pitcher_injury_applied:
            typeof source.probable_pitcher_injury_applied === 'boolean'
                ? source.probable_pitcher_injury_applied
                : undefined,
    };
};

const normalizeNarrative = (
    rawNarrative: unknown,
): NflPagePrediction['narrative'] => {
    if (!rawNarrative || typeof rawNarrative !== 'object') return null;

    const source = rawNarrative as Record<string, unknown>;
    const plan = source.betting_plan;

    return {
        summary: typeof source.summary === 'string' ? source.summary : '',
        key_points: Array.isArray(source.key_points)
            ? source.key_points
                  .map((point) => String(point))
                  .filter((point) => point.length > 0)
            : [],
        risk_note: typeof source.risk_note === 'string' ? source.risk_note : '',
        generated_by:
            typeof source.generated_by === 'string' ? source.generated_by : '',
        social_caption:
            typeof source.social_caption === 'string'
                ? source.social_caption
                : null,
        betting_plan:
            plan && typeof plan === 'object'
                ? {
                      bet_pick:
                          typeof (plan as Record<string, unknown>).bet_pick ===
                          'string'
                              ? ((plan as Record<string, unknown>)
                                    .bet_pick as string)
                              : '',
                      reasoning:
                          typeof (plan as Record<string, unknown>).reasoning ===
                          'string'
                              ? ((plan as Record<string, unknown>)
                                    .reasoning as string)
                              : '',
                  }
                : null,
    };
};

const normalizePrediction = (
    rawPrediction: unknown,
): NflPagePrediction | null => {
    if (!rawPrediction || typeof rawPrediction !== 'object') return null;

    const source = rawPrediction as Record<string, unknown>;

    return {
        id: toNumber(source.id),
        game_id: toNumber(source.game_id),
        home_elo: toNumber(source.home_elo),
        away_elo: toNumber(source.away_elo),
        predicted_spread: toNumber(source.predicted_spread),
        predicted_total: toOptionalNumber(source.predicted_total) ?? 0,
        win_probability: toNumber(source.win_probability),
        confidence_score: toNumber(source.confidence_score),
        betting_value: Array.isArray(source.betting_value)
            ? (source.betting_value as NflPagePrediction['betting_value'])
            : undefined,
        prediction_analysis:
            source.prediction_analysis &&
            typeof source.prediction_analysis === 'object'
                ? (source.prediction_analysis as PredictionAnalysisSummary)
                : null,
        winner_correct:
            typeof source.winner_correct === 'boolean'
                ? source.winner_correct
                : null,
        actual_total: toOptionalNumber(source.actual_total),
        live_predicted_spread: toOptionalNumber(source.live_predicted_spread),
        live_win_probability: toOptionalNumber(source.live_win_probability),
        live_predicted_total: toOptionalNumber(source.live_predicted_total),
        live_seconds_remaining: toOptionalNumber(source.live_seconds_remaining),
        live_updated_at:
            typeof source.live_updated_at === 'string'
                ? source.live_updated_at
                : null,
        narrative: normalizeNarrative(source.narrative),
        depth_chart_context: normalizeDepthChartContext(
            source.depth_chart_context,
        ),
    };
};

export function useNflGamePage(gameId: number) {
    const api = useApiV2Client();
    const currentGame = ref<NflPageGame>(fallbackGame(gameId));
    const homeTeam = ref<NflPageTeam | null>(null);
    const awayTeam = ref<NflPageTeam | null>(null);
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
    const liveSnapshot = ref<NflLiveSnapshot | null>(null);
    const liveRefreshFailed = ref(false);
    const currentTime = ref(Date.now());
    let refreshTimer: ReturnType<typeof setTimeout> | undefined;
    let clockTimer: ReturnType<typeof setInterval> | undefined;
    let refreshing = false;
    let lastStatsRefresh = 0;

    const gameStatus = useGameStatus(() => currentGame.value.status);
    const formatDate = (dateString: string, timeString?: string): string => {
        void timeString;
        return formatDateLong(dateString);
    };
    const homeLinescores = computed(() =>
        parseLinescores(currentGame.value.home_linescores),
    );
    const awayLinescores = computed(() =>
        parseLinescores(currentGame.value.away_linescores),
    );
    const broadcastNetworks = computed(() =>
        parseBroadcastNetworks(currentGame.value.broadcast_networks),
    );
    const weekLabel = computed(() => {
        if (!currentGame.value.week || !currentGame.value.season_type)
            return '';

        const seasonType = String(currentGame.value.season_type);

        if (seasonType === 'Regular Season' || seasonType === '2') {
            return `Week ${currentGame.value.week}`;
        }

        if (seasonType === 'Preseason' || seasonType === '1') {
            return `Preseason Week ${currentGame.value.week}`;
        }

        const playoffRounds: Record<number, string> = {
            1: 'Wild Card',
            2: 'Divisional',
            3: 'Conference Championship',
            5: 'Super Bowl',
        };

        return (
            playoffRounds[currentGame.value.week] ||
            `Playoff Week ${currentGame.value.week}`
        );
    });

    const hasLivePrediction = computed(() =>
        NFL_LIVE_STATUSES.has(currentGame.value.status),
    );
    const livePredictionData = computed((): LivePredictionData | undefined => {
        if (!hasLivePrediction.value) return undefined;
        const snapshot = liveSnapshot.value;
        return {
            isLive: true,
            provisional: true,
            homeLabel: homeTeam.value?.abbreviation,
            awayLabel: awayTeam.value?.abbreviation,
            homeScore: snapshot?.game.home_score ?? null,
            awayScore: snapshot?.game.away_score ?? null,
            status: snapshot?.game.status ?? currentGame.value.status,
            period: snapshot?.game.period,
            gameClock: snapshot?.game.game_clock,
            liveWinProbability:
                snapshot?.projection?.live_win_probability ?? null,
            livePredictedSpread:
                snapshot?.projection?.live_predicted_spread ?? null,
            livePredictedTotal:
                snapshot?.projection?.live_predicted_total ?? null,
            liveSecondsRemaining: snapshot?.projection?.live_seconds_remaining,
            sourceUpdatedAt: snapshot?.source_updated_at,
            freshnessWarning: liveSnapshotWarning(
                snapshot,
                currentTime.value,
                liveRefreshFailed.value,
            ),
            modelWarning:
                snapshot?.warning ??
                'Experimental score-and-clock estimate; not a live sportsbook edge.',
            preGameWinProbability: Number(
                prediction.value?.win_probability ?? 0,
            ),
            preGamePredictedSpread: Number(
                prediction.value?.predicted_spread ?? 0,
            ),
            preGamePredictedTotal: Number(
                prediction.value?.predicted_total ?? 0,
            ),
        };
    });

    const trendsSubtitle = computed(
        () =>
            `${currentGame.value.season} ${weekLabel.value.startsWith('Preseason') ? 'Preseason' : currentGame.value.season_type === '3' ? 'Postseason' : 'Regular Season'} · ${awayTeam.value?.abbreviation ?? 'Away'}: ${awayTrends.value?.sample_size ?? 0} games · ${homeTeam.value?.abbreviation ?? 'Home'}: ${homeTrends.value?.sample_size ?? 0} games · before kickoff`,
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

            const requestOptions = {
                init: { signal: requestController.signal },
            };
            const [gameData, predictionData] = await Promise.all([
                api.games.show('nfl', gameId, requestOptions),
                api.predictions.forGame('nfl', gameId, requestOptions),
            ]);

            if (gameData?.data) {
                const fullGame = gameData.data as unknown as NflPageGame;
                currentGame.value = fullGame;
                const fallbackGame = fullGame as NflPageGame & {
                    homeTeam?: NflPageGame['home_team'];
                    awayTeam?: NflPageGame['away_team'];
                };
                homeTeam.value =
                    fullGame.home_team || fallbackGame.homeTeam || null;
                awayTeam.value =
                    fullGame.away_team || fallbackGame.awayTeam || null;
            }

            if (predictionData?.data) {
                const raw = Array.isArray(predictionData.data)
                    ? (predictionData.data[0] ?? null)
                    : predictionData.data;
                prediction.value = normalizePrediction(raw);
            }

            loading.value = false;
            trendsLoading.value = true;

            const homeTeamId = homeTeam.value?.id;
            const awayTeamId = awayTeam.value?.id;
            const trendQuery = {
                games: 'season',
                season: currentGame.value.season,
                season_type: currentGame.value.season_type,
                before_date:
                    currentGame.value.starts_at ?? currentGame.value.game_date,
            };
            const supplemental: Promise<unknown>[] = [
                api.stats
                    .teams('nfl', {
                        query: { game_id: gameId, per_page: 100 },
                        init: { signal: requestController.signal },
                    })
                    .then((response) => {
                        const stats = flattenApiV2Stats(
                            response?.data,
                        ) as unknown as NflTeamStats[];
                        homeTeamStats.value =
                            stats.find(
                                (statsRow) => statsRow.team_type === 'home',
                            ) ?? null;
                        awayTeamStats.value =
                            stats.find(
                                (statsRow) => statsRow.team_type === 'away',
                            ) ?? null;
                    }),
            ];

            const addTeamContext = (
                team: number | string | null | undefined,
                recentGames: typeof homeRecentGames,
                trends: typeof homeTrends,
            ) => {
                if (!team) return;

                supplemental.push(
                    api.teams
                        .games('nfl', team, {
                            query: { per_page: 25 },
                            init: { signal: requestController.signal },
                        })
                        .then((response) => {
                            recentGames.value = (response?.data ?? [])
                                .filter(
                                    (game) =>
                                        game.status === 'STATUS_FINAL' &&
                                        game.id !== currentGame.value.id,
                                )
                                .slice(0, 5) as RecentGameListItem[];
                        }),
                    api.teams
                        .trends('nfl', team, {
                            query: trendQuery,
                            init: { signal: requestController.signal },
                        })
                        .then((response) => {
                            trends.value =
                                (response?.data as TeamTrendData | undefined) ??
                                null;
                        }),
                );
            };

            addTeamContext(homeTeamId, homeRecentGames, homeTrends);
            addTeamContext(awayTeamId, awayRecentGames, awayTrends);

            await Promise.allSettled(supplemental);
        } catch (e) {
            if (e instanceof DOMException && e.name === 'AbortError') return;
            error.value = e instanceof Error ? e.message : 'An error occurred';
        } finally {
            loading.value = false;
            trendsLoading.value = false;
        }
    };

    const refreshLive = async () => {
        if (refreshing || requestController.signal.aborted || document.hidden)
            return;
        refreshing = true;
        try {
            const response = await api.games.liveSnapshot<NflLiveSnapshot>(
                'nfl',
                gameId,
                {
                    init: {
                        signal: requestController.signal,
                        cache: 'no-store',
                    },
                },
            );
            if (requestController.signal.aborted) return;
            if (!response?.data) throw new Error('Live snapshot unavailable');
            liveSnapshot.value = response.data;
            currentGame.value = {
                ...currentGame.value,
                ...response.data.game,
            } as NflPageGame;
            liveRefreshFailed.value = false;
            currentTime.value = Date.now();
            if (
                Date.now() - lastStatsRefresh >= 60000 ||
                currentGame.value.status === 'STATUS_FINAL'
            ) {
                const statsResponse = await api.stats
                    .teams('nfl', {
                        query: { game_id: gameId, per_page: 2 },
                        init: { signal: requestController.signal },
                    })
                    .catch(() => null);
                if (requestController.signal.aborted) return;
                const stats = flattenApiV2Stats(
                    statsResponse?.data,
                ) as unknown as NflTeamStats[];
                homeTeamStats.value =
                    stats.find((row) => row.team_type === 'home') ??
                    homeTeamStats.value;
                awayTeamStats.value =
                    stats.find((row) => row.team_type === 'away') ??
                    awayTeamStats.value;
                lastStatsRefresh = Date.now();
            }
        } catch {
            if (!requestController.signal.aborted)
                liveRefreshFailed.value = true;
        } finally {
            refreshing = false;
        }
    };
    const scheduleRefresh = () => {
        clearTimeout(refreshTimer);
        if (
            requestController.signal.aborted ||
            currentGame.value.status === 'STATUS_FINAL'
        )
            return;
        refreshTimer = setTimeout(
            async () => {
                await refreshLive();
                scheduleRefresh();
            },
            NFL_LIVE_STATUSES.has(currentGame.value.status) ? 15000 : 60000,
        );
    };
    const onVisibilityChange = () => {
        if (!document.hidden && currentGame.value.status !== 'STATUS_FINAL') {
            void refreshLive().then(scheduleRefresh);
        }
    };
    onMounted(async () => {
        await load();
        if (requestController.signal.aborted) return;
        await refreshLive();
        if (requestController.signal.aborted) return;
        scheduleRefresh();
        clockTimer = setInterval(() => {
            currentTime.value = Date.now();
        }, 5000);
        document.addEventListener('visibilitychange', onVisibilityChange);
    });
    onBeforeUnmount(() => {
        requestController.abort();
        clearTimeout(refreshTimer);
        clearInterval(clockTimer);
        document.removeEventListener('visibilitychange', onVisibilityChange);
    });

    return {
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
    };
}
