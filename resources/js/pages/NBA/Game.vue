<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ref, onMounted, computed } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { type BreadcrumbItem, type Game, type Team, type Prediction, type TeamMetric } from '@/types'
import NBATeamController from '@/actions/App/Http/Controllers/NBA/TeamController'

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
    { title: 'NBA', href: '/nba-predictions' },
    { title: 'Games', href: '/nba-predictions' },
    { title: `Game ${props.game.id}`, href: `/nba/games/${props.game.id}` }
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

const formatCategoryName = (key: string): string => {
    return key
        .replace(/_/g, ' ')
        .replace(/\b\w/g, l => l.toUpperCase())
}

const isLockedCategory = (category: string): boolean => {
    const homeLockedKeys = homeTrends.value?.locked_trends ? Object.keys(homeTrends.value.locked_trends) : []
    const awayLockedKeys = awayTrends.value?.locked_trends ? Object.keys(awayTrends.value.locked_trends) : []
    return homeLockedKeys.includes(category) || awayLockedKeys.includes(category)
}

const getRequiredTier = (category: string): string => {
    return homeTrends.value?.locked_trends?.[category] || awayTrends.value?.locked_trends?.[category] || 'pro'
}

const formatTierName = (tier: string): string => {
    return tier.charAt(0).toUpperCase() + tier.slice(1)
}

const allTrendCategories = computed(() => {
    const categories = new Set<string>()

    if (homeTrends.value?.trends) {
        Object.keys(homeTrends.value.trends).forEach(key => categories.add(key))
    }
    if (awayTrends.value?.trends) {
        Object.keys(awayTrends.value.trends).forEach(key => categories.add(key))
    }
    if (homeTrends.value?.locked_trends) {
        Object.keys(homeTrends.value.locked_trends).forEach(key => categories.add(key))
    }
    if (awayTrends.value?.locked_trends) {
        Object.keys(awayTrends.value.locked_trends).forEach(key => categories.add(key))
    }

    return Array.from(categories).sort()
})

