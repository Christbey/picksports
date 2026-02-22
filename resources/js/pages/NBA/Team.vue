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
const eloData = ref<any>(null)
const trendsData = ref<any>(null)
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
const currentElo = computed(() => {
    if (!eloData.value || eloData.value.length === 0) return null
    return eloData.value[0]
})

const eloChange = computed(() => {
    if (!eloData.value || eloData.value.length < 2) return 0
    const latest = eloData.value[0]?.elo_rating || 0
    const previous = eloData.value[1]?.elo_rating || 0
    return latest - previous
})

const recentForm = computed(() => {
    return recentGames.value.slice(0, 5).map(g => getGameResult(g)).join('-')
})

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

        const [metricsRes, seasonStatsRes, gamesRes, eloRes, trendsRes] = await Promise.all([
            fetch(`/api/v1/nba/teams/${props.team.id}/metrics`),
            fetch(`/api/v1/nba/teams/${props.team.id}/stats/season-averages`),
            fetch(`/api/v1/nba/teams/${props.team.id}/games`),
            fetch(`/api/v1/nba/teams/${props.team.id}/elo-ratings?per_page=20`),
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

        if (eloRes.ok) {
            const eloResData = await eloRes.json()
            eloData.value = eloResData.data || []
        }

        if (trendsRes.ok) {
            const trendsResData = await trendsRes.json()
            trendsData.value = trendsResData.trends || null
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
                <!-- ELO Rating and Recent Form Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- ELO Rating Card -->
                    <Card v-if="currentElo">
                        <CardHeader>
                            <CardTitle>ELO Rating</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-4xl font-bold">{{ formatNumber(currentElo.elo_rating, 0) }}</div>
                                    <div class="text-sm text-muted-foreground mt-1">Current Rating</div>
                                </div>
                                <div class="text-right">
                                    <div
                                        :class="[
                                            'text-2xl font-semibold',
                                            eloChange > 0 ? 'text-green-600 dark:text-green-400' : eloChange < 0 ? 'text-red-600 dark:text-red-400' : 'text-muted-foreground'
                                        ]"
                                    >
                                        {{ eloChange > 0 ? '↗' : eloChange < 0 ? '↘' : '→' }}
                                        {{ eloChange > 0 ? '+' : '' }}{{ formatNumber(eloChange, 1) }}
                                    </div>
                                    <div class="text-xs text-muted-foreground mt-1">vs Last Game</div>
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
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">RPG</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.rebounds_per_game) }}
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">APG</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.assists_per_game) }}
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">FG%</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.field_goal_percentage) }}%
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">3P%</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.three_point_percentage) }}%
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">FT%</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.free_throw_percentage) }}%
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">SPG</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.steals_per_game) }}
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">BPG</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.blocks_per_game) }}
                                        </div>
                                    </div>
                                    <div class="text-center p-4 bg-muted/50 rounded-lg">
                                        <div class="text-sm text-muted-foreground">TPG</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(seasonStats.turnovers_per_game) }}
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
                        <Card>
                            <CardHeader>
                                <CardTitle>Team Trends (Last 20 Games)</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                    <!-- Home/Away Record -->
                                    <div v-if="trendsData.situational" class="p-4 border rounded-lg">
                                        <div class="text-sm text-muted-foreground mb-2">Home Record</div>
                                        <div class="text-2xl font-bold">
                                            {{ trendsData.situational?.home_games?.wins || 0 }}-{{ trendsData.situational?.home_games?.losses || 0 }}
                                        </div>
                                        <div class="text-xs text-muted-foreground mt-1">
                                            {{ trendsData.situational?.home_games?.games || 0 }} games
                                        </div>
                                    </div>

                                    <div v-if="trendsData.situational" class="p-4 border rounded-lg">
                                        <div class="text-sm text-muted-foreground mb-2">Away Record</div>
                                        <div class="text-2xl font-bold">
                                            {{ trendsData.situational?.away_games?.wins || 0 }}-{{ trendsData.situational?.away_games?.losses || 0 }}
                                        </div>
                                        <div class="text-xs text-muted-foreground mt-1">
                                            {{ trendsData.situational?.away_games?.games || 0 }} games
                                        </div>
                                    </div>

                                    <!-- Scoring Trends -->
                                    <div v-if="trendsData.scoring" class="p-4 border rounded-lg">
                                        <div class="text-sm text-muted-foreground mb-2">Avg Points Scored</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(trendsData.scoring?.average_points || 0) }}
                                        </div>
                                        <div class="text-xs text-muted-foreground mt-1">
                                            Per game
                                        </div>
                                    </div>

                                    <!-- Margin Trends -->
                                    <div v-if="trendsData.margin" class="p-4 border rounded-lg">
                                        <div class="text-sm text-muted-foreground mb-2">Avg Margin</div>
                                        <div
                                            class="text-2xl font-bold"
                                            :class="getRatingClass(trendsData.margin?.average_margin || 0)"
                                        >
                                            {{ trendsData.margin?.average_margin > 0 ? '+' : '' }}{{ formatNumber(trendsData.margin?.average_margin || 0) }}
                                        </div>
                                        <div class="text-xs text-muted-foreground mt-1">
                                            Points per game
                                        </div>
                                    </div>

                                    <!-- Quarter Performance -->
                                    <div v-if="trendsData.quarter" class="p-4 border rounded-lg">
                                        <div class="text-sm text-muted-foreground mb-2">Strongest Quarter</div>
                                        <div class="text-2xl font-bold">
                                            {{ trendsData.quarter?.best_quarter || '-' }}
                                        </div>
                                        <div class="text-xs text-muted-foreground mt-1">
                                            Best performance
                                        </div>
                                    </div>

                                    <!-- Streak -->
                                    <div v-if="trendsData.streak" class="p-4 border rounded-lg">
                                        <div class="text-sm text-muted-foreground mb-2">Current Streak</div>
                                        <div
                                            class="text-2xl font-bold"
                                            :class="trendsData.streak?.current_streak_type === 'W' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                        >
                                            {{ trendsData.streak?.current_streak_type || '-' }}{{ trendsData.streak?.current_streak || 0 }}
                                        </div>
                                        <div class="text-xs text-muted-foreground mt-1">
                                            {{ trendsData.streak?.current_streak_type === 'W' ? 'Win' : 'Loss' }} streak
                                        </div>
                                    </div>

                                    <!-- Clutch Performance -->
                                    <div v-if="trendsData.clutch" class="p-4 border rounded-lg">
                                        <div class="text-sm text-muted-foreground mb-2">Close Games (±5)</div>
                                        <div class="text-2xl font-bold">
                                            {{ trendsData.clutch?.close_wins || 0 }}-{{ trendsData.clutch?.close_losses || 0 }}
                                        </div>
                                        <div class="text-xs text-muted-foreground mt-1">
                                            Clutch record
                                        </div>
                                    </div>

                                    <!-- Offensive Efficiency Trend -->
                                    <div v-if="trendsData.offensive_efficiency" class="p-4 border rounded-lg">
                                        <div class="text-sm text-muted-foreground mb-2">Offensive Efficiency</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(trendsData.offensive_efficiency?.current || 0) }}
                                        </div>
                                        <div class="text-xs text-muted-foreground mt-1">
                                            Recent trend
                                        </div>
                                    </div>

                                    <!-- Defensive Performance -->
                                    <div v-if="trendsData.defensive_performance" class="p-4 border rounded-lg">
                                        <div class="text-sm text-muted-foreground mb-2">Defensive Rating</div>
                                        <div class="text-2xl font-bold">
                                            {{ formatNumber(trendsData.defensive_performance?.current || 0) }}
                                        </div>
                                        <div class="text-xs text-muted-foreground mt-1">
                                            Recent trend
                                        </div>
                                    </div>
                                </div>

                                <!-- No trends message -->
                                <div v-if="!trendsData || Object.keys(trendsData).length === 0" class="text-center py-8 text-muted-foreground">
                                    <p>Not enough games played to calculate trends (minimum 20 games required)</p>
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
