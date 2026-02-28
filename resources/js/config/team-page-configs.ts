import { formatBattingAverage, formatNumber, ratingClass } from '@/components/sport-team-metrics-helpers'
import type { TeamPageConfig } from '@/types'

const basketballMetricTiles = [
    { label: 'ORtg', value: (m: any) => formatNumber(m.offensive_rating) },
    { label: 'DRtg', value: (m: any) => formatNumber(m.defensive_rating) },
    { label: 'Net Rating', value: (m: any) => formatNumber(m.net_rating), class: (m: any) => ratingClass(m.net_rating) },
    { label: 'Pace', value: (m: any) => formatNumber(m.pace) },
    { label: 'SOS', value: (m: any) => formatNumber(m.strength_of_schedule, 3) },
]

const basketballSeasonStatTiles = [
    { label: 'PPG', value: (s: any) => formatNumber(s.points_per_game) },
    { label: 'RPG', value: (s: any) => formatNumber(s.rebounds_per_game) },
    { label: 'APG', value: (s: any) => formatNumber(s.assists_per_game) },
    { label: 'FG%', value: (s: any) => `${formatNumber(s.field_goal_percentage)}%` },
    { label: '3P%', value: (s: any) => `${formatNumber(s.three_point_percentage)}%` },
    { label: 'FT%', value: (s: any) => `${formatNumber(s.free_throw_percentage)}%` },
    { label: 'SPG', value: (s: any) => formatNumber(s.steals_per_game) },
    { label: 'BPG', value: (s: any) => formatNumber(s.blocks_per_game) },
    { label: 'TPG', value: (s: any) => formatNumber(s.turnovers_per_game) },
    { label: 'ORPG', value: (s: any) => formatNumber(s.offensive_rebounds_per_game) },
    { label: 'DRPG', value: (s: any) => formatNumber(s.defensive_rebounds_per_game) },
    { label: 'Fast Break PPG', value: (s: any) => formatNumber(s.fast_break_points_per_game) },
    { label: 'Paint PPG', value: (s: any) => formatNumber(s.points_in_paint_per_game) },
    { label: '2nd Chance PPG', value: (s: any) => formatNumber(s.second_chance_points_per_game) },
    { label: 'Bench PPG', value: (s: any) => formatNumber(s.bench_points_per_game) },
]

const basketballRankingKeys = [
    { key: 'points_per_game' },
    { key: 'rebounds_per_game' },
    { key: 'assists_per_game' },
    { key: 'field_goal_percentage' },
    { key: 'three_point_percentage' },
    { key: 'free_throw_percentage' },
    { key: 'steals_per_game' },
    { key: 'blocks_per_game' },
    { key: 'turnovers_per_game', descending: false },
]

type GameLink = (id: number) => any

export const createNbaTeamConfig = (gameLink: GameLink): TeamPageConfig => ({
    sport: 'nba',
    sportLabel: 'NBA',
    predictionsHref: '/nba-predictions',
    metricsHref: '/nba-team-metrics',
    headTitle: (t) => t.name,
    teamDisplayName: (t) => t.display_name || t.name,
    teamLogo: (t) => t.logo,
    teamSubtitle: (t) => `${t.conference}${t.division ? ` • ${t.division}` : ''}`,
    teamHref: (id) => `/nba/teams/${id}`,
    gameLink,
    apiBase: '/api/v1/nba',
    useTabs: true,
    showPowerRanking: true,
    showRecentForm: true,
    showTrends: true,
    showRoster: true,
    playerLink: (id) => `/nba/players/${id}`,
    trendsGames: 20,
    recentGamesLimit: 10,
    upcomingGamesLimit: 5,
    overviewStatCount: 6,
    seasonStatsGridCols: 'md:grid-cols-4 lg:grid-cols-6',
    metricTiles: basketballMetricTiles,
    seasonStatTiles: basketballSeasonStatTiles,
    statRankingKeys: basketballRankingKeys,
})

