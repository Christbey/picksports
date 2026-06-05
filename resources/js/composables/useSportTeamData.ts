import { computed, onMounted, ref } from 'vue';
import { fetchJson } from '@/composables/useApiClient';
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

    onMounted(async () => {
        try {
            loading.value = true;
            error.value = null;

            const fetchId = props.teamId;
            if (!fetchId) return;

            const fetches: Promise<unknown>[] = [];
            const fetchKeys: string[] = [];

            fetches.push(
                fetchJson(`${props.config.apiBase}/teams/${fetchId}/metrics`),
            );
            fetchKeys.push('metrics');

            if (props.config.seasonStatTiles) {
                fetches.push(
                    fetchJson(
                        `${props.config.apiBase}/teams/${fetchId}/stats/season-averages`,
                    ),
                );
                fetchKeys.push('seasonStats');
            }

            // Pull enough rows to cover the full team schedule/game log for the season.
            fetches.push(
                fetchJson(
                    `${props.config.apiBase}/teams/${fetchId}/games?per_page=500`,
                ),
            );
            fetchKeys.push('games');

            fetches.push(fetchJson(`${props.config.apiBase}/teams/${fetchId}`));
            fetchKeys.push('team');

            if (
                props.config.showPowerRanking ||
                props.config.metricRankingKeys
            ) {
                fetches.push(fetchJson(`${props.config.apiBase}/team-metrics`));
                fetchKeys.push('allMetrics');
            }

            if (props.config.statRankingKeys) {
                fetches.push(
                    fetchJson(
                        `${props.config.apiBase}/team-stats/season-averages`,
                    ),
                );
                fetchKeys.push('allStats');
            }

            if (props.config.showTrends) {
                const games = props.config.trendsGames ?? 20;
                fetches.push(
                    fetchJson(
                        `${props.config.apiBase}/teams/${fetchId}/trends?games=${games}`,
                    ),
                );
                fetchKeys.push('trends');
            }

            if (props.config.showRoster) {
                rosterLoading.value = true;
                fetches.push(
                    fetchJson(
                        `${props.config.apiBase}/teams/${fetchId}/players`,
                    ),
                );
                fetchKeys.push('roster');
            }

            const responses = await Promise.all(fetches);

            for (let i = 0; i < fetchKeys.length; i++) {
                const key = fetchKeys[i];
                const res = responses[i] as any;

                if (key === 'team' && res) {
                    const data = res;
                    teamData.value = data.data;
                }

                if (key === 'metrics' && res) {
                    const data = res;
                    teamMetrics.value = data.data?.[0] ?? data.data ?? null;
                }

                if (key === 'seasonStats' && res) {
                    const data = res;
                    seasonStats.value = data.data || null;
                }

                if (key === 'games' && res) {
                    const data = res;
                    const games = data.data || [];
                    const metricSeason = toNumber(teamMetrics.value?.season);
                    const seasonScopedGames =
                        metricSeason !== null
                            ? games.filter(
                                  (g: Game) =>
                                      toNumber((g as any).season) ===
                                      metricSeason,
                              )
                            : games;
                    const sourceGames =
                        seasonScopedGames.length > 0
                            ? seasonScopedGames
                            : games;

                    if (props.config.sortRecentByDate) {
                        recentGames.value = sourceGames
                            .filter((g: Game) => g.status === 'STATUS_FINAL')
                            .sort(
                                (a: Game, b: Game) =>
                                    new Date(b.game_date!).getTime() -
                                    new Date(a.game_date!).getTime(),
                            );

                        const now = new Date();
                        upcomingGames.value = sourceGames
                            .filter(
                                (g: Game) =>
                                    g.status !== 'STATUS_FINAL' &&
                                    new Date(g.game_date!) >= now,
                            )
                            .sort(
                                (a: Game, b: Game) =>
                                    new Date(a.game_date!).getTime() -
                                    new Date(b.game_date!).getTime(),
                            );
                    } else {
                        recentGames.value = sourceGames
                            .filter((g: Game) => g.status === 'STATUS_FINAL')
                            .sort(
                                (a: Game, b: Game) =>
                                    new Date(b.game_date!).getTime() -
                                    new Date(a.game_date!).getTime(),
                            );

                        upcomingGames.value = sourceGames
                            .filter(
                                (g: Game) =>
                                    g.status === 'STATUS_SCHEDULED' ||
                                    g.status === 'STATUS_IN_PROGRESS',
                            )
                            .sort(
                                (a: Game, b: Game) =>
                                    new Date(a.game_date!).getTime() -
                                    new Date(b.game_date!).getTime(),
                            );
                    }
                }

                if (key === 'allMetrics' && res) {
                    const data = res;
                    const allMetricsRaw = data.data || [];
                    const season = toNumber(teamMetrics.value?.season);
                    const seasonMetrics =
                        season !== null
                            ? allMetricsRaw.filter(
                                  (m: any) => toNumber(m.season) === season,
                              )
                            : allMetricsRaw;
                    const baseMetrics =
                        seasonMetrics.length > 0
                            ? seasonMetrics
                            : allMetricsRaw;
                    const byTeam = Array.from(
                        new Map(
                            baseMetrics.map((m: any) => [Number(m.team_id), m]),
                        ).values(),
                    );

                    metricRankingTotalTeams.value = byTeam.length;

                    if (props.config.showPowerRanking) {
                        const sortedByNet = [...byTeam].sort(
                            (a: any, b: any) =>
                                (toNumber(b.net_rating) ?? -Infinity) -
                                (toNumber(a.net_rating) ?? -Infinity),
                        );
                        const idx = sortedByNet.findIndex(
                            (m: any) =>
                                Number(m.team_id) === Number(teamId.value),
                        );
                        if (idx !== -1) {
                            powerRanking.value = {
                                rank: idx + 1,
                                total_teams: byTeam.length,
                            };
                        }
                    }

                    if (props.config.metricRankingKeys) {
                        const rankings: Record<string, number> = {};
                        for (const { key: metricKey, descending } of props
                            .config.metricRankingKeys) {
                            const desc = descending ?? true;
                            const sorted = [...byTeam]
                                .filter(
                                    (row: any) =>
                                        toNumber(row[metricKey]) !== null,
                                )
                                .sort((a: any, b: any) => {
                                    const av = toNumber(a[metricKey]) ?? 0;
                                    const bv = toNumber(b[metricKey]) ?? 0;
                                    return desc ? bv - av : av - bv;
                                });
                            const idx = sorted.findIndex(
                                (row: any) =>
                                    Number(row.team_id) ===
                                    Number(teamId.value),
                            );
                            rankings[metricKey] = idx !== -1 ? idx + 1 : 0;
                        }
                        metricRankings.value = rankings;
                    }
                }

                if (key === 'allStats' && res && props.config.statRankingKeys) {
                    const data = res;
                    const allStats = data.data || [];
                    const rankings: Record<string, number> = {};
                    for (const { key: statKey, descending } of props.config
                        .statRankingKeys) {
                        const desc = descending ?? true;
                        const sorted = [...allStats].sort((a: any, b: any) =>
                            desc
                                ? b[statKey] - a[statKey]
                                : a[statKey] - b[statKey],
                        );
                        const idx = sorted.findIndex(
                            (s: any) => s.team_id === teamId.value,
                        );
                        rankings[statKey] = idx !== -1 ? idx + 1 : 0;
                    }
                    statRankings.value = rankings;
                }

                if (key === 'trends' && res) {
                    const data = res;
                    trendsData.value = data.trends || null;
                    lockedTrends.value = data.locked_trends || null;
                }

                if (key === 'roster' && res) {
                    const data = res;
                    rosterPlayers.value = data.data || [];
                    rosterLoading.value = false;
                }
            }

            const sportSupportsDepthCharts = ['nfl', 'nba', 'mlb'].includes(
                props.config.sport,
            );

            if (props.config.showDepthCharts && sportSupportsDepthCharts) {
                const season = toNumber(teamMetrics.value?.season);
                const depthChartResponse = await api.teams.depthCharts(
                    props.config.sport as ApiV2SportSlug,
                    fetchId,
                    {
                        query: season !== null ? { season } : undefined,
                    },
                );

                depthChart.value = depthChartResponse?.data ?? null;
            }
        } catch (e) {
            error.value =
                e instanceof Error
                    ? e.message
                    : 'An error occurred loading team data';
        } finally {
            loading.value = false;
            rosterLoading.value = false;
        }
    });

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
