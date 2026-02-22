<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ref, onMounted, computed } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { type BreadcrumbItem, type Game, type Team, type Prediction, type TeamMetric } from '@/types'
import CBBTeamController from '@/actions/App/Http/Controllers/CBB/TeamController'

interface TeamTrends {
    team_id: number
    team_abbreviation: string
    team_name: string
    sample_size: number
    user_tier: string
    trends: Record<string, string[]>
    locked_trends: Record<string, string>
}

const props = defineProps<{
    game: Game
}>()

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'CBB', href: '/cbb-predictions' },
    { title: 'Games', href: '/cbb-predictions' },
    { title: `Game ${props.game.id}`, href: `/cbb/games/${props.game.id}` }
])

const homeTeam = ref<Team | null>(null)
const awayTeam = ref<Team | null>(null)
const prediction = ref<Prediction | null>(null)
const homeMetrics = ref<TeamMetric | null>(null)
const awayMetrics = ref<TeamMetric | null>(null)
const homeTeamStats = ref<any>(null)
const awayTeamStats = ref<any>(null)
const topPerformers = ref<any[]>([])
const homeRecentGames = ref<Game[]>([])
const awayRecentGames = ref<Game[]>([])
const homeTrends = ref<TeamTrends | null>(null)
const awayTrends = ref<TeamTrends | null>(null)
const trendsLoading = ref(false)
const loading = ref(true)
const error = ref<string | null>(null)

const formatNumber = (value: number | string | null, decimals = 1): string => {
    if (value === null || value === undefined) return '-'
    const num = typeof value === 'string' ? parseFloat(value) : value
    if (isNaN(num)) return '-'
    return num.toFixed(decimals)
}

const calculatePercentage = (made: number, attempted: number): string => {
    if (!attempted || attempted === 0) return '0.0'
    return ((made / attempted) * 100).toFixed(1)
}

const formatDate = (dateString: string | null): string => {
    if (!dateString) return '-'
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'long',
        day: 'numeric',
        year: 'numeric'
    })
}

const gameStatus = computed(() => {
    switch (props.game.status) {
        case 'STATUS_SCHEDULED':
            return 'Scheduled'
        case 'STATUS_IN_PROGRESS':
            return 'Live'
        case 'STATUS_FINAL':
            return 'Final'
        default:
            return props.game.status
    }
})

const getBetterValue = (homeVal: number, awayVal: number, lowerIsBetter = false): 'home' | 'away' | null => {
    if (lowerIsBetter) {
        if (homeVal < awayVal) return 'home'
        if (awayVal < homeVal) return 'away'
    } else {
        if (homeVal > awayVal) return 'home'
        if (awayVal > homeVal) return 'away'
    }
    return null
}

const parseLinescores = (linescoresStr: string | null): any[] => {
    if (!linescoresStr) return []
    try {
        return JSON.parse(linescoresStr)
    } catch {
        return []
    }
}

const getRecentForm = (games: Game[], teamId: number): string => {
    return games.map(g => {
        const isHome = g.home_team_id === teamId
        const teamScore = isHome ? g.home_score : g.away_score
        const oppScore = isHome ? g.away_score : g.home_score
        return teamScore && oppScore && teamScore > oppScore ? 'W' : 'L'
    }).join('-')
}

const broadcastNetworks = computed(() => {
    if (!props.game.broadcast_networks) return []
    try {
        return JSON.parse(props.game.broadcast_networks)
    } catch {
        return []
    }
})

const homeRecentForm = computed(() => {
    if (!homeTeam.value) return ''
    return getRecentForm(homeRecentGames.value, homeTeam.value.id)
})

const awayRecentForm = computed(() => {
    if (!awayTeam.value) return ''
    return getRecentForm(awayRecentGames.value, awayTeam.value.id)
})

const homeLinescores = computed(() => parseLinescores(props.game.home_line_scores))
const awayLinescores = computed(() => parseLinescores(props.game.away_line_scores))

