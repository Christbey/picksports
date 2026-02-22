<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ref, onMounted, computed } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { type BreadcrumbItem } from '@/types'

interface Team {
    id: number
    name: string
    location: string
    abbreviation: string
    logo_url: string | null
    league: string
    division: string
}

interface Game {
    id: number
    home_team_id: number
    away_team_id: number
    home_score: number | null
    away_score: number | null
    home_linescores: any[] | null
    away_linescores: any[] | null
    status: string
    game_date: string
    inning: number | null
    inning_half: string | null
    venue_name: string | null
    venue_city: string | null
    venue_state: string | null
    broadcast_networks: string[] | null
    season: number
    season_type: string
}

interface Prediction {
    home_win_probability: number
    away_win_probability: number
    predicted_spread: number
    predicted_total: number
    confidence_level: string
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
    game: Game
}>()

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'MLB', href: '/mlb-predictions' },
    { title: 'Games', href: '/mlb-predictions' },
    { title: `Game ${props.game.id}`, href: `/mlb/games/${props.game.id}` }
])

const homeTeam = ref<Team | null>(null)
const awayTeam = ref<Team | null>(null)
const prediction = ref<Prediction | null>(null)
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

const getRecentForm = (games: Game[], teamId: number): string => {
    return games.map(g => {
        const isHome = g.home_team_id === teamId
        const teamScore = isHome ? g.home_score : g.away_score
        const oppScore = isHome ? g.away_score : g.home_score
        return teamScore && oppScore && teamScore > oppScore ? 'W' : 'L'
    }).join('-')
}

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

