<script setup lang="ts">
import RecentGamesCard from '@/components/game-page/RecentGamesCard.vue';
import type { RecentGameListItem } from '@/types';

defineProps<{
    awayLabel?: string | null;
    homeLabel?: string | null;
    awayRecord: string;
    homeRecord: string;
    awayRecentGames: RecentGameListItem[];
    homeRecentGames: RecentGameListItem[];
    awayTeamId: number;
    homeTeamId: number;
    gameHrefPrefix: string;
}>();
</script>

<template>
    <div v-if="homeRecentGames.length > 0 || awayRecentGames.length > 0" class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <RecentGamesCard
            :title="`${awayLabel} Recent Games`"
            :record="awayRecord"
            :recent-games="awayRecentGames"
            :team-id="awayTeamId"
            :game-href-prefix="gameHrefPrefix"
        />
        <RecentGamesCard
            :title="`${homeLabel} Recent Games`"
            :record="homeRecord"
            :recent-games="homeRecentGames"
            :team-id="homeTeamId"
            :game-href-prefix="gameHrefPrefix"
        />
    </div>
</template>
