<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ref, onMounted, computed } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { type BreadcrumbItem } from '@/types'
import NBAGameController from '@/actions/App/Http/Controllers/NBA/GameController'

interface Player {
    id: number
    team_id: number
    espn_id: string
    first_name: string
    last_name: string
    full_name: string
    name: string
    jersey_number: string | null
    position: string | null
    height: string | null
    weight: string | null
    year: string | null
    hometown: string | null
    headshot_url: string | null
    team: {
        id: number
        name: string
        display_name: string
        abbreviation: string
        logo: string | null
    } | null
}

interface PlayerProp {
    id: number
    market: string
    line: number
    over_price: number
    under_price: number
    bookmaker: string
    game: {
        id: number
        home_team: string
        away_team: string
        date: string
        time: string
    }
}

interface PlayerStat {
    id: number
    game_id: number
    minutes_played: number | null
    field_goals_made: number | null
    field_goals_attempted: number | null
    three_point_made: number | null
    three_point_attempted: number | null
    free_throws_made: number | null
    free_throws_attempted: number | null
    rebounds_total: number | null
    rebounds: number | null
    assists: number | null
    steals: number | null
    blocks: number | null
    turnovers: number | null
    fouls: number | null
    points: number | null
    game: {
        id: number
        game_date: string | null
        status: string
        home_team_id: number
        away_team_id: number
        home_score: number | null
        away_score: number | null
        home_team: { id: number; name: string; abbreviation: string } | null
        away_team: { id: number; name: string; abbreviation: string } | null
    } | null
}

const props = defineProps<{
    player: Player
    playerProps?: PlayerProp[]
}>()

