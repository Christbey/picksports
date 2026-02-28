<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { RecentGameListItem } from '@/types';

defineProps<{
    title: string;
    record: string;
    recentGames: RecentGameListItem[];
    teamId: number;
    gameHrefPrefix: string;
}>();

const didTeamWin = (game: RecentGameListItem, teamId: number): boolean => {
    const isHome = game.home_team_id === teamId;
    const teamScore = isHome ? (game.home_score || 0) : (game.away_score || 0);
    const oppScore = isHome ? (game.away_score || 0) : (game.home_score || 0);
    return teamScore > oppScore;
};

const opponentAbbreviation = (game: RecentGameListItem, teamId: number): string => {
    return (game.home_team_id === teamId
        ? game.away_team?.abbreviation
        : game.home_team?.abbreviation) || '-';
};

const formattedScore = (game: RecentGameListItem, teamId: number): string => {
    const isHome = game.home_team_id === teamId;
    const teamScore = isHome ? game.home_score : game.away_score;
    const oppScore = isHome ? game.away_score : game.home_score;
    return `${teamScore}-${oppScore}`;
};
</script>

<template>
    <Card v-if="recentGames.length > 0">
        <CardHeader>
            <CardTitle>{{ title }}</CardTitle>
            <div class="text-sm text-muted-foreground">{{ record }}</div>
        </CardHeader>
        <CardContent>
            <div class="space-y-2">
                <Link
                    v-for="recentGame in recentGames"
                    :key="recentGame.id"
                    :href="`${gameHrefPrefix}/${recentGame.id}`"
                    class="block rounded-md border border-sidebar-border/70 p-3 transition-colors hover:bg-sidebar/50"
                >
                    <div class="flex items-center justify-between">
                        <div class="text-sm font-medium">
                            <span v-if="recentGame.home_team_id === teamId">vs</span>
                            <span v-else>@</span>
                            {{ opponentAbbreviation(recentGame, teamId) }}
                        </div>
                        <div class="text-sm font-semibold">
                            <span :class="didTeamWin(recentGame, teamId) ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                {{ didTeamWin(recentGame, teamId) ? 'W' : 'L' }}
                                {{ formattedScore(recentGame, teamId) }}
                            </span>
                        </div>
                    </div>
                </Link>
            </div>
        </CardContent>
    </Card>
</template>