const formatCategoryName = (key: string): string => {
    const names: Record<string, string> = {
        scoring: 'Scoring',
        halves: 'Halves',
        margins: 'Margins',
        totals: 'Totals',
        first_score: 'First Score',
        situational: 'Situational',
        streaks: 'Streaks',
        advanced: 'Advanced',
        time_based: 'Time Based',
        rest_schedule: 'Rest & Schedule',
        opponent_strength: 'Opponent Strength',
        conference: 'Conference',
        scoring_patterns: 'Scoring Patterns',
        offensive_efficiency: 'Offensive Efficiency',
        defensive_performance: 'Defensive',
        momentum: 'Momentum',
        clutch_performance: 'Clutch'
    }
    return names[key] || key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

const isLockedCategory = (category: string): boolean => {
    return !!(homeTrends.value?.locked_trends[category] || awayTrends.value?.locked_trends[category])
}

const getRequiredTier = (category: string): string => {
    return homeTrends.value?.locked_trends[category] || awayTrends.value?.locked_trends[category] || ''
}

const formatTierName = (tier: string): string => {
    const tierNames: Record<string, string> = {
        basic: 'Basic',
        pro: 'Pro',
        premium: 'Premium'
    }
    return tierNames[tier] || tier.charAt(0).toUpperCase() + tier.slice(1)
}

const allTrendCategories = computed(() => {
    const categories = new Set<string>()
    if (homeTrends.value?.trends) {
        Object.keys(homeTrends.value.trends).forEach(k => categories.add(k))
    }
    if (awayTrends.value?.trends) {
        Object.keys(awayTrends.value.trends).forEach(k => categories.add(k))
    }
    if (homeTrends.value?.locked_trends) {
        Object.keys(homeTrends.value.locked_trends).forEach(k => categories.add(k))
    }
    if (awayTrends.value?.locked_trends) {
        Object.keys(awayTrends.value.locked_trends).forEach(k => categories.add(k))
    }
    return Array.from(categories)
})

onMounted(async () => {
    try {
        loading.value = true
        error.value = null

        const [gameRes, predictionRes, teamStatsRes, playerStatsRes] = await Promise.all([
            fetch(`/api/v1/cbb/games/${props.game.id}`),
            fetch(`/api/v1/cbb/games/${props.game.id}/prediction`),
            fetch(`/api/v1/cbb/games/${props.game.id}/team-stats`),
            fetch(`/api/v1/cbb/games/${props.game.id}/player-stats`)
        ])

        if (gameRes.ok) {
            const gameData = await gameRes.json()
            const fullGame = gameData.data
            homeTeam.value = fullGame.home_team
            awayTeam.value = fullGame.away_team

            if (fullGame.home_team?.id) {
                const [homeMetricsRes, homeGamesRes] = await Promise.all([
                    fetch(`/api/v1/cbb/teams/${fullGame.home_team.id}/metrics`),
                    fetch(`/api/v1/cbb/teams/${fullGame.home_team.id}/games`)
                ])

                if (homeMetricsRes.ok) {
                    const data = await homeMetricsRes.json()
                    homeMetrics.value = data.data || null
                }

                if (homeGamesRes.ok) {
                    const gamesData = await homeGamesRes.json()
                    homeRecentGames.value = (gamesData.data || [])
                        .filter((g: Game) => g.status === 'STATUS_FINAL')
                        .slice(0, 5)
                }
            }

            if (fullGame.away_team?.id) {
                const [awayMetricsRes, awayGamesRes] = await Promise.all([
                    fetch(`/api/v1/cbb/teams/${fullGame.away_team.id}/metrics`),
                    fetch(`/api/v1/cbb/teams/${fullGame.away_team.id}/games`)
                ])

                if (awayMetricsRes.ok) {
                    const data = await awayMetricsRes.json()
                    awayMetrics.value = data.data || null
                }

                if (awayGamesRes.ok) {
                    const gamesData = await awayGamesRes.json()
                    awayRecentGames.value = (gamesData.data || [])
                        .filter((g: Game) => g.status === 'STATUS_FINAL')
                        .slice(0, 5)
                }
            }
        }

        if (predictionRes.ok) {
            const predictionData = await predictionRes.json()
            prediction.value = predictionData.data
        }

        if (teamStatsRes.ok) {
            const teamStatsData = await teamStatsRes.json()
            const stats = teamStatsData.data || []
            homeTeamStats.value = stats.find((s: any) => s.team_type === 'home')
            awayTeamStats.value = stats.find((s: any) => s.team_type === 'away')
        }

        if (playerStatsRes.ok) {
            const playerStatsData = await playerStatsRes.json()
            topPerformers.value = (playerStatsData.data || [])
                .sort((a: any, b: any) => (b.points || 0) - (a.points || 0))
                .slice(0, 10)
        }

        if (homeTeam.value?.id && awayTeam.value?.id) {
            trendsLoading.value = true
            const beforeDate = props.game.game_date
            const [homeTrendsRes, awayTrendsRes] = await Promise.all([
                fetch(`/api/v1/cbb/teams/${homeTeam.value.id}/trends?before_date=${beforeDate}`),
                fetch(`/api/v1/cbb/teams/${awayTeam.value.id}/trends?before_date=${beforeDate}`)
            ])

            if (homeTrendsRes.ok) {
                homeTrends.value = await homeTrendsRes.json()
            }
            if (awayTrendsRes.ok) {
                awayTrends.value = await awayTrendsRes.json()
            }
            trendsLoading.value = false
        }
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'An error occurred'
    } finally {
        loading.value = false
    }
})
</script>

<template>
    <Head :title="`${awayTeam?.name || 'Away'} @ ${homeTeam?.name || 'Home'}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <Alert v-if="error" variant="destructive">
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>

            <div v-if="loading" class="space-y-4">
                <Skeleton class="h-32 w-full" />
                <Skeleton class="h-64 w-full" />
                <Skeleton class="h-64 w-full" />
            </div>

            <template v-else>
                <!-- Matchup Hero -->
                <div class="rounded-xl overflow-hidden bg-gradient-to-r from-blue-600 to-blue-800 dark:from-blue-800 dark:to-blue-950 text-white shadow-lg">
                    <div class="px-6 py-8">
                        <div class="flex flex-col md:flex-row items-center justify-between gap-6">
                            <Link
                                v-if="awayTeam"
                                :href="CBBTeamController(awayTeam.id)"
                                class="flex-1 flex flex-col items-center md:items-end gap-2 hover:opacity-80 transition-opacity"
                            >
                                <img
                                    v-if="awayTeam.logo"
                                    :src="awayTeam.logo"
                                    :alt="awayTeam.name"
                                    class="w-20 h-20 object-contain drop-shadow-lg"
                                />
                                <div class="text-center md:text-right">
                                    <div class="text-xl md:text-2xl font-bold">{{ awayTeam.display_name || awayTeam.name }}</div>
                                    <div class="text-sm text-white/70">Away</div>
                                    <div v-if="awayRecentForm" class="text-xs text-white/60 mt-1">{{ awayRecentForm }}</div>
                                </div>
                            </Link>

                            <div class="text-center min-w-[120px]">
                                <div v-if="game.status === 'STATUS_FINAL'" class="text-4xl md:text-5xl font-bold tracking-tight">
                                    {{ game.away_score }} - {{ game.home_score }}
                                </div>
                                <div v-else class="text-2xl md:text-3xl font-bold text-white/70">
                                    vs
                                </div>
                                <Badge class="mt-2 bg-white/20 text-white border-white/30 hover:bg-white/30">{{ gameStatus }}</Badge>
                            </div>

                            <Link
                                v-if="homeTeam"
                                :href="CBBTeamController(homeTeam.id)"
                                class="flex-1 flex flex-col items-center md:items-start gap-2 hover:opacity-80 transition-opacity"
                            >
                                <img
                                    v-if="homeTeam.logo"
                                    :src="homeTeam.logo"
                                    :alt="homeTeam.name"
                                    class="w-20 h-20 object-contain drop-shadow-lg"
                                />
                                <div class="text-center md:text-left">
                                    <div class="text-xl md:text-2xl font-bold">{{ homeTeam.display_name || homeTeam.name }}</div>
                                    <div class="text-sm text-white/70">Home</div>
                                    <div v-if="homeRecentForm" class="text-xs text-white/60 mt-1">{{ homeRecentForm }}</div>
                                </div>
                            </Link>
                        </div>
                    </div>
                    <!-- Game Info Bar -->
                    <div class="bg-black/20 px-6 py-3 flex flex-wrap items-center justify-center gap-x-6 gap-y-1 text-sm text-white/80">
                        <span>{{ formatDate(game.game_date) }}</span>
                        <span v-if="game.venue" class="flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" /></svg>
                            {{ game.venue }}
                        </span>
                        <span v-if="broadcastNetworks.length > 0" class="flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6zm12.553 1.106A1 1 0 0014 8v4a1 1 0 00.553.894l2 1A1 1 0 0018 13V7a1 1 0 00-1.447-.894l-2 1z" /></svg>
                            {{ broadcastNetworks.join(', ') }}
                        </span>
                    </div>
                </div>

                <!-- Linescore Table -->
                <Card v-if="game.status === 'STATUS_FINAL' && (homeLinescores.length > 0 || awayLinescores.length > 0)">
                    <CardHeader>
                        <CardTitle>Half by Half</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b">
                                        <th class="text-left p-2 text-muted-foreground font-medium">Team</th>
                                        <th v-for="(_, index) in Math.max(homeLinescores.length, awayLinescores.length)" :key="index" class="text-center p-2 text-muted-foreground font-medium">
                                            H{{ index + 1 }}
                                        </th>
                                        <th class="text-center p-2 font-bold">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="border-b">
                                        <td class="p-2 font-medium">
                                            <span class="flex items-center gap-2">
                                                <img v-if="awayTeam?.logo" :src="awayTeam.logo" :alt="awayTeam.abbreviation" class="h-5 w-5 object-contain" />
                                                {{ awayTeam?.abbreviation || 'Away' }}
                                            </span>
                                        </td>
                                        <td v-for="(score, index) in awayLinescores" :key="index" class="text-center p-2">
                                            {{ score.value }}
                                        </td>
                                        <td class="text-center p-2 font-bold" :class="game.away_score > game.home_score ? 'text-green-600 dark:text-green-400' : ''">{{ game.away_score }}</td>
                                    </tr>
                                    <tr>
                                        <td class="p-2 font-medium">
                                            <span class="flex items-center gap-2">
                                                <img v-if="homeTeam?.logo" :src="homeTeam.logo" :alt="homeTeam.abbreviation" class="h-5 w-5 object-contain" />
                                                {{ homeTeam?.abbreviation || 'Home' }}
                                            </span>
                                        </td>
                                        <td v-for="(score, index) in homeLinescores" :key="index" class="text-center p-2">
                                            {{ score.value }}
                                        </td>
                                        <td class="text-center p-2 font-bold" :class="game.home_score > game.away_score ? 'text-green-600 dark:text-green-400' : ''">{{ game.home_score }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="game.status === 'STATUS_FINAL' && homeTeamStats && awayTeamStats">
                    <CardHeader>
                        <CardTitle>Box Score</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b">
                                        <th class="text-left p-2">Stat</th>
                                        <th class="text-center p-2">{{ awayTeam?.abbreviation || 'Away' }}</th>
                                        <th class="text-center p-2">{{ homeTeam?.abbreviation || 'Home' }}</th>
                                    </tr>
                                </thead>
                                <tbody class="text-sm">
                                    <tr class="border-b">
                                        <td class="p-2 font-medium">Field Goals</td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.field_goals_made, awayTeamStats.field_goals_made) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ awayTeamStats.field_goals_made }}-{{ awayTeamStats.field_goals_attempted }} ({{ calculatePercentage(awayTeamStats.field_goals_made, awayTeamStats.field_goals_attempted) }}%)
                                        </td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.field_goals_made, awayTeamStats.field_goals_made) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ homeTeamStats.field_goals_made }}-{{ homeTeamStats.field_goals_attempted }} ({{ calculatePercentage(homeTeamStats.field_goals_made, homeTeamStats.field_goals_attempted) }}%)
                                        </td>
                                    </tr>
                                    <tr class="border-b">
                                        <td class="p-2 font-medium">3-Point</td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.three_point_made, awayTeamStats.three_point_made) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ awayTeamStats.three_point_made }}-{{ awayTeamStats.three_point_attempted }} ({{ calculatePercentage(awayTeamStats.three_point_made, awayTeamStats.three_point_attempted) }}%)
                                        </td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.three_point_made, awayTeamStats.three_point_made) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ homeTeamStats.three_point_made }}-{{ homeTeamStats.three_point_attempted }} ({{ calculatePercentage(homeTeamStats.three_point_made, homeTeamStats.three_point_attempted) }}%)
                                        </td>
                                    </tr>
                                    <tr class="border-b">
                                        <td class="p-2 font-medium">Free Throws</td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.free_throws_made, awayTeamStats.free_throws_made) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ awayTeamStats.free_throws_made }}-{{ awayTeamStats.free_throws_attempted }} ({{ calculatePercentage(awayTeamStats.free_throws_made, awayTeamStats.free_throws_attempted) }}%)
                                        </td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.free_throws_made, awayTeamStats.free_throws_made) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ homeTeamStats.free_throws_made }}-{{ homeTeamStats.free_throws_attempted }} ({{ calculatePercentage(homeTeamStats.free_throws_made, homeTeamStats.free_throws_attempted) }}%)
                                        </td>
                                    </tr>
                                    <tr class="border-b">
                                        <td class="p-2 font-medium">Rebounds</td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.rebounds, awayTeamStats.rebounds) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ awayTeamStats.rebounds }}
                                        </td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.rebounds, awayTeamStats.rebounds) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ homeTeamStats.rebounds }}
                                        </td>
                                    </tr>
                                    <tr class="border-b">
                                        <td class="p-2 font-medium">Assists</td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.assists, awayTeamStats.assists) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ awayTeamStats.assists }}
                                        </td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.assists, awayTeamStats.assists) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ homeTeamStats.assists }}
                                        </td>
                                    </tr>
                                    <tr class="border-b">
                                        <td class="p-2 font-medium">Turnovers</td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.turnovers, awayTeamStats.turnovers, true) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ awayTeamStats.turnovers }}
                                        </td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.turnovers, awayTeamStats.turnovers, true) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ homeTeamStats.turnovers }}
                                        </td>
                                    </tr>
                                    <tr class="border-b">
                                        <td class="p-2 font-medium">Steals</td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.steals, awayTeamStats.steals) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ awayTeamStats.steals }}
                                        </td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.steals, awayTeamStats.steals) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ homeTeamStats.steals }}
                                        </td>
                                    </tr>
                                    <tr class="border-b">
                                        <td class="p-2 font-medium">Blocks</td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.blocks, awayTeamStats.blocks) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ awayTeamStats.blocks }}
                                        </td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.blocks, awayTeamStats.blocks) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ homeTeamStats.blocks }}
                                        </td>
                                    </tr>
                                    <tr v-if="awayTeamStats.points_in_paint && homeTeamStats.points_in_paint" class="border-b">
                                        <td class="p-2 font-medium">Points in Paint</td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.points_in_paint, awayTeamStats.points_in_paint) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ awayTeamStats.points_in_paint }}
                                        </td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.points_in_paint, awayTeamStats.points_in_paint) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ homeTeamStats.points_in_paint }}
                                        </td>
                                    </tr>
                                    <tr v-if="awayTeamStats.fast_break_points && homeTeamStats.fast_break_points">
                                        <td class="p-2 font-medium">Fast Break Points</td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.fast_break_points, awayTeamStats.fast_break_points) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ awayTeamStats.fast_break_points }}
                                        </td>
                                        <td class="text-center p-2" :class="getBetterValue(homeTeamStats.fast_break_points, awayTeamStats.fast_break_points) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                            {{ homeTeamStats.fast_break_points }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="game.status === 'STATUS_FINAL' && topPerformers.length > 0">
                    <CardHeader>
                        <CardTitle>Top Performers</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b text-sm">
                                        <th class="text-left p-2">Player</th>
                                        <th class="text-center p-2">Team</th>
                                        <th class="text-center p-2">PTS</th>
                                        <th class="text-center p-2">REB</th>
                                        <th class="text-center p-2">AST</th>
                                        <th class="text-center p-2">FG</th>
                                    </tr>
                                </thead>
                                <tbody class="text-sm">
                                    <tr v-for="player in topPerformers" :key="player.id" class="border-b">
                                        <td class="p-2 font-medium">{{ player.player?.name || 'Unknown' }}</td>
                                        <td class="text-center p-2">{{ player.team?.abbreviation || '-' }}</td>
                                        <td class="text-center p-2 font-bold">{{ player.points || 0 }}</td>
                                        <td class="text-center p-2">{{ player.rebounds || 0 }}</td>
                                        <td class="text-center p-2">{{ player.assists || 0 }}</td>
                                        <td class="text-center p-2">{{ player.field_goals_made || 0 }}-{{ player.field_goals_attempted || 0 }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="prediction">
                    <CardHeader>
                        <CardTitle>Prediction</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <!-- Win Probability Bar -->
                        <div class="mb-6">
                            <div class="flex items-center justify-between text-sm font-medium mb-2">
                                <span>{{ awayTeam?.abbreviation }} {{ formatNumber(prediction.away_win_probability * 100, 0) }}%</span>
                                <span>{{ homeTeam?.abbreviation }} {{ formatNumber(prediction.home_win_probability * 100, 0) }}%</span>
                            </div>
                            <div class="flex h-3 rounded-full overflow-hidden">
                                <div class="bg-blue-500 dark:bg-blue-600 transition-all" :style="{ width: `${prediction.away_win_probability * 100}%` }"></div>
                                <div class="bg-blue-800 dark:bg-blue-400 transition-all" :style="{ width: `${prediction.home_win_probability * 100}%` }"></div>
                            </div>
                        </div>
                        <!-- Stat Cards -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="text-center p-4 rounded-lg border">
                                <div class="text-sm text-muted-foreground">Spread</div>
                                <div class="text-2xl font-bold">
                                    {{ prediction.predicted_spread > 0 ? '+' : '' }}{{ formatNumber(prediction.predicted_spread) }}
                                </div>
                                <div class="text-xs text-muted-foreground mt-1">{{ prediction.predicted_spread < 0 ? (homeTeam?.abbreviation || 'Home') : (awayTeam?.abbreviation || 'Away') }} favored</div>
                            </div>
                            <div class="text-center p-4 rounded-lg border">
                                <div class="text-sm text-muted-foreground">Total</div>
                                <div class="text-2xl font-bold">
                                    {{ formatNumber(prediction.predicted_total) }}
                                </div>
                                <div class="text-xs text-muted-foreground mt-1">Projected points</div>
                            </div>
                            <div class="text-center p-4 rounded-lg border">
                                <div class="text-sm text-muted-foreground">Confidence</div>
                                <div class="text-2xl font-bold capitalize">
                                    {{ prediction.confidence_level }}
                                </div>
                                <div v-if="prediction.confidence_score" class="text-xs text-muted-foreground mt-1">Score: {{ formatNumber(prediction.confidence_score) }}</div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="homeMetrics && awayMetrics">
                    <CardHeader>
                        <CardTitle>Team Metrics Comparison</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-3">
                            <div class="grid grid-cols-7 gap-2 text-sm font-medium border-b pb-2">
                                <div class="col-span-2 text-right">{{ awayTeam?.abbreviation }}</div>
                                <div class="col-span-3 text-center">Metric</div>
                                <div class="col-span-2 text-left">{{ homeTeam?.abbreviation }}</div>
                            </div>

                            <div class="grid grid-cols-7 gap-2 items-center">
                                <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeMetrics.offensive_rating, awayMetrics.offensive_rating) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ formatNumber(awayMetrics.offensive_rating) }}
                                </div>
                                <div class="col-span-3 text-center text-sm text-muted-foreground">
                                    Offensive Rating
                                </div>
                                <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeMetrics.offensive_rating, awayMetrics.offensive_rating) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ formatNumber(homeMetrics.offensive_rating) }}
                                </div>
                            </div>

                            <div class="grid grid-cols-7 gap-2 items-center">
                                <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeMetrics.defensive_rating, awayMetrics.defensive_rating, true) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ formatNumber(awayMetrics.defensive_rating) }}
                                </div>
                                <div class="col-span-3 text-center text-sm text-muted-foreground">
                                    Defensive Rating
                                </div>
                                <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeMetrics.defensive_rating, awayMetrics.defensive_rating, true) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ formatNumber(homeMetrics.defensive_rating) }}
                                </div>
                            </div>

                            <div class="grid grid-cols-7 gap-2 items-center">
                                <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeMetrics.net_rating, awayMetrics.net_rating) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ formatNumber(awayMetrics.net_rating) }}
                                </div>
                                <div class="col-span-3 text-center text-sm text-muted-foreground">
                                    Net Rating
                                </div>
                                <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeMetrics.net_rating, awayMetrics.net_rating) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ formatNumber(homeMetrics.net_rating) }}
                                </div>
                            </div>

                            <div class="grid grid-cols-7 gap-2 items-center">
                                <div class="col-span-2 text-right font-medium">
                                    {{ formatNumber(awayMetrics.pace) }}
                                </div>
                                <div class="col-span-3 text-center text-sm text-muted-foreground">
                                    Pace
                                </div>
                                <div class="col-span-2 text-left font-medium">
                                    {{ formatNumber(homeMetrics.pace) }}
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="trendsLoading">
                    <CardHeader>
                        <CardTitle>Team Trends Comparison</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-4">
                            <Skeleton class="h-24 w-full" />
                            <Skeleton class="h-24 w-full" />
                            <Skeleton class="h-24 w-full" />
                        </div>
                    </CardContent>
                </Card>

                <Card v-else-if="(homeTrends || awayTrends) && allTrendCategories.length > 0">
                    <CardHeader>
                        <CardTitle>Team Trends Comparison</CardTitle>
                        <p class="text-sm text-muted-foreground">
                            Based on last {{ homeTrends?.sample_size || awayTrends?.sample_size || 20 }} games before this matchup
                        </p>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-6">
                            <template v-for="category in allTrendCategories" :key="category">
                                <div v-if="isLockedCategory(category)" class="p-4 bg-muted/50 rounded-lg border border-dashed">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-muted-foreground" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                                            </svg>
                                            <span class="font-medium text-muted-foreground">{{ formatCategoryName(category) }}</span>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <Badge variant="secondary">{{ formatTierName(getRequiredTier(category)) }} Required</Badge>
                                            <Link href="/pricing" class="text-sm text-primary hover:underline">
                                                Upgrade
                                            </Link>
                                        </div>
                                    </div>
                                </div>

                                <div v-else class="space-y-3">
                                    <h4 class="font-semibold text-sm border-b pb-2">{{ formatCategoryName(category) }}</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div v-if="awayTrends?.trends[category]?.length" class="space-y-1">
                                            <div class="text-xs text-muted-foreground font-medium mb-2">{{ awayTeam?.abbreviation }}</div>
                                            <ul class="space-y-1 text-sm">
                                                <li v-for="(trend, idx) in awayTrends.trends[category]" :key="idx" class="flex items-start gap-2">
                                                    <span class="text-primary mt-1">-</span>
                                                    <span>{{ trend }}</span>
                                                </li>
                                            </ul>
                                        </div>
                                        <div v-else class="text-sm text-muted-foreground italic">
                                            No {{ formatCategoryName(category).toLowerCase() }} trends for {{ awayTeam?.abbreviation }}
                                        </div>

                                        <div v-if="homeTrends?.trends[category]?.length" class="space-y-1">
                                            <div class="text-xs text-muted-foreground font-medium mb-2">{{ homeTeam?.abbreviation }}</div>
                                            <ul class="space-y-1 text-sm">
                                                <li v-for="(trend, idx) in homeTrends.trends[category]" :key="idx" class="flex items-start gap-2">
                                                    <span class="text-primary mt-1">-</span>
                                                    <span>{{ trend }}</span>
                                                </li>
                                            </ul>
                                        </div>
                                        <div v-else class="text-sm text-muted-foreground italic">
                                            No {{ formatCategoryName(category).toLowerCase() }} trends for {{ homeTeam?.abbreviation }}
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </CardContent>
                </Card>
            </template>
        </div>
    </AppLayout>
</template>
