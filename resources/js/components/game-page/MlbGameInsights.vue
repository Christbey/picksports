<script setup lang="ts">
import MlbTeamMetricsComparisonCard from '@/components/game-page/MlbTeamMetricsComparisonCard.vue';
import TeamRecentGamesSection from '@/components/game-page/TeamRecentGamesSection.vue';
import type { MlbPageGame, MlbTeamMetricsData } from '@/types';

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
        awayMetrics?: MlbTeamMetricsData | null;
        homeMetrics?: MlbTeamMetricsData | null;
    }>(),
    {
        awayLabel: null,
        homeLabel: null,
        awayRecord: '0-0',
        homeRecord: '0-0',
        awayRecentGames: () => [],
        homeRecentGames: () => [],
        gameHrefPrefix: '/mlb/games',
        awayMetrics: null,
        homeMetrics: null,
    },
);
</script>

<template>
    <div class="space-y-4">
        <MlbTeamMetricsComparisonCard
            v-if="awayMetrics && homeMetrics"
            :away-label="awayLabel"
            :home-label="homeLabel"
            :away-metrics="awayMetrics"
            :home-metrics="homeMetrics"
        />
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
    </div>
</template>
