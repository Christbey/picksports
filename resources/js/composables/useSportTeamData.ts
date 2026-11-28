import { computed } from 'vue';
import { isFinalGameStatus } from '@/lib/gameStatus';
import {
    formatTeamGameDate,
    teamGameStart,
    teamMetricCalculationDate,
} from '@/lib/teamPageDates';
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
        metricRankingSamples,
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

    const formatDate = formatTeamGameDate;
    const compareStarts = (a: Game, b: Game, descending = false): number => {
        const first = teamGameStart(a),
            second = teamGameStart(b);
        if (first === null || second === null) {
            // Unverified calendar rows retain their input order; no kickoff is inferred.
            return first === second ? 0 : first === null ? 1 : -1;
        }
        return descending ? second - first : first - second;
    };

    const getOpponent = (game: Game, isHome: boolean) => {
        return isHome ? game.away_team : game.home_team;
    };

    const getGameResult = (game: Game) => gameOutcome(game, teamId.value);

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
        const metricId = (value: unknown): bigint | null => {
            if (typeof value === 'number')
                return Number.isSafeInteger(value) && value > 0
                    ? BigInt(value)
                    : null;
            if (typeof value === 'string' && /^[1-9][0-9]{0,19}$/.test(value))
                return BigInt(value);
            return null;
        };
        const newest = new Map<number, any>();
        for (const metric of baseMetrics) {
            const key = toNumber(metric.team_id);
            if (key === null) continue;
            const current = newest.get(key);
            if (!current) {
                newest.set(key, metric);
                continue;
            }
            const date = teamMetricCalculationDate(metric.calculation_date);
            const previousDate = teamMetricCalculationDate(
                current.calculation_date,
            );
            const id = metricId(metric.id),
                previousId = metricId(current.id);
            // Match latestForTeam's calculation_date DESC, id DESC policy, not API net-rating order.
            if (
                (date !== null &&
                    (previousDate === null || date > previousDate)) ||
                (date === previousDate &&
                    id !== null &&
                    (previousId === null || id > previousId))
            )
                newest.set(key, metric);
        }
        const byTeam = Array.from(newest.values());

        metricRankingTotalTeams.value = byTeam.length;

        if (props.config.showPowerRanking) {
            const sortedByNet = byTeam
                .filter((row) => toNumber(row.net_rating) !== null)
                .sort(
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
                    : { rank: index + 1, total_teams: sortedByNet.length };
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
                metricRankingSamples.value[key] = sorted.length;
            }
            metricRankings.value = rankings;
        }
    };

    const applyStatRankings = (allStats: any[]) => {
        if (!props.config.statRankingKeys) return;

        const rankings: Record<string, number> = {};
        for (const { key, descending = true } of props.config.statRankingKeys) {
            const sorted = allStats
                .filter((row) => toNumber(row[key]) !== null)
                .sort((a, b) =>
                    descending
                        ? toNumber(b[key])! - toNumber(a[key])!
                        : toNumber(a[key])! - toNumber(b[key])!,
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
            .filter((game) => isFinalGameStatus(game.status))
            .sort((a, b) => compareStarts(a, b, true));

        const now = Date.now();
        upcomingGames.value = sourceGames
            .filter((game) => {
                if (props.config.sortRecentByDate) {
                    const start = teamGameStart(game);
                    return (
                        game.status === 'STATUS_IN_PROGRESS' ||
                        (game.status === 'STATUS_SCHEDULED' &&
                            (start === null || start >= now))
                    );
                }

                return (
                    game.status === 'STATUS_SCHEDULED' ||
                    game.status === 'STATUS_IN_PROGRESS'
                );
            })
            .sort((a, b) => compareStarts(a, b));
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
