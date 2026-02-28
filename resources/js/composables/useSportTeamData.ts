import { computed, onMounted, ref } from 'vue';
import type { BreadcrumbItem, Game } from '@/types';
import type { TeamPageConfig } from '@/types/sport-team';

export interface UseSportTeamDataProps {
    config: TeamPageConfig;
    team?: any;
    teamId?: number;
    preloadedMetrics?: any;
    preloadedSeasonStats?: any;
    preloadedRecentGames?: any[];
    preloadedUpcomingGames?: any[];
}

export function useSportTeamData(props: UseSportTeamDataProps) {
    const resolveHref = (href: unknown): string => {
        if (typeof href === 'string') return href;
        if (href && typeof href === 'object' && 'url' in href) {
            return String((href as { url: string }).url);
        }
        return '#';
    };

    const teamData = ref<any>(props.team || null);
    const teamMetrics = ref<any>(props.preloadedMetrics || null);
    const seasonStats = ref<any>(props.preloadedSeasonStats || null);
    const recentGames = ref<Game[]>((props.preloadedRecentGames as Game[]) || []);
    const upcomingGames = ref<Game[]>((props.preloadedUpcomingGames as Game[]) || []);
    const powerRanking = ref<{ rank: number; total_teams: number } | null>(null);
    const statRankings = ref<Record<string, number>>({});
    const rosterPlayers = ref<any[]>([]);
    const rosterLoading = ref(false);
    const trendsData = ref<Record<string, string[]> | null>(null);
    const lockedTrends = ref<Record<string, string> | null>(null);
    const loading = ref(!hasPreloadedData());
    const error = ref<string | null>(null);

    function hasPreloadedData(): boolean {
        return !!(props.preloadedMetrics !== undefined && props.preloadedRecentGames !== undefined);
    }

    const teamId = computed(() => teamData.value?.id || props.teamId);

    const breadcrumbs = computed<BreadcrumbItem[]>(() => {
        const items: BreadcrumbItem[] = [
            { title: props.config.sportLabel, href: props.config.predictionsHref },
        ];
        if (props.config.metricsHref) {
            items.push({ title: 'Team Metrics', href: props.config.metricsHref });
        }
        items.push({
            title: teamData.value ? props.config.headTitle(teamData.value) : 'Team',
            href: teamId.value ? resolveHref(props.config.teamHref(teamId.value)) : '#',
        });
        return items;
    });

    const formatDate = (dateString: string | null): string => {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    };

    const getOpponent = (game: Game, isHome: boolean) => {
        return isHome ? game.away_team : game.home_team;
    };

    const getGameResult = (game: Game): string | null => {
        if (game.status !== 'STATUS_FINAL' || !game.home_score || !game.away_score) return null;
        const tid = teamId.value;
        const isHome = game.home_team_id === tid;
        const teamScore = isHome ? game.home_score : game.away_score;
        const oppScore = isHome ? game.away_score : game.home_score;
        return teamScore > oppScore ? 'W' : 'L';
    };

    const record = computed(() => {
        const wins = recentGames.value.filter((g) => getGameResult(g) === 'W').length;
        const losses = recentGames.value.filter((g) => getGameResult(g) === 'L').length;
        return { wins, losses };
    });

    const recentForm = computed(() => {
        return recentGames.value.slice(0, 5).map((g) => getGameResult(g)).filter(Boolean);
    });

    const recentRecord = computed(() => {
        const last5 = recentGames.value.slice(0, 5);
        const wins = last5.filter((g) => getGameResult(g) === 'W').length;
        const losses = last5.length - wins;
        return { wins, losses, games: last5.length };
    });

    const headerInfoItems = computed(() => {
        if (!props.config.headerInfo || !teamData.value) return [];
        return props.config.headerInfo(teamData.value, { record: record.value });
    });

    const trendLabel = (key: string): string => {
        const labels: Record<string, string> = {
            scoring: 'Scoring', margins: 'Margins', streaks: 'Streaks',
            quarters: 'Quarters', halves: 'Halves', totals: 'Totals',
            first_score: 'First Score', situational: 'Situational',
            advanced: 'Advanced', time_based: 'Time Based',
            rest_schedule: 'Rest & Schedule', opponent_strength: 'Opponent Strength',
            conference: 'Conference', scoring_patterns: 'Scoring Patterns',
            offensive_efficiency: 'Offensive Efficiency',
            defensive_performance: 'Defensive Performance',
            momentum: 'Momentum', clutch_performance: 'Clutch Performance',
        };
        return labels[key] || key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
    };

    const allTrendCategories = computed(() => {
        const categories = new Set<string>();
        if (trendsData.value) {
            Object.keys(trendsData.value).forEach((key) => categories.add(key));
        }
        if (lockedTrends.value) {
            Object.keys(lockedTrends.value).forEach((key) => categories.add(key));
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
            return props.config.seasonStatTiles.slice(0, props.config.overviewStatCount);
        }
        return props.config.seasonStatTiles;
    });

    onMounted(async () => {
        if (hasPreloadedData() && !props.config.showTrends && !props.config.showPowerRanking) return;

        try {
            loading.value = !hasPreloadedData();
            error.value = null;

            const fetchId = props.teamId || props.team?.id;
            if (!fetchId) return;

            const fetches: Promise<Response>[] = [];
            const fetchKeys: string[] = [];

            if (!props.preloadedMetrics) {
                fetches.push(fetch(`${props.config.apiBase}/teams/${fetchId}/metrics`));
                fetchKeys.push('metrics');
            }

            if (props.config.seasonStatTiles && props.preloadedSeasonStats === undefined) {
                fetches.push(fetch(`${props.config.apiBase}/teams/${fetchId}/stats/season-averages`));
                fetchKeys.push('seasonStats');
            }

            if (props.preloadedRecentGames === undefined) {
                fetches.push(fetch(`${props.config.apiBase}/teams/${fetchId}/games`));
                fetchKeys.push('games');
            }

            if (!props.team && props.teamId) {
                fetches.push(fetch(`${props.config.apiBase}/teams/${fetchId}`));
                fetchKeys.push('team');
            }

            if (props.config.showPowerRanking) {
                fetches.push(fetch(`${props.config.apiBase}/team-metrics`));
                fetchKeys.push('allMetrics');
            }

            if (props.config.statRankingKeys) {
                fetches.push(fetch(`${props.config.apiBase}/team-stats/season-averages`));
                fetchKeys.push('allStats');
            }

            if (props.config.showTrends) {
                const games = props.config.trendsGames ?? 20;
                fetches.push(fetch(`${props.config.apiBase}/teams/${fetchId}/trends?games=${games}`));
                fetchKeys.push('trends');
            }

            if (props.config.showRoster) {
                rosterLoading.value = true;
                fetches.push(fetch(`${props.config.apiBase}/teams/${fetchId}/players`));
                fetchKeys.push('roster');
            }

            const responses = await Promise.all(fetches);

            for (let i = 0; i < fetchKeys.length; i++) {
                const key = fetchKeys[i];
                const res = responses[i];

                if (key === 'team' && res.ok) {
                    const data = await res.json();
                    teamData.value = data.data;
                }

                if (key === 'metrics' && res.ok) {
                    const data = await res.json();
                    teamMetrics.value = data.data?.[0] ?? data.data ?? null;
                }

                if (key === 'seasonStats' && res.ok) {
                    const data = await res.json();
                    seasonStats.value = data.data || null;
                }

                if (key === 'games' && res.ok) {
                    const data = await res.json();
                    const games = data.data || [];

                    if (props.config.sortRecentByDate) {
                        recentGames.value = games
                            .filter((g: Game) => g.status === 'STATUS_FINAL')
                            .sort((a: Game, b: Game) => new Date(b.game_date!).getTime() - new Date(a.game_date!).getTime())
                            .slice(0, props.config.recentGamesLimit ?? 10);

                        const now = new Date();
                        upcomingGames.value = games
                            .filter((g: Game) => g.status !== 'STATUS_FINAL' && new Date(g.game_date!) >= now)
                            .sort((a: Game, b: Game) => new Date(a.game_date!).getTime() - new Date(b.game_date!).getTime())
                            .slice(0, props.config.upcomingGamesLimit ?? 10);
                    } else {
                        recentGames.value = games
                            .filter((g: Game) => g.status === 'STATUS_FINAL')
                            .slice(0, props.config.recentGamesLimit ?? 10);

                        upcomingGames.value = games
                            .filter((g: Game) => g.status === 'STATUS_SCHEDULED' || g.status === 'STATUS_IN_PROGRESS')
                            .slice(0, props.config.upcomingGamesLimit ?? 5);
                    }
                }

                if (key === 'allMetrics' && res.ok) {
                    const data = await res.json();
                    const allMetrics = data.data || [];
                    const idx = allMetrics.findIndex((m: any) => m.team_id === teamId.value);
                    if (idx !== -1) {
                        powerRanking.value = { rank: idx + 1, total_teams: allMetrics.length };
                    }
                }

                if (key === 'allStats' && res.ok && props.config.statRankingKeys) {
                    const data = await res.json();
                    const allStats = data.data || [];
                    const rankings: Record<string, number> = {};
                    for (const { key: statKey, descending } of props.config.statRankingKeys) {
                        const desc = descending ?? true;
                        const sorted = [...allStats].sort((a: any, b: any) =>
                            desc ? b[statKey] - a[statKey] : a[statKey] - b[statKey],
                        );
                        const idx = sorted.findIndex((s: any) => s.team_id === teamId.value);
                        rankings[statKey] = idx !== -1 ? idx + 1 : 0;
                    }
                    statRankings.value = rankings;
                }

                if (key === 'trends' && res.ok) {
                    const data = await res.json();
                    trendsData.value = data.trends || null;
                    lockedTrends.value = data.locked_trends || null;
                }

                if (key === 'roster' && res.ok) {
                    const data = await res.json();
                    rosterPlayers.value = data.data || [];
                    rosterLoading.value = false;
                }
            }
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'An error occurred loading team data';
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
        rosterPlayers,
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