export const createCbbTeamConfig = (gameLink: GameLink): TeamPageConfig => ({
    sport: 'cbb',
    sportLabel: 'CBB',
    predictionsHref: '/cbb-predictions',
    metricsHref: '/cbb-team-metrics',
    headTitle: (t) => t.name,
    teamDisplayName: (t) => t.display_name || t.name,
    teamLogo: (t) => t.logo,
    teamSubtitle: (t) => `${t.conference}${t.division ? ` • ${t.division}` : ''}`,
    teamHref: (id) => `/cbb/teams/${id}`,
    gameLink,
    apiBase: '/api/v1/cbb',
    useTabs: true,
    showPowerRanking: true,
    showRecentForm: true,
    showTrends: true,
    sortRecentByDate: true,
    recentGamesLimit: 10,
    upcomingGamesLimit: 10,
    overviewStatCount: 6,
    seasonStatsGridCols: 'md:grid-cols-4 lg:grid-cols-6',
    metricTiles: basketballMetricTiles,
    seasonStatTiles: basketballSeasonStatTiles,
})

const createSimpleBasketballConfig = (
    sport: 'wnba' | 'wcbb',
    sportLabel: 'WNBA' | 'WCBB',
    predictionsHref: string,
    metricsHref: string,
    teamHrefPrefix: string,
    gameLink: GameLink,
): TeamPageConfig => ({
    sport,
    sportLabel,
    predictionsHref,
    metricsHref,
    headTitle: (t) => t.name,
    teamDisplayName: (t) => t.display_name || t.name,
    teamLogo: (t) => t.logo,
    teamSubtitle: (t) => `${t.conference}${t.division ? ` • ${t.division}` : ''}`,
    teamHref: (id) => `${teamHrefPrefix}/${id}`,
    gameLink,
    apiBase: `/api/v1/${sport}`,
    useTabs: true,
    showPowerRanking: true,
    showRecentForm: true,
    showTrends: true,
    recentGamesLimit: 5,
    upcomingGamesLimit: 5,
    metricTiles: basketballMetricTiles,
})

export const createWnbaTeamConfig = (gameLink: GameLink): TeamPageConfig =>
    createSimpleBasketballConfig('wnba', 'WNBA', '/wnba-predictions', '/wnba-team-metrics', '/wnba/teams', gameLink)

export const createWcbbTeamConfig = (gameLink: GameLink): TeamPageConfig =>
    createSimpleBasketballConfig('wcbb', 'WCBB', '/wcbb-predictions', '/wcbb-team-metrics', '/wcbb/teams', gameLink)

export const createNflTeamConfig = (gameLink: GameLink): TeamPageConfig => ({
    sport: 'nfl',
    sportLabel: 'NFL',
    predictionsHref: '/nfl-predictions',
    headTitle: (t) => t.name,
    teamDisplayName: (t) => t.display_name || t.name,
    teamLogo: (t) => t.logo,
    teamSubtitle: (t) => `${t.conference}${t.division ? ` • ${t.division}` : ''}`,
    teamHref: (id) => `/nfl/teams/${id}`,
    gameLink,
    apiBase: '/api/v1/nfl',
    useTabs: true,
    showPowerRanking: true,
    showRecentForm: true,
    showTrends: true,
    recentGamesLimit: 5,
    upcomingGamesLimit: 5,
    metricsGridCols: 'md:grid-cols-3 lg:grid-cols-5',
    headerInfo: (team, { record }) => {
        const items: { label: string; value: string }[] = []
        if (record.wins > 0 || record.losses > 0) {
            items.push({ label: 'Record', value: `${record.wins}-${record.losses}` })
        }
        if (team.elo_rating) {
            items.push({ label: 'ELO', value: String(team.elo_rating) })
        }
        return items
    },
    metricTiles: [
        { label: 'Off Rating', value: (m) => formatNumber(m.offensive_rating) },
        { label: 'Def Rating', value: (m) => formatNumber(m.defensive_rating) },
        { label: 'Net Rating', value: (m) => formatNumber(m.net_rating), class: (m) => ratingClass(m.net_rating) },
        { label: 'PPG', value: (m) => formatNumber(m.points_per_game) },
        { label: 'Pts Allowed', value: (m) => formatNumber(m.points_allowed_per_game) },
        { label: 'Yards/Game', value: (m) => formatNumber(m.yards_per_game, 0) },
        { label: 'Yards Allowed', value: (m) => formatNumber(m.yards_allowed_per_game, 0) },
        { label: 'Pass Yds/G', value: (m) => formatNumber(m.passing_yards_per_game, 0) },
        { label: 'Rush Yds/G', value: (m) => formatNumber(m.rushing_yards_per_game, 0) },
        {
            label: 'TO Diff',
            value: (m) => `${m.turnover_differential > 0 ? '+' : ''}${formatNumber(m.turnover_differential, 0)}`,
            class: (m) => ratingClass(m.turnover_differential),
        },
    ],
})

