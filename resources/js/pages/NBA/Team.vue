<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ref, onMounted, computed } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { type BreadcrumbItem, type Team, type TeamMetric, type Game } from '@/types'
import NBAGameController from '@/actions/App/Http/Controllers/NBA/GameController'

const props = defineProps<{
    team: Team
}>()

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NBA', href: '/nba-predictions' },
    { title: 'Team Metrics', href: '/nba-team-metrics' },
    { title: props.team.name, href: `/nba/teams/${props.team.id}` }
]

// Data refs
const teamMetrics = ref<TeamMetric | null>(null)
const seasonStats = ref<any>(null)
const recentGames = ref<Game[]>([])
const upcomingGames = ref<Game[]>([])
const powerRanking = ref<{ rank: number; total_teams: number } | null>(null)
const statRankings = ref<Record<string, number>>({})
const trendsData = ref<Record<string, string[]> | null>(null)
const lockedTrends = ref<Record<string, string> | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

const formatNumber = (value: number | string | null, decimals = 1): string => {
    if (value === null || value === undefined) return '-'
    const num = typeof value === 'string' ? parseFloat(value) : value
    if (isNaN(num)) return '-'
    return num.toFixed(decimals)
}

const getRatingClass = (value: number | null): string => {
    if (value === null) return ''
    if (value > 5) return 'text-green-600 dark:text-green-400 font-semibold'
    if (value > 0) return 'text-green-600 dark:text-green-400'
    if (value < -5) return 'text-red-600 dark:text-red-400 font-semibold'
    if (value < 0) return 'text-red-600 dark:text-red-400'
    return ''
}

const formatDate = (dateString: string | null): string => {
    if (!dateString) return '-'
    return new Date(dateString).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

const getOpponent = (game: Game, isHome: boolean): Team | undefined => {
    return isHome ? game.away_team : game.home_team
}

const getGameResult = (game: Game): string | null => {
    if (game.status !== 'STATUS_FINAL' || !game.home_score || !game.away_score) return null
    const isHome = game.home_team_id === props.team.id
    const teamScore = isHome ? game.home_score : game.away_score
    const oppScore = isHome ? game.away_score : game.home_score
    return teamScore > oppScore ? 'W' : 'L'
}

// Computed properties
const recentForm = computed(() => {
    return recentGames.value.slice(0, 5).map(g => getGameResult(g)).join('-')
})

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
    }
    return labels[key] || key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

const recentRecord = computed(() => {
    const last5 = recentGames.value.slice(0, 5)
    const wins = last5.filter(g => getGameResult(g) === 'W').length
    const losses = last5.length - wins
    return { wins, losses, games: last5.length }
})

