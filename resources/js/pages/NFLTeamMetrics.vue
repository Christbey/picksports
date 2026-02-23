<script setup lang="ts">
import SportTeamMetrics, { type MetricsConfig } from '@/components/SportTeamMetrics.vue';
import { formatNumber, ratingClass } from '@/components/sport-team-metrics-helpers';
import NFLTeamController from '@/actions/App/Http/Controllers/NFL/TeamController';

const turnoverClass = (value: number | null): string => {
    if (value === null) return '';
    if (value > 5) return 'text-green-600 dark:text-green-400 font-semibold';
    if (value > 0) return 'text-green-600 dark:text-green-400';
    if (value < -5) return 'text-red-600 dark:text-red-400 font-semibold';
    if (value < 0) return 'text-red-600 dark:text-red-400';
    return '';
};

const config: MetricsConfig = {
    sport: 'nfl',
    title: 'NFL Team Metrics',
    subtitle: 'Advanced metrics for NFL teams',
    apiEndpoint: '/api/v1/nfl/team-metrics',
    breadcrumbHref: '/nfl-team-metrics',
    teamLink: (id: number) => NFLTeamController(id),
    defaultSort: 'net_rating',
    sortOptions: [
        { key: 'net_rating', label: 'Net', getValue: (m: any) => m.net_rating },
        { key: 'offensive_rating', label: 'Offense', getValue: (m: any) => m.offensive_rating },
        { key: 'defensive_rating', label: 'Defense', getValue: (m: any) => m.defensive_rating, lowerIsBetter: true },
    ],
    columns: [
        {
            label: 'PPG',
            value: (m: any) => formatNumber(m.points_per_game),
        },
        {
            label: 'PA/G',
            value: (m: any) => formatNumber(m.points_allowed_per_game),
        },
        {
            label: 'Net',
            value: (m: any) => formatNumber(m.net_rating),
            class: (m: any) => ratingClass(m.net_rating, 5),
        },
        {
            label: 'YPG',
            value: (m: any) => formatNumber(m.yards_per_game),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'YA/G',
            value: (m: any) => formatNumber(m.yards_allowed_per_game),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'TO+/-',
            value: (m: any) => formatNumber(m.turnover_differential, 0),
            class: (m: any) => turnoverClass(m.turnover_differential),
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
