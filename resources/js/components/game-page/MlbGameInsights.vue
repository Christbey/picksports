<script setup lang="ts">
import TeamRecentGamesSection from '@/components/game-page/TeamRecentGamesSection.vue';
import type { MlbPageGame } from '@/types';

withDefaults(
    defineProps<{
        section: 'recent';
        awayLabel?: string | null;
        homeLabel?: string | null;
        awayRecord?: string;
        homeRecord?: string;
        awayRecentGames?: MlbPageGame[];
        homeRecentGames?: MlbPageGame[];
        awayTeamId?: number;
        homeTeamId?: number;
        gameHrefPrefix?: string;
    }>(),
    {
        awayLabel: null,
        homeLabel: null,
        awayRecord: '0-0',
        homeRecord: '0-0',
        awayRecentGames: () => [],
        homeRecentGames: () => [],
        gameHrefPrefix: '/mlb/games',
    },
);
</script>

<template>
    <TeamRecentGamesSection
        v-if="awayTeamId !== undefined && homeTeamId !== undefined"
        :away-label="awayLabel"
        :home-label="homeLabel"
        :away-record="awayRecord"
        :home-record="homeRecord"
        :away-recent-games="awayRecentGames"
        :home-recent-games="homeRecentGames"
        :away-team-id="awayTeamId"
        :home-team-id="homeTeamId"
        :game-href-prefix="gameHrefPrefix"
    />
</template>