const formatNumber = (value: number | string | null, decimals = 1): string => {
    if (value === null || value === undefined) return '-'
    const num = typeof value === 'string' ? parseFloat(value) : value
    if (isNaN(num)) return '-'
    return num.toFixed(decimals)
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

const calculatePercentage = (made: number, attempted: number): string => {
    if (!attempted || attempted === 0) return '0.0'
    return ((made / attempted) * 100).toFixed(1)
}

const parseLinescores = (linescoresStr: string | null): any[] => {
    if (!linescoresStr) return []
    try {
        return JSON.parse(linescoresStr)
    } catch {
        return []
    }
}

const homeLinescores = computed(() => parseLinescores(props.game.home_linescores))
const awayLinescores = computed(() => parseLinescores(props.game.away_linescores))

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

onMounted(async () => {
    try {
        loading.value = true
        error.value = null

        const [gameRes, predictionRes, teamStatsRes, playerStatsRes] = await Promise.all([
            fetch(`/api/v1/nba/games/${props.game.id}`),
            fetch(`/api/v1/nba/games/${props.game.id}/prediction`),
            fetch(`/api/v1/nba/games/${props.game.id}/team-stats`),
            fetch(`/api/v1/nba/games/${props.game.id}/player-stats`)
        ])

        if (gameRes.ok) {
            const gameData = await gameRes.json()
            const fullGame = gameData.data
            homeTeam.value = fullGame.home_team
            awayTeam.value = fullGame.away_team

            if (fullGame.home_team?.id) {
                const [homeMetricsRes, homeGamesRes] = await Promise.all([
                    fetch(`/api/v1/nba/teams/${fullGame.home_team.id}/metrics`),
                    fetch(`/api/v1/nba/teams/${fullGame.home_team.id}/games`)
                ])

                if (homeMetricsRes.ok) {
                    const data = await homeMetricsRes.json()
                    homeMetrics.value = data.data?.[0] || null
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
                    fetch(`/api/v1/nba/teams/${fullGame.away_team.id}/metrics`),
                    fetch(`/api/v1/nba/teams/${fullGame.away_team.id}/games`)
                ])

                if (awayMetricsRes.ok) {
                    const data = await awayMetricsRes.json()
                    awayMetrics.value = data.data?.[0] || null
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
            homeTeamStats.value = stats.find((s: any) => s.team_type === 'home') || null
            awayTeamStats.value = stats.find((s: any) => s.team_type === 'away') || null
        }

        if (playerStatsRes.ok) {
            const playerStatsData = await playerStatsRes.json()
            topPerformers.value = (playerStatsData.data || []).slice(0, 10)
        }

        // Fetch trends for both teams
        if (homeTeam.value?.id || awayTeam.value?.id) {
            trendsLoading.value = true
            const beforeDate = props.game.game_date || undefined
            const trendsPromises = []

            if (homeTeam.value?.id) {
                trendsPromises.push(
                    fetch(`/api/v1/nba/teams/${homeTeam.value.id}/trends?before_date=${beforeDate || ''}`)
                        .then(res => res.ok ? res.json() : null)
                        .then(data => { homeTrends.value = data })
                        .catch(() => { homeTrends.value = null })
                )
            }

            if (awayTeam.value?.id) {
                trendsPromises.push(
                    fetch(`/api/v1/nba/teams/${awayTeam.value.id}/trends?before_date=${beforeDate || ''}`)
                        .then(res => res.ok ? res.json() : null)
                        .then(data => { awayTrends.value = data })
                        .catch(() => { awayTrends.value = null })
                )
            }

            await Promise.all(trendsPromises)
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
                <Card>
                    <CardContent class="pt-6">
                        <div class="flex items-center justify-between gap-6">
                            <Link
                                v-if="awayTeam"
                                :href="NBATeamController(awayTeam.id)"
                                class="flex-1 flex items-center justify-end gap-3 hover:opacity-75 transition-opacity"
                            >
                                <div class="text-right">
                                    <div class="text-2xl font-bold">{{ awayTeam.display_name || awayTeam.name }}</div>
                                    <div class="text-sm text-muted-foreground">Away</div>
                                    <div v-if="awayRecentGames.length > 0" class="text-xs text-muted-foreground mt-1">
                                        {{ getRecentForm(awayRecentGames, game.away_team_id) }}
                                    </div>
                                </div>
                                <img
                                    v-if="awayTeam.logo"
                                    :src="awayTeam.logo"
                                    :alt="awayTeam.name"
                                    class="w-16 h-16 object-contain"
                                />
                            </Link>

                            <div class="text-center min-w-[120px]">
                                <div v-if="game.status === 'STATUS_FINAL'" class="text-4xl font-bold">
                                    {{ game.away_score }} - {{ game.home_score }}
                                </div>
                                <div v-else class="text-2xl font-bold text-muted-foreground">
                                    vs
                                </div>
                                <Badge class="mt-2">{{ gameStatus }}</Badge>
                                <div class="text-sm text-muted-foreground mt-2">
                                    {{ formatDate(game.game_date) }}
                                </div>
                            </div>

                            <Link
                                v-if="homeTeam"
                                :href="NBATeamController(homeTeam.id)"
                                class="flex-1 flex items-center justify-start gap-3 hover:opacity-75 transition-opacity"
                            >
                                <img
                                    v-if="homeTeam.logo"
                                    :src="homeTeam.logo"
                                    :alt="homeTeam.name"
                                    class="w-16 h-16 object-contain"
                                />
                                <div class="text-left">
                                    <div class="text-2xl font-bold">{{ homeTeam.display_name || homeTeam.name }}</div>
                                    <div class="text-sm text-muted-foreground">Home</div>
                                    <div v-if="homeRecentGames.length > 0" class="text-xs text-muted-foreground mt-1">
                                        {{ getRecentForm(homeRecentGames, game.home_team_id) }}
                                    </div>
                                </div>
                            </Link>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="game.venue_name">
                    <CardHeader>
                        <CardTitle>Game Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                            <div>
                                <div class="text-muted-foreground">Venue</div>
                                <div class="font-medium">{{ game.venue_name }}</div>
                                <div v-if="game.venue_city" class="text-xs text-muted-foreground">
                                    {{ game.venue_city }}<span v-if="game.venue_state">, {{ game.venue_state }}</span>
                                </div>
                            </div>
                            <div v-if="broadcastNetworks.length > 0">
                                <div class="text-muted-foreground">Broadcast</div>
                                <div class="font-medium">{{ broadcastNetworks.join(', ') }}</div>
                            </div>
                            <div>
                                <div class="text-muted-foreground">Season</div>
                                <div class="font-medium">{{ game.season_type }} - Week {{ game.week }}</div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="homeLinescores.length > 0 && awayLinescores.length > 0 && game.status === 'STATUS_FINAL'">
                    <CardHeader>
                        <CardTitle>Quarter by Quarter</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b">
                                        <th class="text-left p-2">Team</th>
                                        <th class="text-center p-2" v-for="quarter in homeLinescores" :key="quarter.period">
                                            Q{{ quarter.period }}
                                        </th>
                                        <th class="text-center p-2 font-bold">Final</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="border-b">
                                        <td class="p-2 font-medium">{{ awayTeam?.abbreviation }}</td>
                                        <td class="text-center p-2" v-for="quarter in awayLinescores" :key="quarter.period">
                                            {{ quarter.value }}
                                        </td>
                                        <td class="text-center p-2 font-bold">{{ game.away_score }}</td>
                                    </tr>
                                    <tr>
                                        <td class="p-2 font-medium">{{ homeTeam?.abbreviation }}</td>
                                        <td class="text-center p-2" v-for="quarter in homeLinescores" :key="quarter.period">
                                            {{ quarter.value }}
                                        </td>
                                        <td class="text-center p-2 font-bold">{{ game.home_score }}</td>
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
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div class="text-center p-4 bg-muted/50 rounded-lg">
                                <div class="text-sm text-muted-foreground">Win Probability</div>
                                <div class="text-2xl font-bold">
                                    {{ awayTeam?.abbreviation }}: {{ formatNumber(prediction.away_win_probability * 100, 0) }}%
                                </div>
                                <div class="text-2xl font-bold mt-1">
                                    {{ homeTeam?.abbreviation }}: {{ formatNumber(prediction.home_win_probability * 100, 0) }}%
                                </div>
                            </div>
                            <div class="text-center p-4 bg-muted/50 rounded-lg">
                                <div class="text-sm text-muted-foreground">Predicted Spread</div>
                                <div class="text-2xl font-bold">
                                    {{ prediction.predicted_spread > 0 ? '+' : '' }}{{ formatNumber(prediction.predicted_spread) }}
                                </div>
                            </div>
                            <div class="text-center p-4 bg-muted/50 rounded-lg">
                                <div class="text-sm text-muted-foreground">Predicted Total</div>
                                <div class="text-2xl font-bold">
                                    {{ formatNumber(prediction.predicted_total) }}
                                </div>
                            </div>
                            <div class="text-center p-4 bg-muted/50 rounded-lg">
                                <div class="text-sm text-muted-foreground">Confidence</div>
                                <div class="text-2xl font-bold capitalize">
                                    {{ prediction.confidence_level }}
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="homeTeamStats && awayTeamStats && game.status === 'STATUS_FINAL'">
                    <CardHeader>
                        <CardTitle>Box Score</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-4">
                            <div class="grid grid-cols-7 gap-2 text-sm font-medium border-b pb-2">
                                <div class="col-span-2 text-right">{{ awayTeam?.abbreviation }}</div>
                                <div class="col-span-3 text-center">Stat</div>
                                <div class="col-span-2 text-left">{{ homeTeam?.abbreviation }}</div>
                            </div>

                            <div class="grid grid-cols-7 gap-2 items-center text-sm">
                                <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.field_goals_made, awayTeamStats.field_goals_made) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ awayTeamStats.field_goals_made }}-{{ awayTeamStats.field_goals_attempted }}
                                </div>
                                <div class="col-span-3 text-center text-muted-foreground">
                                    FG ({{ calculatePercentage(awayTeamStats.field_goals_made, awayTeamStats.field_goals_attempted) }}% - {{ calculatePercentage(homeTeamStats.field_goals_made, homeTeamStats.field_goals_attempted) }}%)
                                </div>
                                <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.field_goals_made, awayTeamStats.field_goals_made) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ homeTeamStats.field_goals_made }}-{{ homeTeamStats.field_goals_attempted }}
                                </div>
                            </div>

                            <div class="grid grid-cols-7 gap-2 items-center text-sm">
                                <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.three_point_made, awayTeamStats.three_point_made) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ awayTeamStats.three_point_made }}-{{ awayTeamStats.three_point_attempted }}
                                </div>
                                <div class="col-span-3 text-center text-muted-foreground">
                                    3PT ({{ calculatePercentage(awayTeamStats.three_point_made, awayTeamStats.three_point_attempted) }}% - {{ calculatePercentage(homeTeamStats.three_point_made, homeTeamStats.three_point_attempted) }}%)
                                </div>
                                <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.three_point_made, awayTeamStats.three_point_made) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ homeTeamStats.three_point_made }}-{{ homeTeamStats.three_point_attempted }}
                                </div>
                            </div>

                            <div class="grid grid-cols-7 gap-2 items-center text-sm">
                                <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.free_throws_made, awayTeamStats.free_throws_made) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ awayTeamStats.free_throws_made }}-{{ awayTeamStats.free_throws_attempted }}
                                </div>
                                <div class="col-span-3 text-center text-muted-foreground">
                                    FT ({{ calculatePercentage(awayTeamStats.free_throws_made, awayTeamStats.free_throws_attempted) }}% - {{ calculatePercentage(homeTeamStats.free_throws_made, homeTeamStats.free_throws_attempted) }}%)
                                </div>
                                <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.free_throws_made, awayTeamStats.free_throws_made) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ homeTeamStats.free_throws_made }}-{{ homeTeamStats.free_throws_attempted }}
                                </div>
                            </div>

                            <div class="grid grid-cols-7 gap-2 items-center text-sm">
                                <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.rebounds, awayTeamStats.rebounds) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ awayTeamStats.rebounds }}
                                </div>
                                <div class="col-span-3 text-center text-muted-foreground">
                                    Rebounds
                                </div>
                                <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.rebounds, awayTeamStats.rebounds) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ homeTeamStats.rebounds }}
                                </div>
                            </div>

                            <div class="grid grid-cols-7 gap-2 items-center text-sm">
                                <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.assists, awayTeamStats.assists) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ awayTeamStats.assists }}
                                </div>
                                <div class="col-span-3 text-center text-muted-foreground">
                                    Assists
                                </div>
                                <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.assists, awayTeamStats.assists) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ homeTeamStats.assists }}
                                </div>
                            </div>

                            <div class="grid grid-cols-7 gap-2 items-center text-sm">
                                <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.turnovers, awayTeamStats.turnovers, true) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ awayTeamStats.turnovers }}
                                </div>
                                <div class="col-span-3 text-center text-muted-foreground">
                                    Turnovers
                                </div>
                                <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.turnovers, awayTeamStats.turnovers, true) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ homeTeamStats.turnovers }}
                                </div>
                            </div>

                            <div class="grid grid-cols-7 gap-2 items-center text-sm">
                                <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.steals, awayTeamStats.steals) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ awayTeamStats.steals }}
                                </div>
                                <div class="col-span-3 text-center text-muted-foreground">
                                    Steals
                                </div>
                                <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.steals, awayTeamStats.steals) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ homeTeamStats.steals }}
                                </div>
                            </div>

                            <div class="grid grid-cols-7 gap-2 items-center text-sm">
                                <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.blocks, awayTeamStats.blocks) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ awayTeamStats.blocks }}
                                </div>
                                <div class="col-span-3 text-center text-muted-foreground">
                                    Blocks
                                </div>
                                <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.blocks, awayTeamStats.blocks) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ homeTeamStats.blocks }}
                                </div>
                            </div>

                            <div class="grid grid-cols-7 gap-2 items-center text-sm">
                                <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.points_in_paint, awayTeamStats.points_in_paint) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ awayTeamStats.points_in_paint || '-' }}
                                </div>
                                <div class="col-span-3 text-center text-muted-foreground">
                                    Points in Paint
                                </div>
                                <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.points_in_paint, awayTeamStats.points_in_paint) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ homeTeamStats.points_in_paint || '-' }}
                                </div>
                            </div>

                            <div class="grid grid-cols-7 gap-2 items-center text-sm">
                                <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.fast_break_points, awayTeamStats.fast_break_points) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ awayTeamStats.fast_break_points || '-' }}
                                </div>
                                <div class="col-span-3 text-center text-muted-foreground">
                                    Fast Break Points
                                </div>
                                <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.fast_break_points, awayTeamStats.fast_break_points) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                    {{ homeTeamStats.fast_break_points || '-' }}
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="topPerformers.length > 0 && game.status === 'STATUS_FINAL'">
                    <CardHeader>
                        <CardTitle>Top Performers</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-3">
                            <div v-for="player in topPerformers" :key="player.id" class="flex items-center justify-between p-3 rounded-lg bg-muted/50">
                                <div class="flex-1">
                                    <div class="font-medium">{{ player.player?.name || `Player #${player.player_id}` }}</div>
                                    <div class="text-sm text-muted-foreground">
                                        {{ player.team_id === game.home_team_id ? homeTeam?.abbreviation : awayTeam?.abbreviation }}
                                    </div>
                                </div>
                                <div class="flex gap-4 text-sm">
                                    <div class="text-center">
                                        <div class="font-bold">{{ player.points }}</div>
                                        <div class="text-xs text-muted-foreground">PTS</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="font-bold">{{ player.rebounds_total }}</div>
                                        <div class="text-xs text-muted-foreground">REB</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="font-bold">{{ player.assists }}</div>
                                        <div class="text-xs text-muted-foreground">AST</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="font-bold">{{ player.field_goals_made }}-{{ player.field_goals_attempted }}</div>
                                        <div class="text-xs text-muted-foreground">FG</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="homeMetrics && awayMetrics">
                    <CardHeader>
                        <CardTitle>Team Stats Comparison</CardTitle>
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

                <Card>
                    <CardHeader>
                        <CardTitle>Team Trends</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div v-if="trendsLoading" class="space-y-4">
                            <Skeleton class="h-24 w-full" />
                            <Skeleton class="h-24 w-full" />
                        </div>

                        <div v-else-if="allTrendCategories.length > 0" class="space-y-6">
                            <div v-for="category in allTrendCategories" :key="category" class="border-b pb-4 last:border-b-0">
                                <h4 class="font-medium mb-3">{{ formatCategoryName(category) }}</h4>

                                <div v-if="isLockedCategory(category)" class="text-center py-4 bg-muted/50 rounded-lg">
                                    <div class="text-sm text-muted-foreground">
                                        Upgrade to {{ formatTierName(getRequiredTier(category)) }} to unlock this trend
                                    </div>
                                </div>

                                <div v-else class="grid grid-cols-2 gap-4">
                                    <div>
                                        <div class="text-sm font-medium text-muted-foreground mb-2">{{ awayTeam?.abbreviation }}</div>
                                        <ul v-if="awayTrends?.trends?.[category]?.length" class="space-y-1 text-sm">
                                            <li v-for="(trend, idx) in awayTrends.trends[category]" :key="idx" class="flex items-start gap-2">
                                                <span class="text-muted-foreground">•</span>
                                                <span>{{ trend }}</span>
                                            </li>
                                        </ul>
                                        <p v-else class="text-sm text-muted-foreground">No trends available</p>
                                    </div>
                                    <div>
                                        <div class="text-sm font-medium text-muted-foreground mb-2">{{ homeTeam?.abbreviation }}</div>
                                        <ul v-if="homeTrends?.trends?.[category]?.length" class="space-y-1 text-sm">
                                            <li v-for="(trend, idx) in homeTrends.trends[category]" :key="idx" class="flex items-start gap-2">
                                                <span class="text-muted-foreground">•</span>
                                                <span>{{ trend }}</span>
                                            </li>
                                        </ul>
                                        <p v-else class="text-sm text-muted-foreground">No trends available</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-else class="text-center py-8 text-muted-foreground">
                            No trends available for this matchup
                        </div>
                    </CardContent>
                </Card>
            </template>
        </div>
    </AppLayout>
</template>
