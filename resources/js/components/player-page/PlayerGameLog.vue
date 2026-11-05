<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type {
    PlayerStat,
    GameLogColumn,
    Player,
    SportPlayerShellConfig,
} from '@/types/player-page';
import { formatDate, getPlayerOpponent } from '@/lib/playerPagePresentation';
const props = defineProps<{
    gameLogs: PlayerStat[];
    gameLogColumns: GameLogColumn[];
    playerData: Player | null;
    error: string | null;
    config: SportPlayerShellConfig;
}>();
const getOpponent = (stat: PlayerStat) =>
    getPlayerOpponent(stat, props.playerData);
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Game Log</CardTitle>
        </CardHeader>
        <CardContent>
            <div v-if="error" class="mb-3 text-sm text-destructive">
                {{ error }}
            </div>
            <div
                v-if="gameLogs.length > 0"
                class="overflow-x-auto rounded-xl border border-border/70"
            >
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b bg-muted/35 text-left text-muted-foreground"
                        >
                            <th class="pr-4 pb-2 font-medium">Date</th>
                            <th class="pr-4 pb-2 font-medium">OPP</th>
                            <th
                                v-for="column in gameLogColumns"
                                :key="column.label"
                                class="pr-2 pb-2 text-right font-medium"
                            >
                                {{ column.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="stat in gameLogs"
                            :key="stat.id"
                            class="border-b transition-colors last:border-b-0 hover:bg-muted/40"
                        >
                            <td class="py-2 pr-4">
                                <Link
                                    v-if="stat.game && config.gameLink"
                                    :href="config.gameLink(stat.game.id)"
                                    class="text-primary hover:underline"
                                >
                                    {{ formatDate(stat.game.game_date) }}
                                </Link>
                                <span v-else>{{
                                    formatDate(stat.game?.game_date ?? null)
                                }}</span>
                            </td>
                            <td class="py-2 pr-4">
                                <template v-if="getOpponent(stat)">
                                    <span class="text-muted-foreground">{{
                                        getOpponent(stat)!.label
                                    }}</span>
                                    {{ getOpponent(stat)!.name }}
                                </template>
                                <span v-else>-</span>
                            </td>
                            <td
                                v-for="column in gameLogColumns"
                                :key="column.label"
                                :class="
                                    column.className ?? 'py-2 pr-2 text-right'
                                "
                            >
                                {{ column.value(stat) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="py-8 text-center text-muted-foreground">
                <p>No game log data available.</p>
            </div>
        </CardContent>
    </Card>
</template>
