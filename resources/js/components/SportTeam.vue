<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ref, onMounted, computed } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { type BreadcrumbItem, type Game } from '@/types'
import { formatNumber, ratingClass } from '@/components/sport-team-metrics-helpers'

export interface MetricTile {
    label: string
    value: (metrics: any) => string
    class?: (metrics: any) => string
}

export interface StatTile {
    label: string
    value: (stats: any) => string
    class?: (stats: any) => string
    rankingKey?: string
}

export interface TeamPageConfig {
    sport: string
    sportLabel: string
    predictionsHref: string
    metricsHref?: string
    headTitle: (team: any) => string
    teamDisplayName: (team: any) => string
    teamLogo: (team: any) => string | null
    teamSubtitle: (team: any) => string
    teamHref: (teamId: number) => string
    gameLink: (gameId: number) => string
    apiBase: string

    metricTiles: MetricTile[]
    metricsGridCols?: string

    seasonStatTiles?: StatTile[]
    seasonStatsGridCols?: string
    overviewStatCount?: number

    useTabs?: boolean
    showPowerRanking?: boolean
    showRecentForm?: boolean
    showTrends?: boolean
    trendsGames?: number

    recentGamesLimit?: number
    upcomingGamesLimit?: number
    gamesLayout?: 'stacked' | 'side-by-side'
    sortRecentByDate?: boolean

    headerInfo?: (team: any, computed: { record: { wins: number; losses: number } }) => { label: string; value: string }[]

    statRankingKeys?: { key: string; descending?: boolean }[]

    showRoster?: boolean
    playerLink?: (playerId: number) => string
}

const props = defineProps<{
    config: TeamPageConfig
    team?: any
    teamId?: number
    preloadedMetrics?: any
    preloadedSeasonStats?: any
    preloadedRecentGames?: any[]
    preloadedUpcomingGames?: any[]
}>()

const teamData = ref<any>(props.team || null)
const teamMetrics = ref<any>(props.preloadedMetrics || null)
const seasonStats = ref<any>(props.preloadedSeasonStats || null)
const recentGames = ref<Game[]>((props.preloadedRecentGames as Game[]) || [])
const upcomingGames = ref<Game[]>((props.preloadedUpcomingGames as Game[]) || [])
const powerRanking = ref<{ rank: number; total_teams: number } | null>(null)
const statRankings = ref<Record<string, number>>({})
const rosterPlayers = ref<any[]>([])
const rosterLoading = ref(false)
const trendsData = ref<Record<string, string[]> | null>(null)
const lockedTrends = ref<Record<string, string> | null>(null)
const loading = ref(!hasPreloadedData())
const error = ref<string | null>(null)

function hasPreloadedData(): boolean {
    return !!(props.preloadedMetrics !== undefined && props.preloadedRecentGames !== undefined)
}

const teamId = computed(() => teamData.value?.id || props.teamId)

const breadcrumbs = computed<BreadcrumbItem[]>(() => {
    const items: BreadcrumbItem[] = [
        { title: props.config.sportLabel, href: props.config.predictionsHref },
    ]
    if (props.config.metricsHref) {
        items.push({ title: 'Team Metrics', href: props.config.metricsHref })
    }
    items.push({
        title: teamData.value ? props.config.headTitle(teamData.value) : 'Team',
        href: teamId.value ? props.config.teamHref(teamId.value) : '#',
    })
    return items
})

