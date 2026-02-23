<script setup lang="ts">
import SportTeam, { type TeamPageConfig } from '@/components/SportTeam.vue'
import { formatNumber, ratingClass } from '@/components/sport-team-metrics-helpers'
import NBAGameController from '@/actions/App/Http/Controllers/NBA/GameController'
import { type Team } from '@/types'

const props = defineProps<{ team: Team }>()

const config: TeamPageConfig = {
    sport: 'nba',
    sportLabel: 'NBA',
    predictionsHref: '/nba-predictions',
    metricsHref: '/nba-team-metrics',
    headTitle: (t) => t.name,
    teamDisplayName: (t) => t.display_name || t.name,
    teamLogo: (t) => t.logo,
    teamSubtitle: (t) => `${t.conference}${t.division ? ` • ${t.division}` : ''}`,
    teamHref: (id) => `/nba/teams/${id}`,
    gameLink: (id) => NBAGameController(id),
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
    metricTiles: [
        { label: 'ORtg', value: (m) => formatNumber(m.offensive_rating) },
        { label: 'DRtg', value: (m) => formatNumber(m.defensive_rating) },
        { label: 'Net Rating', value: (m) => formatNumber(m.net_rating), class: (m) => ratingClass(m.net_rating) },
        { label: 'Pace', value: (m) => formatNumber(m.pace) },
        { label: 'SOS', value: (m) => formatNumber(m.strength_of_schedule, 3) },
    ],
    seasonStatTiles: [
        { label: 'PPG', value: (s) => formatNumber(s.points_per_game), rankingKey: 'points_per_game' },
        { label: 'RPG', value: (s) => formatNumber(s.rebounds_per_game), rankingKey: 'rebounds_per_game' },
        { label: 'APG', value: (s) => formatNumber(s.assists_per_game), rankingKey: 'assists_per_game' },
        { label: 'FG%', value: (s) => `${formatNumber(s.field_goal_percentage)}%`, rankingKey: 'field_goal_percentage' },
        { label: '3P%', value: (s) => `${formatNumber(s.three_point_percentage)}%`, rankingKey: 'three_point_percentage' },
        { label: 'FT%', value: (s) => `${formatNumber(s.free_throw_percentage)}%`, rankingKey: 'free_throw_percentage' },
        { label: 'SPG', value: (s) => formatNumber(s.steals_per_game), rankingKey: 'steals_per_game' },
        { label: 'BPG', value: (s) => formatNumber(s.blocks_per_game), rankingKey: 'blocks_per_game' },
        { label: 'TPG', value: (s) => formatNumber(s.turnovers_per_game), rankingKey: 'turnovers_per_game' },
        { label: 'ORPG', value: (s) => formatNumber(s.offensive_rebounds_per_game) },
        { label: 'DRPG', value: (s) => formatNumber(s.defensive_rebounds_per_game) },
        { label: 'Fast Break PPG', value: (s) => formatNumber(s.fast_break_points_per_game) },
        { label: 'Paint PPG', value: (s) => formatNumber(s.points_in_paint_per_game) },
        { label: '2nd Chance PPG', value: (s) => formatNumber(s.second_chance_points_per_game) },
        { label: 'Bench PPG', value: (s) => formatNumber(s.bench_points_per_game) },
    ],
    statRankingKeys: [
        { key: 'points_per_game' },
        { key: 'rebounds_per_game' },
        { key: 'assists_per_game' },
        { key: 'field_goal_percentage' },
        { key: 'three_point_percentage' },
        { key: 'free_throw_percentage' },
        { key: 'steals_per_game' },
        { key: 'blocks_per_game' },
        { key: 'turnovers_per_game', descending: false },
    ],
}
</script>

<template>
    <SportTeam :config="config" :team="props.team" />
</template>
