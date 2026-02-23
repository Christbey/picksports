<script setup lang="ts">
import SportTeamMetrics, { type MetricsConfig } from '@/components/SportTeamMetrics.vue';
import { formatNumber, formatBattingAverage } from '@/components/sport-team-metrics-helpers';
import MLBTeamController from '@/actions/App/Http/Controllers/MLB/TeamController';

const eraClass = (value: number | null): string => {
    if (value === null) return '';
    if (value < 3.5) return 'text-green-600 dark:text-green-400 font-semibold';
    if (value < 4.0) return 'text-green-600 dark:text-green-400';
    if (value > 5.0) return 'text-red-600 dark:text-red-400 font-semibold';
    if (value > 4.5) return 'text-red-600 dark:text-red-400';
    return '';
};

const runsClass = (value: number | null): string => {
    if (value === null) return '';
    if (value > 5) return 'text-green-600 dark:text-green-400 font-semibold';
    if (value > 4.5) return 'text-green-600 dark:text-green-400';
    if (value < 3.5) return 'text-red-600 dark:text-red-400 font-semibold';
    if (value < 4) return 'text-red-600 dark:text-red-400';
    return '';
};

const config: MetricsConfig = {
    sport: 'mlb',
    title: 'MLB Team Metrics',
    subtitle: 'Advanced metrics for MLB teams',
    apiEndpoint: '/api/v1/mlb/team-metrics',
    breadcrumbHref: '/mlb-team-metrics',
    teamLink: (id: number) => MLBTeamController(id),
    defaultSort: 'offensive_rating',
    sortOptions: [
        { key: 'offensive_rating', label: 'Offense', getValue: (m: any) => m.offensive_rating },
        { key: 'pitching_rating', label: 'Pitching', getValue: (m: any) => m.pitching_rating },
        { key: 'runs_per_game', label: 'R/G', getValue: (m: any) => m.runs_per_game },
    ],
    columns: [
        {
            label: 'R/G',
            value: (m: any) => formatNumber(m.runs_per_game, 2),
            class: (m: any) => runsClass(m.runs_per_game),
        },
        {
            label: 'RA/G',
            value: (m: any) => formatNumber(m.runs_allowed_per_game, 2),
            class: (m: any) => eraClass(m.runs_allowed_per_game),
        },
        {
            label: 'AVG',
            value: (m: any) => formatBattingAverage(m.batting_average),
        },
        {
            label: 'ERA',
            value: (m: any) => formatNumber(m.team_era, 2),
            class: (m: any) => eraClass(m.team_era),
        },
        {
            label: 'ORtg',
            value: (m: any) => formatNumber(m.offensive_rating),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'PRtg',
            value: (m: any) => formatNumber(m.pitching_rating),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'SOS',
            value: (m: any) => formatNumber(m.strength_of_schedule, 3),
            class: () => 'text-muted-foreground',
        },
    ],
};
</script>

<template>
    <SportTeamMetrics :config="config" />
</template>
