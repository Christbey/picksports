<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ref, onMounted, computed } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { type BreadcrumbItem, type Game, type Team, type Prediction, type TeamMetric } from '@/types'
import WCBBTeamController from '@/actions/App/Http/Controllers/WCBB/TeamController'

const props = defineProps<{
    game: Game
}>()

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'WCBB', href: '/wcbb-predictions' },
    { title: 'Games', href: '/wcbb-predictions' },
    { title: `Game ${props.game.id}`, href: `/wcbb/games/${props.game.id}` }
])

const homeTeam = ref<Team | null>(null)
const awayTeam = ref<Team | null>(null)
const prediction = ref<Prediction | null>(null)
const homeMetrics = ref<TeamMetric | null>(null)
const awayMetrics = ref<TeamMetric | null>(null)
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

onMounted(async () => {
    try {
        loading.value = true
        error.value = null

        const [gameRes, predictionRes] = await Promise.all([
            fetch(`/api/v1/wcbb/games/${props.game.id}`),
            fetch(`/api/v1/wcbb/games/${props.game.id}/prediction`)
        ])

        if (gameRes.ok) {
            const gameData = await gameRes.json()
            const fullGame = gameData.data
            homeTeam.value = fullGame.home_team
            awayTeam.value = fullGame.away_team

            if (fullGame.home_team?.id) {
                const homeMetricsRes = await fetch(`/api/v1/wcbb/teams/${fullGame.home_team.id}/metrics`)
                if (homeMetricsRes.ok) {
                    const data = await homeMetricsRes.json()
                    homeMetrics.value = data.data?.[0] || null
                }
            }

            if (fullGame.away_team?.id) {
                const awayMetricsRes = await fetch(`/api/v1/wcbb/teams/${fullGame.away_team.id}/metrics`)
                if (awayMetricsRes.ok) {
                    const data = await awayMetricsRes.json()
                    awayMetrics.value = data.data?.[0] || null
                }
            }
        }

        if (predictionRes.ok) {
            const predictionData = await predictionRes.json()
            prediction.value = predictionData.data
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
                <div class="rounded-xl overflow-hidden bg-gradient-to-r from-purple-600 to-purple-800 dark:from-purple-800 dark:to-purple-950 text-white shadow-lg">
                    <div class="px-6 py-8">
                        <div class="flex flex-col md:flex-row items-center justify-between gap-6">
                            <Link
                                v-if="awayTeam"
                                :href="WCBBTeamController(awayTeam.id)"
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
                                :href="WCBBTeamController(homeTeam.id)"
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
                                </div>
                            </Link>
                        </div>
                    </div>
                    <!-- Game Info Bar -->
                    <div class="bg-black/20 px-6 py-3 flex flex-wrap items-center justify-center gap-x-6 gap-y-1 text-sm text-white/80">
                        <span>{{ formatDate(game.game_date) }}</span>
                    </div>
                </div>

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
                                <div class="bg-purple-500 dark:bg-purple-600 transition-all" :style="{ width: `${prediction.away_win_probability * 100}%` }"></div>
                                <div class="bg-purple-800 dark:bg-purple-400 transition-all" :style="{ width: `${prediction.home_win_probability * 100}%` }"></div>
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
            </template>
        </div>
    </AppLayout>
</template>
