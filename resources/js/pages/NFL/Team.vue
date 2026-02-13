<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ref, onMounted } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { type BreadcrumbItem, type Team, type Game } from '@/types'
import NFLGameController from '@/actions/App/Http/Controllers/NFL/GameController'

const props = defineProps<{
    team: Team
}>()

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NFL', href: '/nfl-predictions' },
    { title: props.team.name, href: `/nfl/teams/${props.team.id}` }
]

const recentGames = ref<Game[]>([])
const upcomingGames = ref<Game[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

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

        const gamesRes = await fetch(`/api/v1/nfl/teams/${props.team.id}/games`)

        if (gamesRes.ok) {
            const gamesData = await gamesRes.json()
            const games = gamesData.data || []

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
                <Skeleton class="h-64 w-full" />
                <Skeleton class="h-64 w-full" />
            </div>

            <template v-else>
                <Card v-if="recentGames.length > 0">
                    <CardHeader>
                        <CardTitle>Recent Games</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-2">
                            <Link
                                v-for="game in recentGames"
                                :key="game.id"
                                :href="NFLGameController(game.id)"
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
                                :href="NFLGameController(game.id)"
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
