<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { Game } from '@/types';

defineProps<{
    title: string;
    games: Game[];
    teamId?: number;
    gameLink: (id: number) => any;
    getGameResult?: (game: Game) => string | null;
    getOpponent: (game: Game, isHome: boolean) => any;
    formatDate: (date: string | null) => string;
    showScore?: boolean;
}>();
</script>

<template>
    <Card v-if="games.length > 0">
        <CardHeader>
            <CardTitle>{{ title }}</CardTitle>
        </CardHeader>
        <CardContent>
            <div class="space-y-2">
                <Link
                    v-for="game in games"
                    :key="game.id"
                    :href="gameLink(game.id)"
                    class="flex items-center justify-between p-3 rounded-lg hover:bg-muted/50 transition-colors"
                >
                    <div class="flex items-center gap-3 flex-1">
                        <span
                            v-if="showScore && getGameResult"
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
                        <span v-if="showScore" class="text-sm font-medium">
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
</template>
