<script setup lang="ts">
import SportTeam, { type TeamPageConfig } from '@/components/SportTeam.vue'
import { formatNumber, ratingClass } from '@/components/sport-team-metrics-helpers'
import NFLGameController from '@/actions/App/Http/Controllers/NFL/GameController'
import { type Team } from '@/types'

const props = defineProps<{ team: Team }>()

const config: TeamPageConfig = {
    sport: 'nfl',
    sportLabel: 'NFL',
    predictionsHref: '/nfl-predictions',
    headTitle: (t) => t.name,
    teamDisplayName: (t) => t.display_name || t.name,
    teamLogo: (t) => t.logo,
    teamSubtitle: (t) => `${t.conference}${t.division ? ` • ${t.division}` : ''}`,
    teamHref: (id) => `/nfl/teams/${id}`,
    gameLink: (id) => NFLGameController(id),
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
}
</script>

<template>
    <SportTeam :config="config" :team="props.team" />
</template>
