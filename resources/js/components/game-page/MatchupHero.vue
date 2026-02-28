<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import type { GamePageGame, GamePageHrefLike, GamePageTeam } from '@/types';

const props = withDefaults(defineProps<{
    awayTeam: GamePageTeam | null;
    homeTeam: GamePageTeam | null;
    awayRecentForm?: string;
    homeRecentForm?: string;
    game: GamePageGame;
    gameStatus: string;
    formatDate: (dateString: string | null) => string;
    teamLink: (id: number) => GamePageHrefLike;
    gradientClass: string;
    venueLabel?: string | null;
    broadcastNetworks?: string[];
    extraInfoItems?: string[];
    showScoreStatuses?: string[];
    badgePulseStatuses?: string[];
    linkTeams?: boolean;
    useTeamColorGlow?: boolean;
}>(), {
    extraInfoItems: () => [],
    showScoreStatuses: () => ['STATUS_FINAL'],
    badgePulseStatuses: () => [],
    linkTeams: true,
    useTeamColorGlow: false,
});
</script>

<template>
    <div class="rounded-xl overflow-hidden text-white shadow-lg" :class="props.gradientClass">
        <div class="px-6 py-8">
            <div class="flex flex-col md:flex-row items-center justify-between gap-6">
                <component
                    :is="props.linkTeams ? Link : 'div'"
                    v-if="awayTeam"
                    :href="props.linkTeams ? teamLink(awayTeam.id) : undefined"
                    class="flex-1 flex flex-col items-center md:items-end gap-2 hover:opacity-80 transition-opacity"
                >
                    <div class="relative">
                        <div
                            v-if="props.useTeamColorGlow && awayTeam.color"
                            class="absolute inset-0 rounded-full opacity-20 blur-xl"
                            :style="{ backgroundColor: `#${awayTeam.color}` }"
                        />
                        <img
                            v-if="awayTeam.logo"
                            :src="awayTeam.logo"
                            :alt="awayTeam.name || 'Away Team'"
                            class="relative z-10 h-20 w-20 object-contain drop-shadow-lg"
                        />
                    </div>
                    <div class="text-center md:text-right">
                        <div class="text-xl md:text-2xl font-bold">{{ awayTeam.display_name || `${awayTeam.location || ''} ${awayTeam.name || ''}`.trim() || awayTeam.name || awayTeam.abbreviation || 'Away Team' }}</div>
                        <div class="text-sm text-white/70">Away</div>
                        <div v-if="awayRecentForm" class="text-xs text-white/60 mt-1">{{ awayRecentForm }}</div>
                    </div>
                </component>

                <div class="text-center min-w-[120px]">
                    <div v-if="props.showScoreStatuses.includes(game.status) && game.away_score !== undefined && game.home_score !== undefined" class="text-4xl md:text-5xl font-bold tracking-tight">
                        {{ game.away_score }} - {{ game.home_score }}
                    </div>
                    <div v-else class="text-2xl md:text-3xl font-bold text-white/70">
                        vs
                    </div>
                    <Badge class="mt-2 bg-white/20 text-white border-white/30 hover:bg-white/30" :class="{ 'animate-pulse !bg-red-600 !border-red-500': props.badgePulseStatuses.includes(game.status) }">{{ gameStatus }}</Badge>
                </div>

                <component
                    :is="props.linkTeams ? Link : 'div'"
                    v-if="homeTeam"
                    :href="props.linkTeams ? teamLink(homeTeam.id) : undefined"
                    class="flex-1 flex flex-col items-center md:items-start gap-2 hover:opacity-80 transition-opacity"
                >
                    <div class="relative">
                        <div
                            v-if="props.useTeamColorGlow && homeTeam.color"
                            class="absolute inset-0 rounded-full opacity-20 blur-xl"
                            :style="{ backgroundColor: `#${homeTeam.color}` }"
                        />
                        <img
                            v-if="homeTeam.logo"
                            :src="homeTeam.logo"
                            :alt="homeTeam.name || 'Home Team'"
                            class="relative z-10 h-20 w-20 object-contain drop-shadow-lg"
                        />
                    </div>
                    <div class="text-center md:text-left">
                        <div class="text-xl md:text-2xl font-bold">{{ homeTeam.display_name || `${homeTeam.location || ''} ${homeTeam.name || ''}`.trim() || homeTeam.name || homeTeam.abbreviation || 'Home Team' }}</div>
                        <div class="text-sm text-white/70">Home</div>
                        <div v-if="homeRecentForm" class="text-xs text-white/60 mt-1">{{ homeRecentForm }}</div>
                    </div>
                </component>
            </div>
        </div>

        <div class="bg-black/20 px-6 py-3 flex flex-wrap items-center justify-center gap-x-6 gap-y-1 text-sm text-white/80">
            <span>{{ formatDate(game.game_date) }}</span>
            <span v-for="(item, idx) in props.extraInfoItems" :key="idx">{{ item }}</span>
            <span v-if="venueLabel" class="flex items-center gap-1">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" /></svg>
                {{ venueLabel }}
            </span>
            <span v-if="broadcastNetworks && broadcastNetworks.length > 0" class="flex items-center gap-1">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6zm12.553 1.106A1 1 0 0014 8v4a1 1 0 00.553.894l2 1A1 1 0 0018 13V7a1 1 0 00-1.447-.894l-2 1z" /></svg>
                {{ broadcastNetworks.join(', ') }}
            </span>
        </div>
    </div>
</template>
