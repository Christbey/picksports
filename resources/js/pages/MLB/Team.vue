<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ref, onMounted, computed } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { type BreadcrumbItem } from '@/types'
import MLBGameController from '@/actions/App/Http/Controllers/MLB/GameController'

interface Team {
    id: number
    name: string
    location: string
    abbreviation: string
    nickname: string | null
    logo_url: string | null
    league: string
    division: string
    color: string | null
    elo_rating: number | null
}

interface TeamMetric {
    id: number
    team_id: number
    season: number
    offensive_rating: number
    pitching_rating: number
    defensive_rating: number
    runs_per_game: number
    runs_allowed_per_game: number
    batting_average: number
    team_era: number
    strength_of_schedule: number
}

interface Game {
    id: number
    home_team_id: number
    away_team_id: number
    home_score: number | null
    away_score: number | null
    status: string
    game_date: string
    home_team?: Team
    away_team?: Team
}

interface SeasonStats {
    games_played: number
    runs_per_game: number
    hits_per_game: number
    home_runs_per_game: number
    rbis_per_game: number
    walks_per_game: number
    strikeouts_per_game: number
    stolen_bases_per_game: number
    batting_average: number
    doubles_per_game: number
    triples_per_game: number
    errors_per_game: number
    earned_runs_per_game: number
    era: number
}

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
    team: Team
    metrics: TeamMetric | null
    recentGames: Game[]
    upcomingGames: Game[]
    seasonStats: SeasonStats | null
}>()

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'MLB', href: '/mlb-predictions' },
    { title: 'Team Metrics', href: '/mlb-team-metrics' },
    { title: props.team.location + ' ' + props.team.name, href: `/mlb/teams/${props.team.id}` }
]

const trends = ref<TeamTrends | null>(null)
const trendsLoading = ref(false)

const formatNumber = (value: number | string | null, decimals = 1): string => {
    if (value === null || value === undefined) return '-'
    const num = typeof value === 'string' ? parseFloat(value) : value
    if (isNaN(num)) return '-'
    return num.toFixed(decimals)
}

const formatBattingAverage = (value: number | string | null): string => {
    if (value === null || value === undefined) return '-'
    const num = typeof value === 'string' ? parseFloat(value) : value
    if (isNaN(num)) return '-'
    return num.toFixed(3).replace(/^0/, '')
}

