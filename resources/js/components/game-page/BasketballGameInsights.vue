<script setup lang="ts">
import BasketballBoxScoreCard from '@/components/game-page/BasketballBoxScoreCard.vue';
import TeamMetricsComparisonCard from '@/components/game-page/TeamMetricsComparisonCard.vue';
import TopPerformersCard from '@/components/game-page/TopPerformersCard.vue';
import type { TeamMetricsData, TopPerformer } from '@/types';

interface BasketballTeamStats {
    field_goals_made: number;
    field_goals_attempted: number;
    three_point_made: number;
    three_point_attempted: number;
    free_throws_made: number;
    free_throws_attempted: number;
    rebounds: number;
    assists: number;
    turnovers: number;
    steals: number;
    blocks: number;
    points_in_paint?: number | null;
    fast_break_points?: number | null;
}

withDefaults(
    defineProps<{
        gameStatus: string;
        awayLabel?: string | null;
        homeLabel?: string | null;
        homeTeamId: number;
        homeTeamStats?: BasketballTeamStats | null;
        awayTeamStats?: BasketballTeamStats | null;
        topPerformers?: TopPerformer[];
        performersMode?: 'list' | 'table';
        homeMetrics?: TeamMetricsData | null;
        awayMetrics?: TeamMetricsData | null;
        metricsTitle?: string;
        boxScoreLayout?: 'grid' | 'table';
        showRecap?: boolean;
        showMetrics?: boolean;
        getBetterValue: (
            homeValue: number,
            awayValue: number,
            lowerIsBetter?: boolean,
        ) => 'home' | 'away' | null;
        formatNumber: (
            value: number | string | null | undefined,
            decimals?: number,
        ) => string;
    }>(),
    {
        awayLabel: null,
        homeLabel: null,
        homeTeamStats: null,
        awayTeamStats: null,
        topPerformers: () => [],
        performersMode: 'list',
        homeMetrics: null,
        awayMetrics: null,
        metricsTitle: 'Team Stats Comparison',
        boxScoreLayout: 'grid',
        showRecap: true,
        showMetrics: true,
    },
);
</script>

<template>
    <template v-if="showRecap">
        <BasketballBoxScoreCard
            v-if="gameStatus === 'STATUS_FINAL' && homeTeamStats && awayTeamStats"
            :away-label="awayLabel"
            :home-label="homeLabel"
            :away-team-stats="awayTeamStats"
            :home-team-stats="homeTeamStats"
            :get-better-value="getBetterValue"
            :layout="boxScoreLayout"
        />

        <TopPerformersCard
            v-if="gameStatus === 'STATUS_FINAL' && topPerformers.length > 0"
            :performers="topPerformers"
            :mode="performersMode"
            :home-team-id="homeTeamId"
            :home-label="homeLabel"
            :away-label="awayLabel"
        />
    </template>

    <TeamMetricsComparisonCard
        v-if="showMetrics && homeMetrics && awayMetrics"
        :title="metricsTitle"
        :away-label="awayLabel"
        :home-label="homeLabel"
        :away-metrics="awayMetrics"
        :home-metrics="homeMetrics"
        :format-number="formatNumber"
        :get-better-value="getBetterValue"
    />
</template>