const eraClass = (value: number | null): string => {
    if (value === null) return ''
    if (value < 3.5) return 'text-green-600 dark:text-green-400 font-semibold'
    if (value < 4.0) return 'text-green-600 dark:text-green-400'
    if (value > 5.0) return 'text-red-600 dark:text-red-400 font-semibold'
    if (value > 4.5) return 'text-red-600 dark:text-red-400'
    return ''
}

const rpgClass = (value: number | null): string => {
    if (value === null) return ''
    if (value > 5) return 'text-green-600 dark:text-green-400 font-semibold'
    if (value > 4.5) return 'text-green-600 dark:text-green-400'
    if (value < 3.5) return 'text-red-600 dark:text-red-400 font-semibold'
    if (value < 4) return 'text-red-600 dark:text-red-400'
    return ''
}

export const createMlbTeamConfig = (gameLink: GameLink): TeamPageConfig => ({
    sport: 'mlb',
    sportLabel: 'MLB',
    predictionsHref: '/mlb-predictions',
    metricsHref: '/mlb-team-metrics',
    headTitle: (t) => `${t.location} ${t.name}`,
    teamDisplayName: (t) => `${t.location} ${t.name}`,
    teamLogo: (t) => t.logo_url,
    teamSubtitle: (t) => `${t.league}${t.division ? ` • ${t.division}` : ''}`,
    teamHref: (id) => `/mlb/teams/${id}`,
    gameLink,
    apiBase: '/api/v1/mlb',
    useTabs: true,
    showPowerRanking: true,
    showRecentForm: true,
    showTrends: true,
    metricsGridCols: 'md:grid-cols-4 lg:grid-cols-8',
    seasonStatsGridCols: 'md:grid-cols-4 lg:grid-cols-7',
    headerInfo: (team) => {
        const items: { label: string; value: string }[] = []
        if (team.elo_rating) {
            items.push({ label: 'Elo Rating', value: String(team.elo_rating) })
        }
        return items
    },
    metricTiles: [
        { label: 'R/G', value: (m) => formatNumber(m.runs_per_game, 2), class: (m) => rpgClass(m.runs_per_game) },
        { label: 'RA/G', value: (m) => formatNumber(m.runs_allowed_per_game, 2), class: (m) => eraClass(m.runs_allowed_per_game) },
        { label: 'AVG', value: (m) => formatBattingAverage(m.batting_average) },
        { label: 'ERA', value: (m) => formatNumber(m.team_era, 2), class: (m) => eraClass(m.team_era) },
        { label: 'ORtg', value: (m) => formatNumber(m.offensive_rating) },
        { label: 'PRtg', value: (m) => formatNumber(m.pitching_rating) },
        { label: 'DRtg', value: (m) => formatNumber(m.defensive_rating) },
        { label: 'SOS', value: (m) => formatNumber(m.strength_of_schedule, 3) },
    ],
    seasonStatTiles: [
        { label: 'Runs', value: (s) => formatNumber(s.runs_per_game, 2) },
        { label: 'Hits', value: (s) => formatNumber(s.hits_per_game, 2) },
        { label: 'HR', value: (s) => formatNumber(s.home_runs_per_game, 2) },
        { label: 'RBI', value: (s) => formatNumber(s.rbis_per_game, 2) },
        { label: 'BB', value: (s) => formatNumber(s.walks_per_game, 2) },
        { label: 'K', value: (s) => formatNumber(s.strikeouts_per_game, 2) },
        { label: 'SB', value: (s) => formatNumber(s.stolen_bases_per_game, 2) },
        { label: '2B', value: (s) => formatNumber(s.doubles_per_game, 2) },
        { label: '3B', value: (s) => formatNumber(s.triples_per_game, 2) },
        { label: 'AVG', value: (s) => formatBattingAverage(s.batting_average) },
        { label: 'ERA', value: (s) => formatNumber(s.era, 2), class: (s) => eraClass(s.era) },
        { label: 'ER/G', value: (s) => formatNumber(s.earned_runs_per_game, 2) },
        { label: 'E/G', value: (s) => formatNumber(s.errors_per_game, 2) },
    ],
})
