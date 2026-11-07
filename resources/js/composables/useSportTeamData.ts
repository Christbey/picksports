import { computed } from 'vue';
import { gameOutcome, finiteValue as toNumber } from '@/lib/gameOutcome';
import { useSportTeamRequests } from '@/composables/useSportTeamRequests';
import type { BreadcrumbItem, Game } from '@/types';
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

    const requests = useSportTeamRequests(props, {
        games: (rows) => applyGames(rows),
        metricRankings: (rows) => applyMetricRankings(rows),
        statRankings: (rows) => applyStatRankings(rows),
    });
    const {
        teamData,
        teamMetrics,
        recentGames,
        upcomingGames,
        powerRanking,
        statRankings,
        metricRankings,
        metricRankingTotalTeams,
        trendsData,
        lockedTrends,
    } = requests;

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

    const getGameResult = (game: Game) =>
        game.status === 'STATUS_FINAL' ? gameOutcome(game, teamId.value) : null;

    const summarizeRecord = (games: Game[]) => {
        const results = games.map(getGameResult);
        const count = (result: 'W' | 'L' | 'T') =>
            results.filter((value) => value === result).length;
        return {
            wins: count('W'),
            losses: count('L'),
            ties: count('T'),
            games: results.filter(Boolean).length,
        };
    };
    const record = computed(() => summarizeRecord(recentGames.value));
    const recentForm = computed(() =>
        recentGames.value.slice(0, 5).map(getGameResult).filter(Boolean),
    );
    const recentRecord = computed(() =>
        summarizeRecord(recentGames.value.slice(0, 5)),
    );

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

    const applyMetricRankings = (allMetricsRaw: any[]) => {
        const season = toNumber(teamMetrics.value?.season);
        const seasonMetrics =
            season !== null
                ? allMetricsRaw.filter(
                      (metric: any) => toNumber(metric.season) === season,
                  )
                : allMetricsRaw;
        const baseMetrics = seasonMetrics;
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
        const sourceGames = seasonScopedGames;

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

    return {
        ...requests,
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