const getRatingClass = (value: number | null, isEra = false): string => {
    if (value === null) return ''
    if (isEra) {
        if (value < 3.5) return 'text-green-600 dark:text-green-400 font-semibold'
        if (value < 4.0) return 'text-green-600 dark:text-green-400'
        if (value > 5.0) return 'text-red-600 dark:text-red-400 font-semibold'
        if (value > 4.5) return 'text-red-600 dark:text-red-400'
    } else {
        if (value > 5) return 'text-green-600 dark:text-green-400 font-semibold'
        if (value > 4.5) return 'text-green-600 dark:text-green-400'
        if (value < 3.5) return 'text-red-600 dark:text-red-400 font-semibold'
        if (value < 4) return 'text-red-600 dark:text-red-400'
    }
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

const formatCategoryName = (key: string): string => {
    return key
        .replace(/_/g, ' ')
        .replace(/\b\w/g, l => l.toUpperCase())
}

const isLockedCategory = (category: string): boolean => {
    return trends.value?.locked_trends ? Object.keys(trends.value.locked_trends).includes(category) : false
}

const getRequiredTier = (category: string): string => {
    return trends.value?.locked_trends?.[category] || 'pro'
}

const formatTierName = (tier: string): string => {
    return tier.charAt(0).toUpperCase() + tier.slice(1)
}

const allTrendCategories = computed(() => {
    const categories = new Set<string>()

    if (trends.value?.trends) {
        Object.keys(trends.value.trends).forEach(key => categories.add(key))
    }
    if (trends.value?.locked_trends) {
        Object.keys(trends.value.locked_trends).forEach(key => categories.add(key))
    }

    return Array.from(categories).sort()
})

onMounted(async () => {
    try {
        trendsLoading.value = true
        const trendsRes = await fetch(`/api/v1/mlb/teams/${props.team.id}/trends`)
        if (trendsRes.ok) {
            trends.value = await trendsRes.json()
        }
    } catch (e) {
        console.error('Failed to load trends:', e)
    } finally {
        trendsLoading.value = false
    }
})
</script>

<template>
    <Head :title="`${team.location} ${team.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="flex items-start gap-4">
                <img
                    v-if="team.logo_url"
                    :src="team.logo_url"
                    :alt="team.name"
                    class="w-20 h-20 object-contain"
                />
                <div class="flex-1">
                    <h1 class="text-3xl font-bold">{{ team.location }} {{ team.name }}</h1>
                    <p class="text-muted-foreground">
                        {{ team.league }} {{ team.division ? `• ${team.division}` : '' }}
                    </p>
                    <p v-if="team.elo_rating" class="text-sm text-muted-foreground mt-1">
                        Elo Rating: {{ team.elo_rating }}
                    </p>
                </div>
            </div>

            <Card v-if="metrics">
                <CardHeader>
                    <CardTitle>Current Season Metrics</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-4">
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">R/G</div>
                            <div class="text-2xl font-bold" :class="getRatingClass(metrics.runs_per_game)">
                                {{ formatNumber(metrics.runs_per_game, 2) }}
                            </div>
                        </div>
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">RA/G</div>
                            <div class="text-2xl font-bold" :class="getRatingClass(metrics.runs_allowed_per_game, true)">
                                {{ formatNumber(metrics.runs_allowed_per_game, 2) }}
                            </div>
                        </div>
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">AVG</div>
                            <div class="text-2xl font-bold">
                                {{ formatBattingAverage(metrics.batting_average) }}
                            </div>
                        </div>
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">ERA</div>
                            <div class="text-2xl font-bold" :class="getRatingClass(metrics.team_era, true)">
                                {{ formatNumber(metrics.team_era, 2) }}
                            </div>
                        </div>
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">ORtg</div>
                            <div class="text-2xl font-bold">
                                {{ formatNumber(metrics.offensive_rating) }}
                            </div>
                        </div>
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">PRtg</div>
                            <div class="text-2xl font-bold">
                                {{ formatNumber(metrics.pitching_rating) }}
                            </div>
                        </div>
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">DRtg</div>
                            <div class="text-2xl font-bold">
                                {{ formatNumber(metrics.defensive_rating) }}
                            </div>
                        </div>
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">SOS</div>
                            <div class="text-2xl font-bold">
                                {{ formatNumber(metrics.strength_of_schedule, 3) }}
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card v-if="seasonStats && seasonStats.games_played > 0">
                <CardHeader>
                    <CardTitle>Season Averages ({{ seasonStats.games_played }} games)</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4">
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">Runs</div>
                            <div class="text-2xl font-bold">
                                {{ formatNumber(seasonStats.runs_per_game, 2) }}
                            </div>
                        </div>
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">Hits</div>
                            <div class="text-2xl font-bold">
                                {{ formatNumber(seasonStats.hits_per_game, 2) }}
                            </div>
                        </div>
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">HR</div>
                            <div class="text-2xl font-bold">
                                {{ formatNumber(seasonStats.home_runs_per_game, 2) }}
                            </div>
                        </div>
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">RBI</div>
                            <div class="text-2xl font-bold">
                                {{ formatNumber(seasonStats.rbis_per_game, 2) }}
                            </div>
                        </div>
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">BB</div>
                            <div class="text-2xl font-bold">
                                {{ formatNumber(seasonStats.walks_per_game, 2) }}
                            </div>
                        </div>
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">K</div>
                            <div class="text-2xl font-bold">
                                {{ formatNumber(seasonStats.strikeouts_per_game, 2) }}
                            </div>
                        </div>
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">SB</div>
                            <div class="text-2xl font-bold">
                                {{ formatNumber(seasonStats.stolen_bases_per_game, 2) }}
                            </div>
                        </div>
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">2B</div>
                            <div class="text-2xl font-bold">
                                {{ formatNumber(seasonStats.doubles_per_game, 2) }}
                            </div>
                        </div>
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">3B</div>
                            <div class="text-2xl font-bold">
                                {{ formatNumber(seasonStats.triples_per_game, 2) }}
                            </div>
                        </div>
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">AVG</div>
                            <div class="text-2xl font-bold">
                                {{ formatBattingAverage(seasonStats.batting_average) }}
                            </div>
                        </div>
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">ERA</div>
                            <div class="text-2xl font-bold" :class="getRatingClass(seasonStats.era, true)">
                                {{ formatNumber(seasonStats.era, 2) }}
                            </div>
                        </div>
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">ER/G</div>
                            <div class="text-2xl font-bold">
                                {{ formatNumber(seasonStats.earned_runs_per_game, 2) }}
                            </div>
                        </div>
                        <div class="text-center p-4 bg-muted/50 rounded-lg">
                            <div class="text-sm text-muted-foreground">E/G</div>
                            <div class="text-2xl font-bold">
                                {{ formatNumber(seasonStats.errors_per_game, 2) }}
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <Card v-if="recentGames.length > 0">
                    <CardHeader>
                        <CardTitle>Recent Games</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-2">
                            <Link
                                v-for="game in recentGames"
                                :key="game.id"
                                :href="MLBGameController(game.id)"
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
                                :href="MLBGameController(game.id)"
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

                            <ul v-else-if="trends?.trends?.[category]?.length" class="space-y-1 text-sm">
                                <li v-for="(trend, idx) in trends.trends[category]" :key="idx" class="flex items-start gap-2">
                                    <span class="text-muted-foreground">•</span>
                                    <span>{{ trend }}</span>
                                </li>
                            </ul>
                            <p v-else class="text-sm text-muted-foreground">No trends available</p>
                        </div>
                    </div>

                    <div v-else class="text-center py-8 text-muted-foreground">
                        No trends available for this team
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
