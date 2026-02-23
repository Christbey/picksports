<script setup lang="ts">
import SportTeam, { type TeamPageConfig } from '@/components/SportTeam.vue'
import { formatNumber, formatBattingAverage } from '@/components/sport-team-metrics-helpers'
import MLBGameController from '@/actions/App/Http/Controllers/MLB/GameController'

const props = defineProps<{
    team: any
    metrics: any
    recentGames: any[]
    upcomingGames: any[]
    seasonStats: any
}>()

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

const config: TeamPageConfig = {
    sport: 'mlb',
    sportLabel: 'MLB',
    predictionsHref: '/mlb-predictions',
    metricsHref: '/mlb-team-metrics',
    headTitle: (t) => `${t.location} ${t.name}`,
    teamDisplayName: (t) => `${t.location} ${t.name}`,
    teamLogo: (t) => t.logo_url,
    teamSubtitle: (t) => `${t.league}${t.division ? ` • ${t.division}` : ''}`,
    teamHref: (id) => `/mlb/teams/${id}`,
    gameLink: (id) => MLBGameController(id),
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
}
</script>

<template>
    <SportTeam
        :config="config"
        :team="props.team"
        :preloaded-metrics="props.metrics"
        :preloaded-season-stats="props.seasonStats"
        :preloaded-recent-games="props.recentGames"
        :preloaded-upcoming-games="props.upcomingGames"
    />
</template>
