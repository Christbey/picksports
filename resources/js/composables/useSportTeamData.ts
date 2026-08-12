import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';
import type { BreadcrumbItem, Game } from '@/types';
import type { ApiV2SportSlug, GameDepthChartTeam } from '@/types';
import type { TeamPageConfig } from '@/types/sport-team';

export interface UseSportTeamDataProps {
    config: TeamPageConfig;
    teamId: number;
}

export function useSportTeamData(props: UseSportTeamDataProps) {
    const resolveHref = (href: unknown): string => {
        if (typeof href === 'string') return href;
        if (href && typeof href === 'object' && 'url' in href) {
            return String((href as { url: string }).url);
        }
        return '#';
    };

    const teamData = ref<any>(null);
    const teamMetrics = ref<any>(null);
    const seasonStats = ref<any>(null);
    const recentGames = ref<Game[]>([]);
    const upcomingGames = ref<Game[]>([]);
    const powerRanking = ref<{ rank: number; total_teams: number } | null>(
        null,
    );
    const statRankings = ref<Record<string, number>>({});
    const metricRankings = ref<Record<string, number>>({});
    const metricRankingTotalTeams = ref(0);
    const rosterPlayers = ref<any[]>([]);
    const depthChart = ref<GameDepthChartTeam | null>(null);
    const rosterLoading = ref(false);
    const trendsData = ref<Record<string, string[]> | null>(null);
    const lockedTrends = ref<Record<string, string> | null>(null);
    const loading = ref(true);
    const error = ref<string | null>(null);
    const api = useApiV2Client();
    const requestController = new AbortController();

    const teamId = computed(() => teamData.value?.id || props.teamId);

    const breadcrumbs = computed<BreadcrumbItem[]>(() => {
        const items: BreadcrumbItem[] = [
            {
                title: props.config.sportLabel,
                href: props.config.predictionsHref,
            },
        ];
        if (props.config.metricsHref) {
            items.push({
                title: 'Team Metrics',
                href: props.config.metricsHref,
            });
        }
        items.push({
            title: teamData.value
                ? props.config.headTitle(teamData.value)
                : 'Team',
            href: teamId.value
                ? resolveHref(props.config.teamHref(teamId.value))
                : '#',
        });
        return items;
    });

    const formatDate = (dateString: string | null): string => {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
        });
    };

    const getOpponent = (game: Game, isHome: boolean) => {
        return isHome ? game.away_team : game.home_team;
    };

    const getGameResult = (game: Game): string | null => {
        if (
            game.status !== 'STATUS_FINAL' ||
            game.home_score == null ||
            game.away_score == null
        )
            return null;
        const tid = teamId.value;
        const isHome = game.home_team_id === tid;
        const teamScore = isHome ? game.home_score : game.away_score;
        const oppScore = isHome ? game.away_score : game.home_score;
        return teamScore > oppScore ? 'W' : 'L';
    };

    const record = computed(() => {
        const wins = recentGames.value.filter(
            (g) => getGameResult(g) === 'W',
        ).length;
        const losses = recentGames.value.filter(
            (g) => getGameResult(g) === 'L',
        ).length;
        return { wins, losses };
    });

    const recentForm = computed(() => {
        return recentGames.value
            .slice(0, 5)
            .map((g) => getGameResult(g))
            .filter(Boolean);
    });

    const recentRecord = computed(() => {
        const last5 = recentGames.value.slice(0, 5);
        const wins = last5.filter((g) => getGameResult(g) === 'W').length;
        const losses = last5.length - wins;
        return { wins, losses, games: last5.length };
    });

    const headerInfoItems = computed(() => {
        if (!props.config.headerInfo || !teamData.value) return [];
        return props.config.headerInfo(teamData.value, {
            record: record.value,
        });
    });

    const trendLabel = (key: string): string => {
        const labels: Record<string, string> = {
            scoring: 'Scoring',
            margins: 'Margins',
            streaks: 'Streaks',
            quarters: 'Quarters',
            halves: 'Halves',
            totals: 'Totals',
            first_score: 'First Score',
            situational: 'Situational',
            advanced: 'Advanced',
            time_based: 'Time Based',
            rest_schedule: 'Rest & Schedule',
            opponent_strength: 'Opponent Strength',
            conference: 'Conference',
            scoring_patterns: 'Scoring Patterns',
            offensive_efficiency: 'Offensive Efficiency',
            defensive_performance: 'Defensive Performance',
            momentum: 'Momentum',
            clutch_performance: 'Clutch Performance',
        };
        return (
            labels[key] ||
            key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
        );
    };

    const allTrendCategories = computed(() => {
        const categories = new Set<string>();
        if (trendsData.value) {
            Object.keys(trendsData.value).forEach((key) => categories.add(key));
        }
        if (lockedTrends.value) {
            Object.keys(lockedTrends.value).forEach((key) =>
                categories.add(key),
            );
        }
        return Array.from(categories).sort();
    });

    const displayRecentGames = computed(() => {
        const limit = props.config.recentGamesLimit ?? 10;
        return recentGames.value.slice(0, limit);
    });

    const displayUpcomingGames = computed(() => {
        const limit = props.config.upcomingGamesLimit ?? 5;
        return upcomingGames.value.slice(0, limit);
    });

    const overviewSeasonStatTiles = computed(() => {
        if (!props.config.seasonStatTiles) return [];
        if (props.config.overviewStatCount) {
            return props.config.seasonStatTiles.slice(
                0,
                props.config.overviewStatCount,
            );
        }
        return props.config.seasonStatTiles;
    });

    const toNumber = (value: unknown): number | null => {
        if (typeof value === 'number')
            return Number.isFinite(value) ? value : null;
        if (typeof value === 'string' && value.trim() !== '') {
            const parsed = Number(value);
            return Number.isFinite(parsed) ? parsed : null;
        }
        return null;
    };

    const applyMetricRankings = (allMetricsRaw: any[]) => {
        const season = toNumber(teamMetrics.value?.season);
        const seasonMetrics =
            season !== null
                ? allMetricsRaw.filter(
                      (metric: any) => toNumber(metric.season) === season,
                  )
                : allMetricsRaw;
        const baseMetrics =
            seasonMetrics.length > 0 ? seasonMetrics : allMetricsRaw;
        const byTeam = Array.from(
            new Map(
                baseMetrics.map((metric: any) => [
                    Number(metric.team_id),
                    metric,
                ]),
            ).values(),
        ) as any[];

        metricRankingTotalTeams.value = byTeam.length;

        if (props.config.showPowerRanking) {
            const sortedByNet = [...byTeam].sort(
                (a, b) =>
                    (toNumber(b.net_rating) ?? -Infinity) -
                    (toNumber(a.net_rating) ?? -Infinity),
            );
            const index = sortedByNet.findIndex(
                (metric) => Number(metric.team_id) === Number(teamId.value),
            );
            powerRanking.value =
                index === -1
                    ? null
                    : { rank: index + 1, total_teams: byTeam.length };
        }

        if (props.config.metricRankingKeys) {
            const rankings: Record<string, number> = {};
            for (const { key, descending = true } of props.config
                .metricRankingKeys) {
                const sorted = [...byTeam]
                    .filter((row) => toNumber(row[key]) !== null)
                    .sort((a, b) => {
                        const first = toNumber(a[key]) ?? 0;
                        const second = toNumber(b[key]) ?? 0;
                        return descending ? second - first : first - second;
                    });
                const index = sorted.findIndex(
                    (row) => Number(row.team_id) === Number(teamId.value),
                );
                rankings[key] = index === -1 ? 0 : index + 1;
            }
            metricRankings.value = rankings;
        }
    };

    const applyStatRankings = (allStats: any[]) => {
        if (!props.config.statRankingKeys) return;

        const rankings: Record<string, number> = {};
        for (const { key, descending = true } of props.config.statRankingKeys) {
            const sorted = [...allStats].sort((a, b) =>
                descending ? b[key] - a[key] : a[key] - b[key],
            );
            const index = sorted.findIndex(
                (stats) => Number(stats.team_id) === Number(teamId.value),
            );
            rankings[key] = index === -1 ? 0 : index + 1;
        }
        statRankings.value = rankings;
    };

    const applyGames = (games: Game[]) => {
        const metricSeason = toNumber(teamMetrics.value?.season);
        const seasonScopedGames =
            metricSeason !== null
                ? games.filter(
                      (game) => toNumber((game as any).season) === metricSeason,
                  )
                : games;
        const sourceGames =
            seasonScopedGames.length > 0 ? seasonScopedGames : games;

        recentGames.value = sourceGames
            .filter((game) => game.status === 'STATUS_FINAL')
            .sort(
                (a, b) =>
                    new Date(b.game_date!).getTime() -
                    new Date(a.game_date!).getTime(),
            );

        const now = new Date();
        upcomingGames.value = sourceGames
            .filter((game) => {
                if (props.config.sortRecentByDate) {
                    return (
                        game.status !== 'STATUS_FINAL' &&
                        new Date(game.game_date!) >= now
                    );
                }

                return (
                    game.status === 'STATUS_SCHEDULED' ||
                    game.status === 'STATUS_IN_PROGRESS'
                );
            })
            .sort(
                (a, b) =>
                    new Date(a.game_date!).getTime() -
                    new Date(b.game_date!).getTime(),
            );
    };

    onMounted(async () => {
        try {
            loading.value = true;
            error.value = null;

            const fetchId = props.teamId;
            if (!fetchId) return;

            const sport = props.config.sport as ApiV2SportSlug;
            const requestOptions = {
                init: { signal: requestController.signal },
            };
            const [teamResponse, metricResponse] = await Promise.all([
                api.teams.show(sport, fetchId, requestOptions),
                api.teams.metrics(sport, fetchId, requestOptions),
            ]);

            teamData.value = teamResponse?.data ?? null;
            teamMetrics.value = metricResponse?.data ?? null;
            loading.value = false;

            const season = toNumber(teamMetrics.value?.season);
            const supplemental: Promise<unknown>[] = [
                api.teams
                    .games(sport, fetchId, {
                        query: { per_page: 200 },
                        init: { signal: requestController.signal },
                    })
                    .then((response) =>
                        applyGames((response?.data ?? []) as unknown as Game[]),
                    ),
            ];

            if (props.config.seasonStatTiles) {
                supplemental.push(
                    api.teams
                        .statSeasonAverages(sport, fetchId, requestOptions)
                        .then((response) => {
                            seasonStats.value = response?.data ?? null;
                        }),
                );
            }

            if (
                props.config.showPowerRanking ||
                props.config.metricRankingKeys
            ) {
                supplemental.push(
                    api.metrics
                        .teams(sport, {
                            query: {
                                ...(season !== null ? { season } : {}),
                                per_page: 500,
                            },
                            init: { signal: requestController.signal },
                        })
                        .then((response) =>
                            applyMetricRankings(response?.data ?? []),
                        ),
                );
            }

            if (props.config.statRankingKeys) {
                supplemental.push(
                    api.stats
                        .teamSeasonAverages(sport, requestOptions)
                        .then((response) =>
                            applyStatRankings(response?.data ?? []),
                        ),
                );
            }

            if (props.config.showTrends) {
                supplemental.push(
                    api.teams
                        .trends(sport, fetchId, {
                            query: { games: props.config.trendsGames ?? 20 },
                            init: { signal: requestController.signal },
                        })
                        .then((response) => {
                            const payload = response?.data;
                            trendsData.value =
                                (payload?.trends as Record<string, string[]>) ??
                                null;
                            lockedTrends.value =
                                (payload?.locked_trends as Record<
                                    string,
                                    string
                                >) ?? null;
                        }),
                );
            }

            if (props.config.showRoster) {
                rosterLoading.value = true;
                supplemental.push(
                    api.teams
                        .players(sport, fetchId, {
                            query: { per_page: 100 },
                            init: { signal: requestController.signal },
                        })
                        .then((response) => {
                            rosterPlayers.value = response?.data ?? [];
                        })
                        .finally(() => {
                            rosterLoading.value = false;
                        }),
                );
            }

            if (
                props.config.showDepthCharts &&
                ['nfl', 'nba', 'mlb'].includes(props.config.sport)
            ) {
                supplemental.push(
                    api.teams
                        .depthCharts(sport, fetchId, {
                            query: season !== null ? { season } : undefined,
                            init: { signal: requestController.signal },
                        })
                        .then((response) => {
                            depthChart.value = response?.data ?? null;
                        }),
                );
            }

            await Promise.allSettled(supplemental);
        } catch (e) {
            if (e instanceof DOMException && e.name === 'AbortError') return;
            error.value =
                e instanceof Error
                    ? e.message
                    : 'An error occurred loading team data';
        } finally {
            loading.value = false;
            rosterLoading.value = false;
        }
    });

    onBeforeUnmount(() => requestController.abort());

    return {
        teamData,
        teamMetrics,
        seasonStats,
        recentGames,
        upcomingGames,
        powerRanking,
        statRankings,
        metricRankings,
        metricRankingTotalTeams,
        rosterPlayers,
        depthChart,
        rosterLoading,
        trendsData,
        lockedTrends,
        loading,
        error,
        teamId,
        breadcrumbs,
        formatDate,
        getOpponent,
        getGameResult,
        record,
        recentForm,
        recentRecord,
        headerInfoItems,
        trendLabel,
        allTrendCategories,
        displayRecentGames,
        displayUpcomingGames,
        overviewSeasonStatTiles,
    };
}
