import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { finiteValue } from '@/lib/gameOutcome';
import { useApiV2Client } from '@/composables/useApiV2Client';
import type { ApiV2SportSlug, Game, GameDepthChartTeam } from '@/types';
import type { TeamPageConfig } from '@/types/sport-team';

export type TeamSection =
    | 'metrics'
    | 'games'
    | 'seasonStats'
    | 'metricRankings'
    | 'statRankings'
    | 'trends'
    | 'roster'
    | 'depthChart';
export const teamSectionLabels: Record<TeamSection, string> = {
    metrics: 'Team metrics',
    games: 'Games',
    seasonStats: 'Season statistics',
    metricRankings: 'Metric rankings',
    statRankings: 'Stat rankings',
    trends: 'Trends',
    roster: 'Roster',
    depthChart: 'Depth chart',
};
export function useSportTeamRequests(
    props: { config: TeamPageConfig; teamId: number },
    apply: {
        games: (rows: Game[]) => void;
        metricRankings: (rows: any[]) => void;
        statRankings: (rows: any[]) => void;
    },
) {
    const api = useApiV2Client();
    const teamData = ref<any>(null),
        teamMetrics = ref<any>(null),
        seasonStats = ref<any>(null);
    const recentGames = ref<Game[]>([]),
        upcomingGames = ref<Game[]>([]),
        powerRanking = ref<{ rank: number; total_teams: number } | null>(null);
    const statRankings = ref<Record<string, number>>({}),
        metricRankings = ref<Record<string, number>>({}),
        metricRankingTotalTeams = ref(0),
        rosterPlayers = ref<any[]>([]),
        depthChart = ref<GameDepthChartTeam | null>(null);
    const trendsData = ref<Record<string, string[]> | null>(null),
        lockedTrends = ref<Record<string, string> | null>(null);
    const loading = ref(true),
        error = ref<string | null>(null),
        sectionErrors = ref<Partial<Record<TeamSection, string>>>({}),
        sectionLoading = ref<Partial<Record<TeamSection, boolean>>>({});
    const rosterLoading = computed(() => sectionLoading.value.roster ?? false);
    let controller: AbortController | undefined,
        version = 0,
        disposed = false,
        loadedKey: string | null = null;
    let tasks: Partial<Record<TeamSection, () => Promise<void>>> = {};
    const resetDetails = () => {
        teamMetrics.value = null;
        seasonStats.value = null;
        recentGames.value = [];
        upcomingGames.value = [];
        powerRanking.value = null;
        statRankings.value = {};
        metricRankings.value = {};
        metricRankingTotalTeams.value = 0;
        rosterPlayers.value = [];
        depthChart.value = null;
        trendsData.value = null;
        lockedTrends.value = null;
    };
    const message = (cause: unknown, fallback: string) =>
        cause instanceof Error ? cause.message : fallback;
    async function retrySection(section: TeamSection) {
        if (section === 'metrics') return loadTeam();
        if (disposed || sectionLoading.value[section]) return;
        await tasks[section]?.();
    }
    async function loadTeam() {
        if (disposed) return;
        const current = ++version;
        controller?.abort();
        controller = new AbortController();
        const signal = controller.signal,
            active = () => !disposed && current === version && !signal.aborted;
        const fetchId = props.teamId,
            sport = props.config.sport as ApiV2SportSlug,
            config = props.config,
            key = JSON.stringify([sport, fetchId]);
        tasks = {};
        sectionErrors.value = {};
        sectionLoading.value = {};
        loading.value = true;
        error.value = null;
        if (loadedKey !== key) {
            teamData.value = null;
            resetDetails();
        }
        const options = { init: { signal } };
        const requireResponse = <T>(response: T | null): T => {
            if (response === null)
                throw new Error('Team data is temporarily unavailable.');
            return response;
        };
        const task = (
            section: TeamSection,
            fetch: () => Promise<unknown>,
            accept: (response: any) => void,
        ) => {
            const run = async () => {
                if (!active() || sectionLoading.value[section]) return;
                sectionLoading.value[section] = true;
                delete sectionErrors.value[section];
                try {
                    const response = requireResponse(await fetch());
                    if (active()) accept(response);
                } catch (cause) {
                    if (active())
                        sectionErrors.value[section] = message(
                            cause,
                            `Unable to load ${teamSectionLabels[section].toLowerCase()}.`,
                        );
                } finally {
                    if (active()) sectionLoading.value[section] = false;
                }
            };
            tasks[section] = run;
            return run();
        };
        try {
            if (!fetchId) throw new Error('This team could not be found.');
            const [teamResponse, metricResult] = await Promise.all([
                api.teams.show(sport, fetchId, options).then((response) => {
                    if (!response?.data)
                        throw new Error(
                            'This team could not be loaded. Please try again.',
                        );
                    return response;
                }),
                api.teams.metrics(sport, fetchId, options).then(
                    (value) => ({ status: 'fulfilled' as const, value }),
                    (reason) => ({ status: 'rejected' as const, reason }),
                ),
            ]);
            if (!active()) return;
            teamData.value = teamResponse.data;
            loadedKey = key;
            if (metricResult.status === 'fulfilled' && metricResult.value) {
                const metrics = metricResult.value.data ?? null;
                if (teamMetrics.value?.season !== metrics?.season)
                    resetDetails();
                teamMetrics.value = metrics;
            } else {
                sectionErrors.value.metrics = message(
                    metricResult.status === 'rejected'
                        ? metricResult.reason
                        : null,
                    'Team metrics are temporarily unavailable.',
                );
            }
            loading.value = false;
            const validSeason = finiteValue(teamMetrics.value?.season);
            const seasonQuery =
                validSeason !== null ? { season: validSeason } : {};
            const supplemental = [
                task(
                    'games',
                    () =>
                        api.teams.games(sport, fetchId, {
                            query: { per_page: 200, ...seasonQuery },
                            init: { signal },
                        }),
                    (response) => apply.games((response.data ?? []) as Game[]),
                ),
            ];
            if (config.seasonStatTiles)
                supplemental.push(
                    task(
                        'seasonStats',
                        () =>
                            api.teams.statSeasonAverages(
                                sport,
                                fetchId,
                                options,
                            ),
                        (response) => {
                            seasonStats.value = response.data ?? null;
                        },
                    ),
                );
            if (config.showPowerRanking || config.metricRankingKeys)
                supplemental.push(
                    task(
                        'metricRankings',
                        () =>
                            api.metrics.teams(sport, {
                                query: { ...seasonQuery, per_page: 500 },
                                init: { signal },
                            }),
                        (response) => apply.metricRankings(response.data ?? []),
                    ),
                );
            if (config.statRankingKeys)
                supplemental.push(
                    task(
                        'statRankings',
                        () => api.stats.teamSeasonAverages(sport, options),
                        (response) => apply.statRankings(response.data ?? []),
                    ),
                );
            if (config.showTrends)
                supplemental.push(
                    task(
                        'trends',
                        () =>
                            api.teams.trends(sport, fetchId, {
                                query: { games: config.trendsGames ?? 20 },
                                init: { signal },
                            }),
                        (response) => {
                            trendsData.value = response.data?.trends ?? null;
                            lockedTrends.value =
                                response.data?.locked_trends ?? null;
                        },
                    ),
                );
            if (config.showRoster)
                supplemental.push(
                    task(
                        'roster',
                        () =>
                            api.teams.players(sport, fetchId, {
                                query: { per_page: 100 },
                                init: { signal },
                            }),
                        (response) => {
                            rosterPlayers.value = response.data ?? [];
                        },
                    ),
                );
            if (config.showDepthCharts && ['nfl', 'nba', 'mlb'].includes(sport))
                supplemental.push(
                    task(
                        'depthChart',
                        () =>
                            api.teams.depthCharts(sport, fetchId, {
                                query:
                                    validSeason !== null
                                        ? { season: validSeason }
                                        : undefined,
                                init: { signal },
                            }),
                        (response) => {
                            depthChart.value = response.data ?? null;
                        },
                    ),
                );
            await Promise.all(supplemental);
        } catch (cause) {
            if (active()) {
                error.value = message(
                    cause,
                    'An error occurred loading team data',
                );
                loading.value = false;
                controller.abort();
            }
        } finally {
            if (active()) loading.value = false;
        }
    }
    watch(
        () => [props.teamId, props.config, props.config.sport],
        () => void loadTeam(),
        { flush: 'sync' },
    );
    onMounted(() => void loadTeam());
    onBeforeUnmount(() => {
        disposed = true;
        version++;
        controller?.abort();
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
        trendsData,
        lockedTrends,
        loading,
        error,
        rosterLoading,
        sectionErrors,
        sectionLoading,
        loadTeam,
        retrySection,
        teamSectionLabels,
    };
}