const formatDate = (dateString: string | null): string => {
    if (!dateString) return '-'
    return new Date(dateString).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

const getOpponent = (game: Game, isHome: boolean) => {
    return isHome ? game.away_team : game.home_team
}

const getGameResult = (game: Game): string | null => {
    if (game.status !== 'STATUS_FINAL' || !game.home_score || !game.away_score) return null
    const tid = teamId.value
    const isHome = game.home_team_id === tid
    const teamScore = isHome ? game.home_score : game.away_score
    const oppScore = isHome ? game.away_score : game.home_score
    return teamScore > oppScore ? 'W' : 'L'
}

const record = computed(() => {
    const wins = recentGames.value.filter(g => getGameResult(g) === 'W').length
    const losses = recentGames.value.filter(g => getGameResult(g) === 'L').length
    return { wins, losses }
})

const recentForm = computed(() => {
    return recentGames.value.slice(0, 5).map(g => getGameResult(g)).filter(Boolean)
})

const recentRecord = computed(() => {
    const last5 = recentGames.value.slice(0, 5)
    const wins = last5.filter(g => getGameResult(g) === 'W').length
    const losses = last5.length - wins
    return { wins, losses, games: last5.length }
})

const headerInfoItems = computed(() => {
    if (!props.config.headerInfo || !teamData.value) return []
    return props.config.headerInfo(teamData.value, { record: record.value })
})

const trendLabel = (key: string): string => {
    const labels: Record<string, string> = {
        scoring: 'Scoring', margins: 'Margins', streaks: 'Streaks',
        quarters: 'Quarters', halves: 'Halves', totals: 'Totals',
        first_score: 'First Score', situational: 'Situational',
        advanced: 'Advanced', time_based: 'Time Based',
        rest_schedule: 'Rest & Schedule', opponent_strength: 'Opponent Strength',
        conference: 'Conference', scoring_patterns: 'Scoring Patterns',
        offensive_efficiency: 'Offensive Efficiency',
        defensive_performance: 'Defensive Performance',
        momentum: 'Momentum', clutch_performance: 'Clutch Performance',
    }
    return labels[key] || key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

const allTrendCategories = computed(() => {
    const categories = new Set<string>()
    if (trendsData.value) {
        Object.keys(trendsData.value).forEach(key => categories.add(key))
    }
    if (lockedTrends.value) {
        Object.keys(lockedTrends.value).forEach(key => categories.add(key))
    }
    return Array.from(categories).sort()
})

const displayRecentGames = computed(() => {
    const limit = props.config.recentGamesLimit ?? 10
    return recentGames.value.slice(0, limit)
})

const displayUpcomingGames = computed(() => {
    const limit = props.config.upcomingGamesLimit ?? 5
    return upcomingGames.value.slice(0, limit)
})

const overviewSeasonStatTiles = computed(() => {
    if (!props.config.seasonStatTiles) return []
    if (props.config.overviewStatCount) {
        return props.config.seasonStatTiles.slice(0, props.config.overviewStatCount)
    }
    return props.config.seasonStatTiles
})

onMounted(async () => {
    if (hasPreloadedData() && !props.config.showTrends && !props.config.showPowerRanking) return

    try {
        loading.value = !hasPreloadedData()
        error.value = null

        const fetchId = props.teamId || props.team?.id
        if (!fetchId) return

        const fetches: Promise<Response>[] = []
        const fetchKeys: string[] = []

        if (!props.preloadedMetrics) {
            fetches.push(fetch(`${props.config.apiBase}/teams/${fetchId}/metrics`))
            fetchKeys.push('metrics')
        }

        if (props.config.seasonStatTiles && props.preloadedSeasonStats === undefined) {
            fetches.push(fetch(`${props.config.apiBase}/teams/${fetchId}/stats/season-averages`))
            fetchKeys.push('seasonStats')
        }

        if (props.preloadedRecentGames === undefined) {
            fetches.push(fetch(`${props.config.apiBase}/teams/${fetchId}/games`))
            fetchKeys.push('games')
        }

        if (!props.team && props.teamId) {
            fetches.push(fetch(`${props.config.apiBase}/teams/${fetchId}`))
            fetchKeys.push('team')
        }

        if (props.config.showPowerRanking) {
            fetches.push(fetch(`${props.config.apiBase}/team-metrics`))
            fetchKeys.push('allMetrics')
        }

        if (props.config.statRankingKeys) {
            fetches.push(fetch(`${props.config.apiBase}/team-stats/season-averages`))
            fetchKeys.push('allStats')
        }

        if (props.config.showTrends) {
            const games = props.config.trendsGames ?? 20
            fetches.push(fetch(`${props.config.apiBase}/teams/${fetchId}/trends?games=${games}`))
            fetchKeys.push('trends')
        }

        if (props.config.showRoster) {
            fetches.push(fetch(`${props.config.apiBase}/teams/${fetchId}/players`))
            fetchKeys.push('roster')
        }

        const responses = await Promise.all(fetches)

        for (let i = 0; i < fetchKeys.length; i++) {
            const key = fetchKeys[i]
            const res = responses[i]

            if (key === 'team' && res.ok) {
                const data = await res.json()
                teamData.value = data.data
            }

            if (key === 'metrics' && res.ok) {
                const data = await res.json()
                teamMetrics.value = data.data?.[0] ?? data.data ?? null
            }

            if (key === 'seasonStats' && res.ok) {
                const data = await res.json()
                seasonStats.value = data.data || null
            }

            if (key === 'games' && res.ok) {
                const data = await res.json()
                const games = data.data || []

                if (props.config.sortRecentByDate) {
                    recentGames.value = games
                        .filter((g: Game) => g.status === 'STATUS_FINAL')
                        .sort((a: Game, b: Game) => new Date(b.game_date!).getTime() - new Date(a.game_date!).getTime())
                        .slice(0, props.config.recentGamesLimit ?? 10)

                    const now = new Date()
                    upcomingGames.value = games
                        .filter((g: Game) => g.status !== 'STATUS_FINAL' && new Date(g.game_date!) >= now)
                        .sort((a: Game, b: Game) => new Date(a.game_date!).getTime() - new Date(b.game_date!).getTime())
                        .slice(0, props.config.upcomingGamesLimit ?? 10)
                } else {
                    recentGames.value = games
                        .filter((g: Game) => g.status === 'STATUS_FINAL')
                        .slice(0, props.config.recentGamesLimit ?? 10)

                    upcomingGames.value = games
                        .filter((g: Game) => g.status === 'STATUS_SCHEDULED' || g.status === 'STATUS_IN_PROGRESS')
                        .slice(0, props.config.upcomingGamesLimit ?? 5)
                }
            }

            if (key === 'allMetrics' && res.ok) {
                const data = await res.json()
                const allMetrics = data.data || []
                const idx = allMetrics.findIndex((m: any) => m.team_id === teamId.value)
                if (idx !== -1) {
                    powerRanking.value = { rank: idx + 1, total_teams: allMetrics.length }
                }
            }

            if (key === 'allStats' && res.ok && props.config.statRankingKeys) {
                const data = await res.json()
                const allStats = data.data || []
                const rankings: Record<string, number> = {}
                for (const { key: statKey, descending } of props.config.statRankingKeys) {
                    const desc = descending ?? true
                    const sorted = [...allStats].sort((a: any, b: any) =>
                        desc ? b[statKey] - a[statKey] : a[statKey] - b[statKey]
                    )
                    const idx = sorted.findIndex((s: any) => s.team_id === teamId.value)
                    rankings[statKey] = idx !== -1 ? idx + 1 : 0
                }
                statRankings.value = rankings
            }

            if (key === 'trends' && res.ok) {
                const data = await res.json()
                trendsData.value = data.trends || null
                lockedTrends.value = data.locked_trends || null
            }

            if (key === 'roster' && res.ok) {
                const data = await res.json()
                rosterPlayers.value = data.data || []
            }
        }
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'An error occurred loading team data'
    } finally {
        loading.value = false
    }
})
</script>

<template>
    <Head :title="teamData ? config.headTitle(teamData) : 'Team'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <!-- Loading state for team data fetch (CBB) -->
        <div v-if="!teamData && loading" class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="flex items-start gap-4">
                <div class="w-20 h-20 bg-muted animate-pulse rounded" />
                <div class="flex-1 space-y-2">
                    <div class="h-8 w-64 bg-muted animate-pulse rounded" />
                    <div class="h-4 w-48 bg-muted animate-pulse rounded" />
                </div>
            </div>
            <div class="h-48 bg-muted animate-pulse rounded" />
        </div>

        <!-- Error state (no team data) -->
        <div v-else-if="!teamData && error" class="flex h-full flex-1 flex-col gap-4 p-4">
            <Card>
                <CardContent class="p-6">
                    <p class="text-destructive">{{ error }}</p>
                </CardContent>
            </Card>
        </div>

        <!-- Main content -->
        <div v-else-if="teamData" class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Team Header -->
            <div class="flex items-start gap-4">
                <img
                    v-if="config.teamLogo(teamData)"
                    :src="config.teamLogo(teamData)!"
                    :alt="config.teamDisplayName(teamData)"
                    class="w-20 h-20 object-contain"
                />
                <div class="flex-1">
                    <h1 class="text-3xl font-bold">{{ config.teamDisplayName(teamData) }}</h1>
                    <p class="text-muted-foreground">{{ config.teamSubtitle(teamData) }}</p>
                    <div v-if="headerInfoItems.length > 0" class="mt-2 flex items-center gap-4">
                        <div v-for="item in headerInfoItems" :key="item.label" class="text-sm font-medium">
                            {{ item.label }}: {{ item.value }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Error alert -->
            <Alert v-if="error" variant="destructive">
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>

            <!-- Loading skeletons for data -->
            <div v-if="loading" class="space-y-4">
                <Skeleton class="h-32 w-full" />
                <Skeleton class="h-64 w-full" />
                <Skeleton class="h-64 w-full" />
            </div>

            <template v-else>
                <!-- Power Ranking and Recent Form Cards (NBA) -->
                <div v-if="config.showPowerRanking || config.showRecentForm" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Card v-if="config.showPowerRanking && powerRanking">
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
                                        :class="ratingClass(teamMetrics.net_rating)"
                                    >
                                        {{ teamMetrics.net_rating > 0 ? '+' : '' }}{{ formatNumber(teamMetrics.net_rating) }}
                                    </div>
                                    <div class="text-xs text-muted-foreground mt-1">Net Rating</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card v-if="config.showRecentForm && recentRecord.games > 0">
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
                                            v-for="(result, idx) in recentForm"
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
                <template v-if="config.useTabs">
                    <Tabs default-value="overview" class="w-full">
                        <TabsList class="w-full">
                            <TabsTrigger value="overview">Overview</TabsTrigger>
                            <TabsTrigger v-if="config.seasonStatTiles" value="stats">Advanced Stats</TabsTrigger>
                            <TabsTrigger v-if="config.showTrends" value="trends">Trends & Insights</TabsTrigger>
                            <TabsTrigger v-if="config.showRoster" value="roster">Roster</TabsTrigger>
                            <TabsTrigger value="schedule">Schedule</TabsTrigger>
                        </TabsList>

                        <!-- Overview Tab -->
                        <TabsContent value="overview">
                            <div class="space-y-4">
                                <!-- Metrics Card -->
                                <Card v-if="teamMetrics">
                                    <CardHeader>
                                        <CardTitle>Current Season Metrics</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div class="grid grid-cols-2 gap-4" :class="config.metricsGridCols || 'md:grid-cols-5'">
                                            <div v-for="tile in config.metricTiles" :key="tile.label" class="text-center p-4 bg-muted/50 rounded-lg">
                                                <div class="text-sm text-muted-foreground">{{ tile.label }}</div>
                                                <div class="text-2xl font-bold" :class="tile.class?.(teamMetrics)">
                                                    {{ tile.value(teamMetrics) }}
                                                </div>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>

                                <!-- Season Stats (overview count) -->
                                <Card v-if="seasonStats">
                                    <CardHeader>
                                        <CardTitle>Season Averages ({{ seasonStats.games_played }} games)</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div class="grid grid-cols-2 gap-4" :class="config.seasonStatsGridCols || 'md:grid-cols-4 lg:grid-cols-6'">
                                            <div v-for="tile in overviewSeasonStatTiles" :key="tile.label" class="text-center p-4 bg-muted/50 rounded-lg">
                                                <div class="text-sm text-muted-foreground">{{ tile.label }}</div>
                                                <div class="text-2xl font-bold" :class="tile.class?.(seasonStats)">
                                                    {{ tile.value(seasonStats) }}
                                                    <span v-if="tile.rankingKey && statRankings[tile.rankingKey]" class="text-xs font-normal text-muted-foreground">#{{ statRankings[tile.rankingKey] }}</span>
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
                                                :href="config.gameLink(game.id)"
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

                                <div v-if="!teamMetrics && !seasonStats && recentGames.length === 0" class="text-center py-8 text-muted-foreground">
                                    <p>No overview data available for this team yet.</p>
                                </div>
                            </div>
                        </TabsContent>

                        <!-- Advanced Stats Tab -->
                        <TabsContent v-if="config.seasonStatTiles" value="stats">
                            <Card v-if="seasonStats">
                                <CardHeader>
                                    <CardTitle>Season Averages ({{ seasonStats.games_played }} games)</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div class="grid grid-cols-2 gap-4" :class="config.seasonStatsGridCols || 'md:grid-cols-4 lg:grid-cols-6'">
                                        <div v-for="tile in config.seasonStatTiles" :key="tile.label" class="text-center p-4 bg-muted/50 rounded-lg">
                                            <div class="text-sm text-muted-foreground">{{ tile.label }}</div>
                                            <div class="text-2xl font-bold" :class="tile.class?.(seasonStats)">
                                                {{ tile.value(seasonStats) }}
                                                <span v-if="tile.rankingKey && statRankings[tile.rankingKey]" class="text-xs font-normal text-muted-foreground">#{{ statRankings[tile.rankingKey] }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>

                        <!-- Trends Tab -->
                        <TabsContent v-if="config.showTrends" value="trends">
                            <div v-if="trendsData && Object.keys(trendsData).length > 0" class="space-y-4">
                                <Card v-for="(insights, key) in trendsData" :key="key">
                                    <CardHeader>
                                        <CardTitle>{{ trendLabel(key as string) }}</CardTitle>
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
                                                <span class="text-sm font-medium">{{ trendLabel(key as string) }}</span>
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

                        <!-- Roster Tab -->
                        <TabsContent v-if="config.showRoster" value="roster">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Roster</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div v-if="rosterPlayers.length > 0" class="space-y-2">
                                        <Link
                                            v-for="player in rosterPlayers"
                                            :key="player.id"
                                            :href="config.playerLink ? config.playerLink(player.id) : '#'"
                                            class="flex items-center gap-4 p-3 rounded-lg hover:bg-muted/50 transition-colors"
                                        >
                                            <img
                                                v-if="player.headshot_url"
                                                :src="player.headshot_url"
                                                :alt="player.name"
                                                class="w-10 h-10 rounded-full object-cover"
                                            />
                                            <div v-else class="w-10 h-10 rounded-full bg-muted flex items-center justify-center text-xs font-bold text-muted-foreground">
                                                {{ player.first_name?.[0] }}{{ player.last_name?.[0] }}
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="font-medium truncate">{{ player.name }}</div>
                                                <div class="text-sm text-muted-foreground">
                                                    {{ player.position }}
                                                    <span v-if="player.height"> · {{ player.height }}</span>
                                                </div>
                                            </div>
                                            <div v-if="player.jersey_number" class="text-lg font-bold text-muted-foreground">
                                                #{{ player.jersey_number }}
                                            </div>
                                        </Link>
                                    </div>
                                    <div v-else class="text-center py-8 text-muted-foreground">
                                        <p>No roster data available.</p>
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
                                                v-for="game in displayRecentGames"
                                                :key="game.id"
                                                :href="config.gameLink(game.id)"
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
                                                v-for="game in displayUpcomingGames"
                                                :key="game.id"
                                                :href="config.gameLink(game.id)"
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
                        </TabsContent>
                    </Tabs>
                </template>

                <!-- Non-tabbed linear layout -->
                <template v-else>
                    <!-- Metrics Card -->
                    <Card v-if="teamMetrics">
                        <CardHeader>
                            <CardTitle>Current Season Metrics</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="grid grid-cols-2 gap-4" :class="config.metricsGridCols || 'md:grid-cols-5'">
                                <div v-for="tile in config.metricTiles" :key="tile.label" class="text-center p-4 bg-muted/50 rounded-lg">
                                    <div class="text-sm text-muted-foreground">{{ tile.label }}</div>
                                    <div class="text-2xl font-bold" :class="tile.class?.(teamMetrics)">
                                        {{ tile.value(teamMetrics) }}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Season Stats Card -->
                    <Card v-if="seasonStats && config.seasonStatTiles">
                        <CardHeader>
                            <CardTitle>Season Averages ({{ seasonStats.games_played }} games)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="grid grid-cols-2 gap-4" :class="config.seasonStatsGridCols || 'md:grid-cols-4 lg:grid-cols-6'">
                                <div v-for="tile in config.seasonStatTiles" :key="tile.label" class="text-center p-4 bg-muted/50 rounded-lg">
                                    <div class="text-sm text-muted-foreground">{{ tile.label }}</div>
                                    <div class="text-2xl font-bold" :class="tile.class?.(seasonStats)">
                                        {{ tile.value(seasonStats) }}
                                        <span v-if="tile.rankingKey && statRankings[tile.rankingKey]" class="text-xs font-normal text-muted-foreground">#{{ statRankings[tile.rankingKey] }}</span>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Games: side-by-side or stacked -->
                    <div :class="config.gamesLayout === 'side-by-side' ? 'grid grid-cols-1 lg:grid-cols-2 gap-4' : 'space-y-4'">
                        <Card v-if="displayRecentGames.length > 0">
                            <CardHeader>
                                <CardTitle>Recent Games</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="space-y-2">
                                    <Link
                                        v-for="game in displayRecentGames"
                                        :key="game.id"
                                        :href="config.gameLink(game.id)"
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

                        <Card v-if="displayUpcomingGames.length > 0">
                            <CardHeader>
                                <CardTitle>Upcoming Games</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="space-y-2">
                                    <Link
                                        v-for="game in displayUpcomingGames"
                                        :key="game.id"
                                        :href="config.gameLink(game.id)"
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

                    <!-- Trends Section (non-tabbed: MLB) -->
                    <template v-if="config.showTrends">
                        <Card>
                            <CardHeader>
                                <CardTitle>Team Trends</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div v-if="loading" class="space-y-4">
                                    <Skeleton class="h-24 w-full" />
                                    <Skeleton class="h-24 w-full" />
                                </div>

                                <div v-else-if="allTrendCategories.length > 0" class="space-y-6">
                                    <div v-for="category in allTrendCategories" :key="category" class="border-b pb-4 last:border-b-0">
                                        <h4 class="font-medium mb-3">{{ trendLabel(category) }}</h4>

                                        <div v-if="lockedTrends && lockedTrends[category]" class="text-center py-4 bg-muted/50 rounded-lg">
                                            <div class="text-sm text-muted-foreground">
                                                Upgrade to {{ lockedTrends[category].charAt(0).toUpperCase() + lockedTrends[category].slice(1) }} to unlock this trend
                                            </div>
                                        </div>

                                        <ul v-else-if="trendsData?.[category]?.length" class="space-y-1 text-sm">
                                            <li v-for="(trend, idx) in trendsData[category]" :key="idx" class="flex items-start gap-2">
                                                <span class="text-muted-foreground">&bull;</span>
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
                    </template>
                </template>
            </template>
        </div>
    </AppLayout>
</template>