const gameLogs = ref<PlayerStat[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const breadcrumbs = computed<BreadcrumbItem[]>(() => {
    const items: BreadcrumbItem[] = [
        { title: 'NBA', href: '/nba-predictions' },
    ]
    if (props.player.team) {
        items.push({ title: props.player.team.name, href: `/nba/teams/${props.player.team.id}` })
    }
    items.push({ title: props.player.name, href: `/nba/players/${props.player.id}` })
    return items
})

const formatDate = (dateString: string | null): string => {
    if (!dateString) return '-'
    return new Date(dateString).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

const pct = (made: number | null, attempted: number | null): string => {
    if (!made || !attempted || attempted === 0) return '-'
    return (made / attempted * 100).toFixed(1)
}

const formatOdds = (odds: number): string => {
    return odds > 0 ? `+${odds}` : odds.toString()
}

const avg = (values: (number | null)[]): string => {
    const valid = values.filter((v): v is number => v !== null && v !== undefined)
    if (valid.length === 0) return '-'
    return (valid.reduce((a, b) => a + b, 0) / valid.length).toFixed(1)
}

const seasonAverages = computed(() => {
    if (gameLogs.value.length === 0) return null

    const logs = gameLogs.value
    const games = logs.length

    const sum = (fn: (s: PlayerStat) => number | null) =>
        logs.reduce((acc, s) => acc + (fn(s) ?? 0), 0)

    const totalFGM = sum(s => s.field_goals_made)
    const totalFGA = sum(s => s.field_goals_attempted)
    const total3PM = sum(s => s.three_point_made)
    const total3PA = sum(s => s.three_point_attempted)
    const totalFTM = sum(s => s.free_throws_made)
    const totalFTA = sum(s => s.free_throws_attempted)

    return {
        ppg: (sum(s => s.points) / games).toFixed(1),
        rpg: (sum(s => s.rebounds ?? s.rebounds_total) / games).toFixed(1),
        apg: (sum(s => s.assists) / games).toFixed(1),
        spg: (sum(s => s.steals) / games).toFixed(1),
        bpg: (sum(s => s.blocks) / games).toFixed(1),
        mpg: (sum(s => s.minutes_played) / games).toFixed(1),
        fgPct: totalFGA > 0 ? (totalFGM / totalFGA * 100).toFixed(1) : '-',
        threePct: total3PA > 0 ? (total3PM / total3PA * 100).toFixed(1) : '-',
        ftPct: totalFTA > 0 ? (totalFTM / totalFTA * 100).toFixed(1) : '-',
        games,
    }
})

const getOpponent = (stat: PlayerStat): { label: string; name: string } | null => {
    if (!stat.game) return null
    const isHome = stat.game.home_team_id === props.player.team_id
    const opp = isHome ? stat.game.away_team : stat.game.home_team
    return {
        label: isHome ? 'vs' : '@',
        name: opp?.abbreviation || opp?.name || '???',
    }
}

onMounted(async () => {
    try {
        const res = await fetch(`/api/v1/nba/players/${props.player.id}/stats`)
        if (res.ok) {
            const data = await res.json()
            gameLogs.value = data.data || []
        }
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Failed to load game logs'
    } finally {
        loading.value = false
    }
})
</script>

<template>
    <Head :title="player.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <!-- Player Header -->
            <div class="flex items-start gap-4">
                <img
                    v-if="player.headshot_url"
                    :src="player.headshot_url"
                    :alt="player.name"
                    class="w-24 h-24 rounded-lg object-cover"
                />
                <div v-else class="w-24 h-24 rounded-lg bg-muted flex items-center justify-center text-2xl font-bold text-muted-foreground">
                    {{ player.first_name?.[0] }}{{ player.last_name?.[0] }}
                </div>
                <div class="flex-1">
                    <h1 class="text-3xl font-bold">{{ player.name }}</h1>
                    <div class="flex items-center gap-2 text-muted-foreground mt-1">
                        <span v-if="player.jersey_number" class="font-semibold">#{{ player.jersey_number }}</span>
                        <span v-if="player.jersey_number && player.position">·</span>
                        <span v-if="player.position">{{ player.position }}</span>
                    </div>
                    <div v-if="player.team" class="mt-1">
                        <Link :href="`/nba/teams/${player.team.id}`" class="text-sm text-primary hover:underline">
                            {{ player.team.name }}
                        </Link>
                    </div>
                    <div v-if="player.height || player.weight" class="text-sm text-muted-foreground mt-1">
                        <span v-if="player.height">{{ player.height }}</span>
                        <span v-if="player.height && player.weight"> · </span>
                        <span v-if="player.weight">{{ player.weight }} lbs</span>
                    </div>
                </div>
            </div>

            <!-- Player Props -->
            <Card v-if="playerProps && playerProps.length > 0" class="mb-4">
                <CardHeader>
                    <CardTitle>Upcoming Props</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="prop in playerProps"
                            :key="prop.id"
                            class="border rounded-lg p-4 space-y-2"
                        >
                            <div class="flex items-center justify-between">
                                <span class="font-semibold text-sm">{{ prop.market }}</span>
                                <span class="text-xs text-muted-foreground">
                                    {{ prop.game.away_team }} @ {{ prop.game.home_team }}
                                </span>
                            </div>
                            <div class="flex items-center justify-center py-2">
                                <span class="text-2xl font-bold">{{ prop.line }}</span>
                            </div>
                            <div class="grid grid-cols-2 gap-2 text-sm">
                                <div class="text-center p-2 bg-muted rounded">
                                    <div class="text-xs text-muted-foreground">Over</div>
                                    <div class="font-mono font-semibold">{{ formatOdds(prop.over_price) }}</div>
                                </div>
                                <div class="text-center p-2 bg-muted rounded">
                                    <div class="text-xs text-muted-foreground">Under</div>
                                    <div class="font-mono font-semibold">{{ formatOdds(prop.under_price) }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Season Averages -->
            <div v-if="loading" class="space-y-4">
                <Skeleton class="h-32 w-full" />
                <Skeleton class="h-64 w-full" />
            </div>

            <template v-else>
                <Card v-if="seasonAverages">
                    <CardHeader>
                        <CardTitle>Season Averages ({{ seasonAverages.games }} games)</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="grid grid-cols-3 gap-4 md:grid-cols-5 lg:grid-cols-9">
                            <div class="text-center p-3 bg-muted/50 rounded-lg">
                                <div class="text-sm text-muted-foreground">PPG</div>
                                <div class="text-2xl font-bold">{{ seasonAverages.ppg }}</div>
                            </div>
                            <div class="text-center p-3 bg-muted/50 rounded-lg">
                                <div class="text-sm text-muted-foreground">RPG</div>
                                <div class="text-2xl font-bold">{{ seasonAverages.rpg }}</div>
                            </div>
                            <div class="text-center p-3 bg-muted/50 rounded-lg">
                                <div class="text-sm text-muted-foreground">APG</div>
                                <div class="text-2xl font-bold">{{ seasonAverages.apg }}</div>
                            </div>
                            <div class="text-center p-3 bg-muted/50 rounded-lg">
                                <div class="text-sm text-muted-foreground">SPG</div>
                                <div class="text-2xl font-bold">{{ seasonAverages.spg }}</div>
                            </div>
                            <div class="text-center p-3 bg-muted/50 rounded-lg">
                                <div class="text-sm text-muted-foreground">BPG</div>
                                <div class="text-2xl font-bold">{{ seasonAverages.bpg }}</div>
                            </div>
                            <div class="text-center p-3 bg-muted/50 rounded-lg">
                                <div class="text-sm text-muted-foreground">FG%</div>
                                <div class="text-2xl font-bold">{{ seasonAverages.fgPct }}</div>
                            </div>
                            <div class="text-center p-3 bg-muted/50 rounded-lg">
                                <div class="text-sm text-muted-foreground">3P%</div>
                                <div class="text-2xl font-bold">{{ seasonAverages.threePct }}</div>
                            </div>
                            <div class="text-center p-3 bg-muted/50 rounded-lg">
                                <div class="text-sm text-muted-foreground">FT%</div>
                                <div class="text-2xl font-bold">{{ seasonAverages.ftPct }}</div>
                            </div>
                            <div class="text-center p-3 bg-muted/50 rounded-lg">
                                <div class="text-sm text-muted-foreground">MPG</div>
                                <div class="text-2xl font-bold">{{ seasonAverages.mpg }}</div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Game Log -->
                <Card>
                    <CardHeader>
                        <CardTitle>Game Log</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div v-if="gameLogs.length > 0" class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b text-left text-muted-foreground">
                                        <th class="pb-2 pr-4 font-medium">Date</th>
                                        <th class="pb-2 pr-4 font-medium">OPP</th>
                                        <th class="pb-2 pr-2 font-medium text-right">MIN</th>
                                        <th class="pb-2 pr-2 font-medium text-right">PTS</th>
                                        <th class="pb-2 pr-2 font-medium text-right">REB</th>
                                        <th class="pb-2 pr-2 font-medium text-right">AST</th>
                                        <th class="pb-2 pr-2 font-medium text-right">STL</th>
                                        <th class="pb-2 pr-2 font-medium text-right">BLK</th>
                                        <th class="pb-2 pr-2 font-medium text-right">FG</th>
                                        <th class="pb-2 pr-2 font-medium text-right">3PT</th>
                                        <th class="pb-2 pr-2 font-medium text-right">FT</th>
                                        <th class="pb-2 font-medium text-right">TO</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="stat in gameLogs"
                                        :key="stat.id"
                                        class="border-b last:border-b-0 hover:bg-muted/50 transition-colors"
                                    >
                                        <td class="py-2 pr-4">
                                            <Link
                                                v-if="stat.game"
                                                :href="NBAGameController(stat.game.id)"
                                                class="text-primary hover:underline"
                                            >
                                                {{ formatDate(stat.game.game_date) }}
                                            </Link>
                                            <span v-else>-</span>
                                        </td>
                                        <td class="py-2 pr-4">
                                            <template v-if="getOpponent(stat)">
                                                <span class="text-muted-foreground">{{ getOpponent(stat)!.label }}</span>
                                                {{ getOpponent(stat)!.name }}
                                            </template>
                                            <span v-else>-</span>
                                        </td>
                                        <td class="py-2 pr-2 text-right">{{ stat.minutes_played ?? '-' }}</td>
                                        <td class="py-2 pr-2 text-right font-medium">{{ stat.points ?? '-' }}</td>
                                        <td class="py-2 pr-2 text-right">{{ stat.rebounds ?? stat.rebounds_total ?? '-' }}</td>
                                        <td class="py-2 pr-2 text-right">{{ stat.assists ?? '-' }}</td>
                                        <td class="py-2 pr-2 text-right">{{ stat.steals ?? '-' }}</td>
                                        <td class="py-2 pr-2 text-right">{{ stat.blocks ?? '-' }}</td>
                                        <td class="py-2 pr-2 text-right whitespace-nowrap">
                                            {{ stat.field_goals_made ?? 0 }}-{{ stat.field_goals_attempted ?? 0 }}
                                        </td>
                                        <td class="py-2 pr-2 text-right whitespace-nowrap">
                                            {{ stat.three_point_made ?? 0 }}-{{ stat.three_point_attempted ?? 0 }}
                                        </td>
                                        <td class="py-2 pr-2 text-right whitespace-nowrap">
                                            {{ stat.free_throws_made ?? 0 }}-{{ stat.free_throws_attempted ?? 0 }}
                                        </td>
                                        <td class="py-2 text-right">{{ stat.turnovers ?? '-' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="text-center py-8 text-muted-foreground">
                            <p>No game log data available.</p>
                        </div>
                    </CardContent>
                </Card>
            </template>
        </div>
    </AppLayout>
</template>
