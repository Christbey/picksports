<script setup lang="ts">
import SportTeam, { type TeamPageConfig } from '@/components/SportTeam.vue'
import { formatNumber, ratingClass } from '@/components/sport-team-metrics-helpers'
import CBBGameController from '@/actions/App/Http/Controllers/CBB/GameController'

const props = defineProps<{ teamId: number }>()

const config: TeamPageConfig = {
    sport: 'cbb',
    sportLabel: 'CBB',
    predictionsHref: '/cbb-predictions',
    metricsHref: '/cbb-team-metrics',
    headTitle: (t) => t.name,
    teamDisplayName: (t) => t.display_name || t.name,
    teamLogo: (t) => t.logo,
    teamSubtitle: (t) => `${t.conference}${t.division ? ` • ${t.division}` : ''}`,
    teamHref: (id) => `/cbb/teams/${id}`,
    gameLink: (id) => CBBGameController(id),
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
    metricTiles: [
        { label: 'ORtg', value: (m) => formatNumber(m.offensive_rating) },
        { label: 'DRtg', value: (m) => formatNumber(m.defensive_rating) },
        { label: 'Net Rating', value: (m) => formatNumber(m.net_rating), class: (m) => ratingClass(m.net_rating) },
        { label: 'Pace', value: (m) => formatNumber(m.pace) },
        { label: 'SOS', value: (m) => formatNumber(m.strength_of_schedule, 3) },
    ],
    seasonStatTiles: [
        { label: 'PPG', value: (s) => formatNumber(s.points_per_game) },
        { label: 'RPG', value: (s) => formatNumber(s.rebounds_per_game) },
        { label: 'APG', value: (s) => formatNumber(s.assists_per_game) },
        { label: 'FG%', value: (s) => `${formatNumber(s.field_goal_percentage)}%` },
        { label: '3P%', value: (s) => `${formatNumber(s.three_point_percentage)}%` },
        { label: 'FT%', value: (s) => `${formatNumber(s.free_throw_percentage)}%` },
        { label: 'SPG', value: (s) => formatNumber(s.steals_per_game) },
        { label: 'BPG', value: (s) => formatNumber(s.blocks_per_game) },
        { label: 'TPG', value: (s) => formatNumber(s.turnovers_per_game) },
        { label: 'ORPG', value: (s) => formatNumber(s.offensive_rebounds_per_game) },
        { label: 'DRPG', value: (s) => formatNumber(s.defensive_rebounds_per_game) },
        { label: 'Fast Break PPG', value: (s) => formatNumber(s.fast_break_points_per_game) },
        { label: 'Paint PPG', value: (s) => formatNumber(s.points_in_paint_per_game) },
        { label: '2nd Chance PPG', value: (s) => formatNumber(s.second_chance_points_per_game) },
        { label: 'Bench PPG', value: (s) => formatNumber(s.bench_points_per_game) },
    ],
}
</script>

<template>
    <SportTeam :config="config" :team-id="props.teamId" />
</template>
