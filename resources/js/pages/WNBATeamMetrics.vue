<script setup lang="ts">
import SportTeamMetrics, { type MetricsConfig } from '@/components/SportTeamMetrics.vue';
import { formatNumber, formatPercent, ratingClass } from '@/components/sport-team-metrics-helpers';
import WNBATeamController from '@/actions/App/Http/Controllers/WNBA/TeamController';

const config: MetricsConfig = {
    sport: 'wnba',
    title: 'WNBA Team Metrics',
    subtitle: 'Advanced efficiency metrics for WNBA teams',
    apiEndpoint: '/api/v1/wnba/team-metrics',
    breadcrumbHref: '/wnba-team-metrics',
    teamLink: (id: number) => WNBATeamController(id),
    defaultSort: 'net_rating',
    sortOptions: [
        { key: 'net_rating', label: 'Net Rating', getValue: (m: any) => m.net_rating },
        { key: 'offensive_rating', label: 'Offense', getValue: (m: any) => m.offensive_rating },
        { key: 'defensive_rating', label: 'Defense', getValue: (m: any) => m.defensive_rating, lowerIsBetter: true },
        { key: 'true_shooting_percentage', label: 'TS%', getValue: (m: any) => m.true_shooting_percentage },
    ],
    columns: [
        {
            label: 'ORtg',
            value: (m: any) => formatNumber(m.offensive_rating),
        },
        {
            label: 'DRtg',
            value: (m: any) => formatNumber(m.defensive_rating),
        },
        {
            label: 'Net',
            value: (m: any) => formatNumber(m.net_rating),
            class: (m: any) => ratingClass(m.net_rating, 5),
        },
        {
            label: 'Pace',
            value: (m: any) => formatNumber(m.pace),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'eFG%',
            value: (m: any) => formatPercent(m.effective_field_goal_percentage),
        },
        {
            label: 'TO%',
            value: (m: any) => formatPercent(m.turnover_percentage),
        },
        {
            label: 'OREB%',
            value: (m: any) => formatPercent(m.offensive_rebound_percentage),
        },
        {
            label: 'FTR',
            value: (m: any) => formatPercent(m.free_throw_rate),
        },
        {
            label: 'TS%',
            value: (m: any) => formatPercent(m.true_shooting_percentage),
            class: () => 'font-medium',
        },
    ],
};
</script>

<template>
    <SportTeamMetrics :config="config" />
</template>
