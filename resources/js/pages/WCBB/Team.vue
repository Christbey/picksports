<script setup lang="ts">
import SportTeam, { type TeamPageConfig } from '@/components/SportTeam.vue'
import { formatNumber, ratingClass } from '@/components/sport-team-metrics-helpers'
import WCBBGameController from '@/actions/App/Http/Controllers/WCBB/GameController'
import { type Team } from '@/types'

const props = defineProps<{ team: Team }>()

const config: TeamPageConfig = {
    sport: 'wcbb',
    sportLabel: 'WCBB',
    predictionsHref: '/wcbb-predictions',
    metricsHref: '/wcbb-team-metrics',
    headTitle: (t) => t.name,
    teamDisplayName: (t) => t.display_name || t.name,
    teamLogo: (t) => t.logo,
    teamSubtitle: (t) => `${t.conference}${t.division ? ` • ${t.division}` : ''}`,
    teamHref: (id) => `/wcbb/teams/${id}`,
    gameLink: (id) => WCBBGameController(id),
    apiBase: '/api/v1/wcbb',
    useTabs: true,
    showPowerRanking: true,
    showRecentForm: true,
    showTrends: true,
    recentGamesLimit: 5,
    upcomingGamesLimit: 5,
    metricTiles: [
        { label: 'ORtg', value: (m) => formatNumber(m.offensive_rating) },
        { label: 'DRtg', value: (m) => formatNumber(m.defensive_rating) },
        { label: 'Net Rating', value: (m) => formatNumber(m.net_rating), class: (m) => ratingClass(m.net_rating) },
        { label: 'Pace', value: (m) => formatNumber(m.pace) },
        { label: 'SOS', value: (m) => formatNumber(m.strength_of_schedule, 3) },
    ],
}
</script>

<template>
    <SportTeam :config="config" :team="props.team" />
</template>
