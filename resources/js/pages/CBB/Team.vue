<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ref, onMounted, computed } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { type BreadcrumbItem, type Team, type TeamMetric, type Game } from '@/types'
import CBBGameController from '@/actions/App/Http/Controllers/CBB/GameController'

const props = defineProps<{
    teamId: number
}>()

const team = ref<Team | null>(null)
const teamMetrics = ref<TeamMetric | null>(null)
const seasonStats = ref<any>(null)
const recentGames = ref<Game[]>([])
const upcomingGames = ref<Game[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'CBB', href: '/cbb-predictions' },
    { title: 'Team Metrics', href: '/cbb-team-metrics' },
    { title: team.value?.name || 'Team', href: `/cbb/teams/${props.teamId}` }
])

onMounted(async () => {
    try {
        loading.value = true

        // Fetch team data
        const [teamResponse, metricsResponse, seasonStatsResponse, gamesResponse] = await Promise.all([
            fetch(`/api/v1/cbb/teams/${props.teamId}`),
            fetch(`/api/v1/cbb/teams/${props.teamId}/metrics`),
            fetch(`/api/v1/cbb/teams/${props.teamId}/stats/season-averages`),
            fetch(`/api/v1/cbb/teams/${props.teamId}/games`)
        ])

        const teamData = await teamResponse.json()
        team.value = teamData.data

        if (metricsResponse.ok) {
            const metricsData = await metricsResponse.json()
            teamMetrics.value = metricsData.data
        }

        if (seasonStatsResponse.ok) {
            const seasonStatsData = await seasonStatsResponse.json()
            seasonStats.value = seasonStatsData.data || null
        }

        if (gamesResponse.ok) {
            const gamesData = await gamesResponse.json()
            const games = gamesData.data || []

            // Split into recent and upcoming games
            const now = new Date()
            recentGames.value = games
                .filter((game: Game) => game.status === 'STATUS_FINAL')
                .sort((a: Game, b: Game) => new Date(b.game_date).getTime() - new Date(a.game_date).getTime())
                .slice(0, 10)

            upcomingGames.value = games
                .filter((game: Game) => game.status !== 'STATUS_FINAL' && new Date(game.game_date) >= now)
                .sort((a: Game, b: Game) => new Date(a.game_date).getTime() - new Date(b.game_date).getTime())
                .slice(0, 10)
        }
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'An error occurred loading team data'
    } finally {
        loading.value = false
    }
})

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
    const isHome = game.home_team_id === props.teamId
    const teamScore = isHome ? game.home_score : game.away_score
    const oppScore = isHome ? game.away_score : game.home_score
    return teamScore > oppScore ? 'W' : 'L'
}
</script>

<template>
    <Head :title="team?.name || 'Team'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div v-if="loading" class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="flex items-start gap-4">
                <div class="w-20 h-20 bg-muted animate-pulse rounded" />
                <div class="flex-1 space-y-2">
                    <div class="h-8 w-64 bg-muted animate-pulse rounded" />
                    <div class="h-4 w-48 bg-muted animate-pulse rounded" />
                </div>
            </div>
            <div class="h-48 bg-muted animate-pulse rounded" />
        </div>

        <div v-else-if="error" class="flex h-full flex-1 flex-col gap-4 p-4">
            <Card>
                <CardContent class="p-6">
                    <p class="text-destructive">{{ error }}</p>
                </CardContent>
            </Card>
        </div>

        <div v-else-if="team" class="flex h-full flex-1 flex-col gap-4 p-4">
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
                                :href="CBBGameController(game.id)"
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
                                        {{ game.home_team_id === teamId ? 'vs' : '@' }}
                                    </span>
                                    <span class="font-medium">
                                        {{ getOpponent(game, game.home_team_id === teamId)?.name }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-4">
                                    <span class="text-sm font-medium">
                                        {{ game.home_team_id === teamId ? game.home_score : game.away_score }} -
                                        {{ game.home_team_id === teamId ? game.away_score : game.home_score }}
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
                                :href="CBBGameController(game.id)"
                                class="flex items-center justify-between p-3 rounded-lg hover:bg-muted/50 transition-colors"
                            >
                                <div class="flex items-center gap-3 flex-1">
                                    <span class="text-sm text-muted-foreground">
                                        {{ game.home_team_id === teamId ? 'vs' : '@' }}
                                    </span>
                                    <span class="font-medium">
                                        {{ getOpponent(game, game.home_team_id === teamId)?.name }}
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
    </AppLayout>
</template>