onMounted(async () => {
    try {
        loading.value = true
        error.value = null

        const [metricsRes, seasonStatsRes, gamesRes, allMetricsRes, allStatsRes, trendsRes] = await Promise.all([
            fetch(`/api/v1/nba/teams/${props.team.id}/metrics`),
            fetch(`/api/v1/nba/teams/${props.team.id}/stats/season-averages`),
            fetch(`/api/v1/nba/teams/${props.team.id}/games`),
            fetch(`/api/v1/nba/team-metrics`),
            fetch(`/api/v1/nba/team-stats/season-averages`),
            fetch(`/api/v1/nba/teams/${props.team.id}/trends?games=20`)
        ])

        if (metricsRes.ok) {
            const metricsData = await metricsRes.json()
            teamMetrics.value = metricsData.data?.[0] || null
        }

        if (seasonStatsRes.ok) {
            const seasonStatsData = await seasonStatsRes.json()
            seasonStats.value = seasonStatsData.data || null
        }

        if (gamesRes.ok) {
            const gamesData = await gamesRes.json()
            const games = gamesData.data || []

            recentGames.value = games
                .filter((g: Game) => g.status === 'STATUS_FINAL')
                .slice(0, 10)

            upcomingGames.value = games
                .filter((g: Game) => g.status === 'STATUS_SCHEDULED' || g.status === 'STATUS_IN_PROGRESS')
                .slice(0, 5)
        }

        if (allMetricsRes.ok) {
            const allMetricsData = await allMetricsRes.json()
            const allMetrics = allMetricsData.data || []
            const idx = allMetrics.findIndex((m: any) => m.team_id === props.team.id)
            if (idx !== -1) {
                powerRanking.value = { rank: idx + 1, total_teams: allMetrics.length }
            }
        }

        if (allStatsRes.ok) {
            const allStatsData = await allStatsRes.json()
            const allStats = allStatsData.data || []
            const rankStat = (key: string, descending = true) => {
                const sorted = [...allStats].sort((a: any, b: any) =>
                    descending ? b[key] - a[key] : a[key] - b[key]
                )
                const idx = sorted.findIndex((s: any) => s.team_id === props.team.id)
                return idx !== -1 ? idx + 1 : 0
            }
            statRankings.value = {
                points_per_game: rankStat('points_per_game'),
                rebounds_per_game: rankStat('rebounds_per_game'),
                assists_per_game: rankStat('assists_per_game'),
                field_goal_percentage: rankStat('field_goal_percentage'),
                three_point_percentage: rankStat('three_point_percentage'),
                free_throw_percentage: rankStat('free_throw_percentage'),
                steals_per_game: rankStat('steals_per_game'),
                blocks_per_game: rankStat('blocks_per_game'),
                turnovers_per_game: rankStat('turnovers_per_game', false),
            }
        }

        if (trendsRes.ok) {
            const trendsResData = await trendsRes.json()
            trendsData.value = trendsResData.trends || null
            lockedTrends.value = trendsResData.locked_trends || null
        }
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'An error occurred'
    } finally {
        loading.value = false
    }
})
</script>

