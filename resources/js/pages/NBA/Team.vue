<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ref, onMounted, computed } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription } from '@/components/ui/alert'
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

const teamMetrics = ref<TeamMetric | null>(null)
const seasonStats = ref<any>(null)
const recentGames = ref<Game[]>([])
const upcomingGames = ref<Game[]>([])
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

onMounted(async () => {
    try {
        loading.value = true
        error.value = null

        const [metricsRes, seasonStatsRes, gamesRes] = await Promise.all([
            fetch(`/api/v1/nba/teams/${props.team.id}/metrics`),
            fetch(`/api/v1/nba/teams/${props.team.id}/stats/season-averages`),
            fetch(`/api/v1/nba/teams/${props.team.id}/games`)
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

            const now = new Date()
            recentGames.value = games
                .filter((g: Game) => g.status === 'STATUS_FINAL')
                .slice(0, 5)

            upcomingGames.value = games
                .filter((g: Game) => g.status === 'STATUS_SCHEDULED' || g.status === 'STATUS_IN_PROGRESS')
                .slice(0, 5)
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
            </template>
        </div>
    </AppLayout>
</template>