onMounted(async () => {
    try {
        loading.value = true
        error.value = null

        // Fetch team details
        const [homeTeamRes, awayTeamRes, predictionRes] = await Promise.all([
            fetch(`/api/v1/mlb/teams/${props.game.home_team_id}`),
            fetch(`/api/v1/mlb/teams/${props.game.away_team_id}`),
            fetch(`/api/v1/mlb/games/${props.game.id}/prediction`)
        ])

        if (homeTeamRes.ok) {
            const data = await homeTeamRes.json()
            homeTeam.value = data.data
        }

        if (awayTeamRes.ok) {
            const data = await awayTeamRes.json()
            awayTeam.value = data.data
        }

        if (predictionRes.ok) {
            const data = await predictionRes.json()
            prediction.value = data.data
        }

        // Fetch trends for both teams
        if (homeTeam.value?.id || awayTeam.value?.id) {
            trendsLoading.value = true
            const beforeDate = props.game.game_date || undefined
            const trendsPromises = []

            if (homeTeam.value?.id) {
                trendsPromises.push(
                    fetch(`/api/v1/mlb/teams/${homeTeam.value.id}/trends?before_date=${beforeDate || ''}`)
                        .then(res => res.ok ? res.json() : null)
                        .then(data => { homeTrends.value = data })
                        .catch(() => { homeTrends.value = null })
                )
            }

            if (awayTeam.value?.id) {
                trendsPromises.push(
                    fetch(`/api/v1/mlb/teams/${awayTeam.value.id}/trends?before_date=${beforeDate || ''}`)
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
                <!-- Matchup Hero -->
                <div class="rounded-xl overflow-hidden bg-gradient-to-r from-orange-600 to-orange-800 dark:from-orange-800 dark:to-orange-950 text-white shadow-lg">
                    <div class="px-6 py-8">
                        <div class="flex flex-col md:flex-row items-center justify-between gap-6">
                            <Link
                                v-if="awayTeam"
                                :href="`/mlb/teams/${awayTeam.id}`"
                                class="flex-1 flex flex-col items-center md:items-end gap-2 hover:opacity-80 transition-opacity"
                            >
                                <img
                                    v-if="awayTeam.logo_url"
                                    :src="awayTeam.logo_url"
                                    :alt="awayTeam.name"
                                    class="w-20 h-20 object-contain drop-shadow-lg"
                                />
                                <div class="text-center md:text-right">
                                    <div class="text-xl md:text-2xl font-bold">{{ awayTeam.location }} {{ awayTeam.name }}</div>
                                    <div class="text-sm text-white/70">Away</div>
                                    <div v-if="awayRecentGames.length > 0" class="text-xs text-white/60 mt-1">
                                        {{ getRecentForm(awayRecentGames, game.away_team_id) }}
                                    </div>
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
                                :href="`/mlb/teams/${homeTeam.id}`"
                                class="flex-1 flex flex-col items-center md:items-start gap-2 hover:opacity-80 transition-opacity"
                            >
                                <img
                                    v-if="homeTeam.logo_url"
                                    :src="homeTeam.logo_url"
                                    :alt="homeTeam.name"
                                    class="w-20 h-20 object-contain drop-shadow-lg"
                                />
                                <div class="text-center md:text-left">
                                    <div class="text-xl md:text-2xl font-bold">{{ homeTeam.location }} {{ homeTeam.name }}</div>
                                    <div class="text-sm text-white/70">Home</div>
                                    <div v-if="homeRecentGames.length > 0" class="text-xs text-white/60 mt-1">
                                        {{ getRecentForm(homeRecentGames, game.home_team_id) }}
                                    </div>
                                </div>
                            </Link>
                        </div>
                    </div>
                    <!-- Game Info Bar -->
                    <div class="bg-black/20 px-6 py-3 flex flex-wrap items-center justify-center gap-x-6 gap-y-1 text-sm text-white/80">
                        <span>{{ formatDate(game.game_date) }}</span>
                        <span v-if="game.venue_name" class="flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" /></svg>
                            {{ game.venue_name }}<span v-if="game.venue_city">, {{ game.venue_city }}</span>
                        </span>
                        <span v-if="game.broadcast_networks && game.broadcast_networks.length > 0" class="flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6zm12.553 1.106A1 1 0 0014 8v4a1 1 0 00.553.894l2 1A1 1 0 0018 13V7a1 1 0 00-1.447-.894l-2 1z" /></svg>
                            {{ game.broadcast_networks.join(', ') }}
                        </span>
                    </div>
                </div>

                <!-- Linescore Table -->
                <Card v-if="game.home_linescores && game.away_linescores && game.status === 'STATUS_FINAL'">
                    <CardHeader>
                        <CardTitle>Inning by Inning</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b">
                                        <th class="text-left p-2 text-muted-foreground font-medium">Team</th>
                                        <th class="text-center p-2 text-muted-foreground font-medium" v-for="(_, idx) in game.home_linescores" :key="idx">
                                            {{ idx + 1 }}
                                        </th>
                                        <th class="text-center p-2 font-bold">R</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="border-b">
                                        <td class="p-2 font-medium">
                                            <span class="flex items-center gap-2">
                                                <img v-if="awayTeam?.logo_url" :src="awayTeam.logo_url" :alt="awayTeam.abbreviation" class="h-5 w-5 object-contain" />
                                                {{ awayTeam?.abbreviation }}
                                            </span>
                                        </td>
                                        <td class="text-center p-2" v-for="(inning, idx) in game.away_linescores" :key="idx">
                                            {{ inning.value ?? inning }}
                                        </td>
                                        <td class="text-center p-2 font-bold" :class="game.away_score > game.home_score ? 'text-green-600 dark:text-green-400' : ''">{{ game.away_score }}</td>
                                    </tr>
                                    <tr>
                                        <td class="p-2 font-medium">
                                            <span class="flex items-center gap-2">
                                                <img v-if="homeTeam?.logo_url" :src="homeTeam.logo_url" :alt="homeTeam.abbreviation" class="h-5 w-5 object-contain" />
                                                {{ homeTeam?.abbreviation }}
                                            </span>
                                        </td>
                                        <td class="text-center p-2" v-for="(inning, idx) in game.home_linescores" :key="idx">
                                            {{ inning.value ?? inning }}
                                        </td>
                                        <td class="text-center p-2 font-bold" :class="game.home_score > game.away_score ? 'text-green-600 dark:text-green-400' : ''">{{ game.home_score }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                <!-- Prediction -->
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
                                <div class="bg-orange-500 dark:bg-orange-600 transition-all" :style="{ width: `${prediction.away_win_probability * 100}%` }"></div>
                                <div class="bg-orange-800 dark:bg-orange-400 transition-all" :style="{ width: `${prediction.home_win_probability * 100}%` }"></div>
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
                                <div class="text-xs text-muted-foreground mt-1">Projected runs</div>
                            </div>
                            <div class="text-center p-4 rounded-lg border">
                                <div class="text-sm text-muted-foreground">Confidence</div>
                                <div class="text-2xl font-bold capitalize">
                                    {{ prediction.confidence_level }}
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