<template>
    <Head :title="`${team.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="flex items-start gap-4">
                <img
                    v-if="team.logo"
                    :src="team.logo"
                    :alt="team.name"
                    class="w-20 h-20 object-contain"
                />
                <div class="flex-1">
                    <h1 class="text-3xl font-bold">{{ team.display_name || team.name }}</h1>
                    <p class="text-muted-foreground">
                        {{ team.conference }} {{ team.division ? `• ${team.division}` : '' }}
                    </p>
                </div>
            </div>

            <Alert v-if="error" variant="destructive">
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>

            <div v-if="loading" class="space-y-4">
                <Skeleton class="h-32 w-full" />
                <Skeleton class="h-64 w-full" />
                <Skeleton class="h-64 w-full" />
            </div>

            <template v-else>
                <!-- Power Ranking and Recent Form Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Power Ranking Card -->
                    <Card v-if="powerRanking">
                        <CardHeader>
                            <CardTitle>Power Ranking</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-4xl font-bold">#{{ powerRanking.rank }}</div>
                                    <div class="text-sm text-muted-foreground mt-1">of {{ powerRanking.total_teams }} teams</div>
                                </div>
                                <div v-if="teamMetrics" class="text-right">
                                    <div
                                        class="text-2xl font-semibold"
                                        :class="getRatingClass(teamMetrics.net_rating)"
                                    >
                                        {{ teamMetrics.net_rating > 0 ? '+' : '' }}{{ formatNumber(teamMetrics.net_rating) }}
                                    </div>
                                    <div class="text-xs text-muted-foreground mt-1">Net Rating</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Recent Form Card -->
                    <Card v-if="recentRecord.games > 0">
                        <CardHeader>
                            <CardTitle>Recent Form</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-4xl font-bold">
                                        {{ recentRecord.wins }}-{{ recentRecord.losses }}
                                    </div>
                                    <div class="text-sm text-muted-foreground mt-1">Last {{ recentRecord.games }} Games</div>
                                </div>
                                <div class="text-right">
                                    <div class="flex gap-1 justify-end">
                                        <span
                                            v-for="(result, idx) in recentForm.split('-')"
                                            :key="idx"
                                            :class="[
                                                'inline-flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold',
                                                result === 'W' ? 'bg-green-600 text-white' : 'bg-red-600 text-white'
                                            ]"
                                        >
                                            {{ result }}
                                        </span>
                                    </div>
                                    <div class="text-xs text-muted-foreground mt-1">Recent Results</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- Tabbed Content -->
                <Tabs default-value="overview" class="w-full">
                    <TabsList class="w-full">
                        <TabsTrigger value="overview">Overview</TabsTrigger>
                        <TabsTrigger value="stats">Advanced Stats</TabsTrigger>
                        <TabsTrigger value="trends">Trends & Insights</TabsTrigger>
                        <TabsTrigger value="schedule">Schedule</TabsTrigger>
                    </TabsList>

                    <!-- Overview Tab -->
                    <TabsContent value="overview">
                        <div class="space-y-4">
                            <Card v-if="teamMetrics">
                    <CardHeader>
                        <CardTitle>Current Season Metrics</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                            <div class="text-center p-4 bg-muted/50 rounded-lg">
                                <div class="text-sm text-muted-foreground">ORtg</div>
                                <div class="text-2xl font-bold">
                                    {{ formatNumber(teamMetrics.offensive_rating) }}
                                </div>
                            </div>
                            <div class="text-center p-4 bg-muted/50 rounded-lg">
                                <div class="text-sm text-muted-foreground">DRtg</div>
                                <div class="text-2xl font-bold">
                                    {{ formatNumber(teamMetrics.defensive_rating) }}
                                </div>
                            </div>
                            <div class="text-center p-4 bg-muted/50 rounded-lg">
                                <div class="text-sm text-muted-foreground">Net Rating</div>
                                <div class="text-2xl font-bold" :class="getRatingClass(teamMetrics.net_rating)">
                                    {{ formatNumber(teamMetrics.net_rating) }}
                                </div>
                            </div>
                            <div class="text-center p-4 bg-muted/50 rounded-lg">
                                <div class="text-sm text-muted-foreground">Pace</div>
                                <div class="text-2xl font-bold">
                                    {{ formatNumber(teamMetrics.pace) }}
                                </div>
                            </div>
                            <div class="text-center p-4 bg-muted/50 rounded-lg">
                                <div class="text-sm text-muted-foreground">SOS</div>
                                <div class="text-2xl font-bold">
                                    {{ formatNumber(teamMetrics.strength_of_schedule, 3) }}
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                            <Card v-if="seasonStats">
                                <CardHeader>
                                    <CardTitle>Season Averages ({{ seasonStats.games_played }} games)</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                                            <div class="text-sm text-muted-foreground">PPG</div>
                                            <div class="text-2xl font-bold">
                                                {{ formatNumber(seasonStats.points_per_game) }}
                                                <span v-if="statRankings.points_per_game" class="text-xs font-normal text-muted-foreground">#{{ statRankings.points_per_game }}</span>
                                            </div>
                                        </div>
                                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                                            <div class="text-sm text-muted-foreground">RPG</div>
                                            <div class="text-2xl font-bold">
                                                {{ formatNumber(seasonStats.rebounds_per_game) }}
                                                <span v-if="statRankings.rebounds_per_game" class="text-xs font-normal text-muted-foreground">#{{ statRankings.rebounds_per_game }}</span>
                                            </div>
                                        </div>
                                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                                            <div class="text-sm text-muted-foreground">APG</div>
                                            <div class="text-2xl font-bold">
                                                {{ formatNumber(seasonStats.assists_per_game) }}
                                                <span v-if="statRankings.assists_per_game" class="text-xs font-normal text-muted-foreground">#{{ statRankings.assists_per_game }}</span>
                                            </div>
                                        </div>
                                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                                            <div class="text-sm text-muted-foreground">FG%</div>
                                            <div class="text-2xl font-bold">
                                                {{ formatNumber(seasonStats.field_goal_percentage) }}%
                                                <span v-if="statRankings.field_goal_percentage" class="text-xs font-normal text-muted-foreground">#{{ statRankings.field_goal_percentage }}</span>
                                            </div>
                                        </div>
                                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                                            <div class="text-sm text-muted-foreground">3P%</div>
                                            <div class="text-2xl font-bold">
                                                {{ formatNumber(seasonStats.three_point_percentage) }}%
                                                <span v-if="statRankings.three_point_percentage" class="text-xs font-normal text-muted-foreground">#{{ statRankings.three_point_percentage }}</span>
                                            </div>
                                        </div>
                                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                                            <div class="text-sm text-muted-foreground">FT%</div>
                                            <div class="text-2xl font-bold">
                                                {{ formatNumber(seasonStats.free_throw_percentage) }}%
                                                <span v-if="statRankings.free_throw_percentage" class="text-xs font-normal text-muted-foreground">#{{ statRankings.free_throw_percentage }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>

                            <!-- Recent Games Preview -->
                            <Card v-if="recentGames.length > 0">
                                <CardHeader>
                                    <CardTitle>Recent Games</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div class="space-y-2">
                                        <Link
                                            v-for="game in recentGames.slice(0, 5)"
                                            :key="game.id"
                                            :href="NBAGameController(game.id)"
                                            class="flex items-center justify-between p-3 rounded-lg hover:bg-muted/50 transition-colors"
                                        >
                                            <div class="flex items-center gap-3 flex-1">
                                                <span
                                                    class="font-bold text-sm w-6"
                                                    :class="getGameResult(game) === 'W' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                                >
                                                    {{ getGameResult(game) }}
                                                </span>
                                                <span class="text-sm text-muted-foreground">
                                                    {{ game.home_team_id === team.id ? 'vs' : '@' }}
                                                </span>
                                                <span class="font-medium">
                                                    {{ getOpponent(game, game.home_team_id === team.id)?.name }}
                                                </span>
                                            </div>
                                            <div class="flex items-center gap-4">
                                                <span class="text-sm font-medium">
                                                    {{ game.home_team_id === team.id ? game.home_score : game.away_score }} -
                                                    {{ game.home_team_id === team.id ? game.away_score : game.home_score }}
                                                </span>
                                                <span class="text-sm text-muted-foreground">
                                                    {{ formatDate(game.game_date) }}
                                                </span>
                                            </div>
                                        </Link>
                                    </div>
                                </CardContent>
                            </Card>

                            <div v-if="!teamMetrics && !seasonStats && recentGames.length === 0" class="text-center py-8 text-muted-foreground">
                                <p>No overview data available for this team yet.</p>
                            </div>
                        </div>
                    </TabsContent>

                    <!-- Advanced Stats Tab -->
                    <TabsContent value="stats">
                        <Card v-if="seasonStats">
                            <CardHeader>
                                <CardTitle>Season Averages ({{ seasonStats.games_played }} games)</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">PPG</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.points_per_game) }}
                                            <span v-if="statRankings.points_per_game" class="text-xs font-normal text-muted-foreground">#{{ statRankings.points_per_game }}</span>
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">RPG</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.rebounds_per_game) }}
                                            <span v-if="statRankings.rebounds_per_game" class="text-xs font-normal text-muted-foreground">#{{ statRankings.rebounds_per_game }}</span>
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">APG</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.assists_per_game) }}
                                            <span v-if="statRankings.assists_per_game" class="text-xs font-normal text-muted-foreground">#{{ statRankings.assists_per_game }}</span>
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">FG%</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.field_goal_percentage) }}%
                                            <span v-if="statRankings.field_goal_percentage" class="text-xs font-normal text-muted-foreground">#{{ statRankings.field_goal_percentage }}</span>
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">3P%</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.three_point_percentage) }}%
                                            <span v-if="statRankings.three_point_percentage" class="text-xs font-normal text-muted-foreground">#{{ statRankings.three_point_percentage }}</span>
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">FT%</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.free_throw_percentage) }}%
                                            <span v-if="statRankings.free_throw_percentage" class="text-xs font-normal text-muted-foreground">#{{ statRankings.free_throw_percentage }}</span>
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">SPG</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.steals_per_game) }}
                                            <span v-if="statRankings.steals_per_game" class="text-xs font-normal text-muted-foreground">#{{ statRankings.steals_per_game }}</span>
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">BPG</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.blocks_per_game) }}
                                            <span v-if="statRankings.blocks_per_game" class="text-xs font-normal text-muted-foreground">#{{ statRankings.blocks_per_game }}</span>
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">TPG</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.turnovers_per_game) }}
                                            <span v-if="statRankings.turnovers_per_game" class="text-xs font-normal text-muted-foreground">#{{ statRankings.turnovers_per_game }}</span>
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">ORPG</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.offensive_rebounds_per_game) }}
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">DRPG</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.defensive_rebounds_per_game) }}
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">Fast Break PPG</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.fast_break_points_per_game) }}
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">Paint PPG</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.points_in_paint_per_game) }}
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">2nd Chance PPG</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.second_chance_points_per_game) }}
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">Bench PPG</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.bench_points_per_game) }}
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <!-- Trends Tab -->
                    <TabsContent value="trends">
                        <div v-if="trendsData && Object.keys(trendsData).length > 0" class="space-y-4">
                            <Card v-for="(insights, key) in trendsData" :key="key">
                                <CardHeader>
                                    <CardTitle>{{ trendLabel(key) }}</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <ul class="space-y-2">
                                        <li
                                            v-for="(insight, idx) in insights"
                                            :key="idx"
                                            class="flex items-start gap-2 text-sm"
                                        >
                                            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-primary" />
                                            <span>{{ insight }}</span>
                                        </li>
                                    </ul>
                                </CardContent>
                            </Card>

                            <!-- Locked trends -->
                            <Card v-if="lockedTrends && Object.keys(lockedTrends).length > 0" class="opacity-60">
                                <CardHeader>
                                    <CardTitle>More Insights Available</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                                        <div
                                            v-for="(tier, key) in lockedTrends"
                                            :key="key"
                                            class="flex items-center justify-between p-3 border rounded-lg"
                                        >
                                            <span class="text-sm font-medium">{{ trendLabel(key) }}</span>
                                            <span class="text-xs text-muted-foreground capitalize">{{ tier }}</span>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        <Card v-else>
                            <CardContent class="py-8">
                                <div class="text-center text-muted-foreground">
                                    <p>Not enough games played to calculate trends.</p>
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <!-- Schedule Tab -->
                    <TabsContent value="schedule">
                        <div class="space-y-4">
                            <Card v-if="recentGames.length > 0">
                                <CardHeader>
                                    <CardTitle>Recent Games</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div class="space-y-2">
                                        <Link
                                            v-for="game in recentGames"
                                            :key="game.id"
                                            :href="NBAGameController(game.id)"
                                            class="flex items-center justify-between p-3 rounded-lg hover:bg-muted/50 transition-colors"
                                        >
                                            <div class="flex items-center gap-3 flex-1">
                                                <span
                                                    class="font-bold text-sm w-6"
                                                    :class="getGameResult(game) === 'W' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                                >
                                                    {{ getGameResult(game) }}
                                                </span>
                                                <span class="text-sm text-muted-foreground">
                                                    {{ game.home_team_id === team.id ? 'vs' : '@' }}
                                                </span>
                                                <span class="font-medium">
                                                    {{ getOpponent(game, game.home_team_id === team.id)?.name }}
                                                </span>
                                            </div>
                                            <div class="flex items-center gap-4">
                                                <span class="text-sm font-medium">
                                                    {{ game.home_team_id === team.id ? game.home_score : game.away_score }} -
                                                    {{ game.home_team_id === team.id ? game.away_score : game.home_score }}
                                                </span>
                                                <span class="text-sm text-muted-foreground">
                                                    {{ formatDate(game.game_date) }}
                                                </span>
                                            </div>
                                        </Link>
                                    </div>
                                </CardContent>
                            </Card>

                            <Card v-if="upcomingGames.length > 0">
                                <CardHeader>
                                    <CardTitle>Upcoming Games</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div class="space-y-2">
                                        <Link
                                            v-for="game in upcomingGames"
                                            :key="game.id"
                                            :href="NBAGameController(game.id)"
                                            class="flex items-center justify-between p-3 rounded-lg hover:bg-muted/50 transition-colors"
                                        >
                                            <div class="flex items-center gap-3 flex-1">
                                                <span class="text-sm text-muted-foreground">
                                                    {{ game.home_team_id === team.id ? 'vs' : '@' }}
                                                </span>
                                                <span class="font-medium">
                                                    {{ getOpponent(game, game.home_team_id === team.id)?.name }}
                                                </span>
                                            </div>
                                            <span class="text-sm text-muted-foreground">
                                                {{ formatDate(game.game_date) }}
                                            </span>
                                        </Link>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>
                </Tabs>
            </template>
        </div>
    </AppLayout>
</template>
