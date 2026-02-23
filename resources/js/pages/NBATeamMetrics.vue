<script setup lang="ts">
import SportTeamMetrics, { type MetricsConfig } from '@/components/SportTeamMetrics.vue';
import { formatNumber, ratingClass } from '@/components/sport-team-metrics-helpers';
import NBATeamController from '@/actions/App/Http/Controllers/NBA/TeamController';

const config: MetricsConfig = {
    sport: 'nba',
    title: 'NBA Team Metrics',
    subtitle: 'Advanced efficiency metrics for NBA teams',
    apiEndpoint: '/api/v1/nba/team-metrics',
    breadcrumbHref: '/nba-team-metrics',
    teamLink: (id: number) => NBATeamController(id),
    defaultSort: 'net_rating',
    sortOptions: [
        { key: 'net_rating', label: 'Net Rating', getValue: (m: any) => m.net_rating },
        { key: 'offensive_efficiency', label: 'Offense', getValue: (m: any) => m.offensive_efficiency },
        { key: 'defensive_efficiency', label: 'Defense', getValue: (m: any) => m.defensive_efficiency, lowerIsBetter: true },
    ],
    columns: [
        {
            label: 'Record',
            value: (m: any) => (m.wins !== null ? `${m.wins}-${m.losses}` : '-'),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'ORtg',
            value: (m: any) => formatNumber(m.offensive_efficiency),
        },
        {
            label: 'DRtg',
            value: (m: any) => formatNumber(m.defensive_efficiency),
        },
        {
            label: 'Net',
            value: (m: any) => formatNumber(m.net_rating),
            class: (m: any) => ratingClass(m.net_rating, 5),
        },
        {
            label: 'Tempo',
            value: (m: any) => formatNumber(m.tempo),
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
