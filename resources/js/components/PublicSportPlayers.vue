<script setup lang="ts">
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { usePublicSportContext } from '@/composables/usePublicSport';
const { formatMetric, topPlayers } = usePublicSportContext();
</script>
<template>
    <Card class="border-white/10 bg-white/5 text-slate-50">
        <CardHeader>
            <CardTitle class="flex items-center justify-between gap-3">
                <span>Top Players</span>
                <span
                    class="rounded-full border border-emerald-400/20 bg-emerald-400/10 px-3 py-1 text-xs font-semibold tracking-[0.18em] text-emerald-200 uppercase"
                    >Stat Leaders</span
                >
            </CardTitle>
            <CardDescription class="text-slate-400"
                >Public player leaders ranked by sport-specific
                production.</CardDescription
            >
        </CardHeader>
        <CardContent class="space-y-3">
            <div v-if="topPlayers.length === 0" class="text-sm text-slate-400">
                No player leaderboard data available.
            </div>
            <div
                v-for="player in topPlayers"
                :key="`${player.player_id}-${player.name}`"
                class="flex items-center gap-3 rounded-2xl border border-white/10 bg-gradient-to-r from-emerald-400/10 to-transparent p-3"
            >
                <img
                    v-if="player.headshot"
                    :src="player.headshot"
                    :alt="player.name"
                    class="size-12 rounded-full object-cover"
                />
                <div
                    v-else
                    class="flex size-12 items-center justify-center rounded-full bg-white/10 text-xs font-semibold text-slate-300"
                >
                    {{ player.name.slice(0, 2).toUpperCase() }}
                </div>
                <div class="min-w-0 flex-1">
                    <div class="truncate font-medium text-white">
                        {{ player.name }}
                    </div>
                    <div class="mt-1 text-xs text-slate-400">
                        {{ player.team_abbreviation || 'FA' }}
                        <span v-if="player.position"
                            >· {{ player.position }}</span
                        >
                        <span v-if="player.games_played"
                            >· {{ player.games_played }} GP</span
                        >
                    </div>
                </div>
                <div class="text-right">
                    <div
                        class="text-xs tracking-[0.18em] text-slate-500 uppercase"
                    >
                        {{ player.headline_stat_label }}
                    </div>
                    <div class="text-lg font-semibold text-amber-200">
                        {{ formatMetric(player.headline_stat_value) }}
                    </div>
                    <div class="mt-1 text-xs text-slate-500">per game</div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
