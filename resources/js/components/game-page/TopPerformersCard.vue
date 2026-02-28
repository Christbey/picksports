<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { TopPerformer } from '@/types';

defineProps<{
    performers: TopPerformer[];
    mode: 'list' | 'table';
    homeTeamId: number;
    homeLabel?: string | null;
    awayLabel?: string | null;
    title?: string;
}>();

const playerName = (player: TopPerformer): string => {
    if (player?.player?.name) return player.player.name;
    if (player?.player_id) return `Player #${player.player_id}`;
    return 'Unknown';
};

const teamLabel = (player: TopPerformer, homeTeamId: number, homeLabel?: string | null, awayLabel?: string | null): string => {
    if (player?.team?.abbreviation) return player.team.abbreviation;
    if (player?.team_id) return player.team_id === homeTeamId ? (homeLabel || '-') : (awayLabel || '-');
    return '-';
};

const rebounds = (player: TopPerformer): number => Number(player?.rebounds_total ?? player?.rebounds ?? 0);
const assists = (player: TopPerformer): number => Number(player?.assists ?? 0);
const points = (player: TopPerformer): number => Number(player?.points ?? 0);
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{ title || 'Top Performers' }}</CardTitle>
        </CardHeader>
        <CardContent>
            <div v-if="mode === 'table'" class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b text-sm">
                            <th class="p-2 text-left">Player</th>
                            <th class="p-2 text-center">Team</th>
                            <th class="p-2 text-center">PTS</th>
                            <th class="p-2 text-center">REB</th>
                            <th class="p-2 text-center">AST</th>
                            <th class="p-2 text-center">FG</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm">
                        <tr v-for="player in performers" :key="player.id" class="border-b">
                            <td class="p-2 font-medium">{{ playerName(player) }}</td>
                            <td class="p-2 text-center">{{ teamLabel(player, homeTeamId, homeLabel, awayLabel) }}</td>
                            <td class="p-2 text-center font-bold">{{ points(player) }}</td>
                            <td class="p-2 text-center">{{ rebounds(player) }}</td>
                            <td class="p-2 text-center">{{ assists(player) }}</td>
                            <td class="p-2 text-center">{{ player.field_goals_made || 0 }}-{{ player.field_goals_attempted || 0 }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="space-y-3">
                <div v-for="player in performers" :key="player.id" class="flex items-center justify-between rounded-lg bg-muted/50 p-3">
                    <div class="flex-1">
                        <div class="font-medium">{{ playerName(player) }}</div>
                        <div class="text-sm text-muted-foreground">{{ teamLabel(player, homeTeamId, homeLabel, awayLabel) }}</div>
                    </div>
                    <div class="flex gap-4 text-sm">
                        <div class="text-center">
                            <div class="font-bold">{{ points(player) }}</div>
                            <div class="text-xs text-muted-foreground">PTS</div>
                        </div>
                        <div class="text-center">
                            <div class="font-bold">{{ rebounds(player) }}</div>
                            <div class="text-xs text-muted-foreground">REB</div>
                        </div>
                        <div class="text-center">
                            <div class="font-bold">{{ assists(player) }}</div>
                            <div class="text-xs text-muted-foreground">AST</div>
                        </div>
                        <div class="text-center">
                            <div class="font-bold">{{ player.field_goals_made || 0 }}-{{ player.field_goals_attempted || 0 }}</div>
                            <div class="text-xs text-muted-foreground">FG</div>
                        </div>
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
