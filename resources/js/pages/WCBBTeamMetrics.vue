<script setup lang="ts">
import SportTeamMetrics, { type MetricsConfig } from '@/components/SportTeamMetrics.vue';
import { formatNumber, ratingClass } from '@/components/sport-team-metrics-helpers';
import WCBBTeamController from '@/actions/App/Http/Controllers/WCBB/TeamController';

const config: MetricsConfig = {
    sport: 'wcbb',
    title: 'WCBB Team Metrics',
    subtitle: "Advanced efficiency metrics for women's college basketball teams",
    apiEndpoint: '/api/v1/wcbb/team-metrics',
    breadcrumbHref: '/wcbb-team-metrics',
    teamLink: (id: number) => WCBBTeamController(id),
    defaultSort: 'adj_net_rating',
    hasMeetsMinimum: true,
    sortOptions: [
        { key: 'adj_net_rating', label: 'Net Rating', getValue: (m: any) => m.adj_net_rating ?? m.net_rating },
        { key: 'offensive_efficiency', label: 'Offense', getValue: (m: any) => m.adj_offensive_efficiency ?? m.offensive_efficiency },
        { key: 'defensive_efficiency', label: 'Defense', getValue: (m: any) => m.adj_defensive_efficiency ?? m.defensive_efficiency, lowerIsBetter: true },
    ],
    columns: [
        {
            label: 'GP',
            value: (m: any) => `${m.games_played}`,
            class: () => 'text-muted-foreground',
        },
        {
            label: 'AdjO',
            value: (m: any) => formatNumber(m.adj_offensive_efficiency ?? m.offensive_efficiency),
        },
        {
            label: 'AdjD',
            value: (m: any) => formatNumber(m.adj_defensive_efficiency ?? m.defensive_efficiency),
        },
        {
            label: 'AdjNet',
            value: (m: any) => formatNumber(m.adj_net_rating ?? m.net_rating),
            class: (m: any) => ratingClass(m.adj_net_rating ?? m.net_rating, 10),
        },
        {
            label: 'Tempo',
            value: (m: any) => formatNumber(m.adj_tempo ?? m.tempo),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'SOS',
            value: (m: any) => formatNumber(m.strength_of_schedule, 3),
            class: () => 'text-muted-foreground',
        },
        {
            label: 'L10 Net',
            value: (m: any) => {
                const val = formatNumber(m.rolling_net_rating);
                const count = m.rolling_games_count ? ` (${m.rolling_games_count})` : '';
                return `${val}${count}`;
            },
            class: (m: any) => ratingClass(m.rolling_net_rating, 10),
        },
        {
            label: 'Home',
            value: (m: any) => {
                const net =
                    m.home_offensive_efficiency && m.home_defensive_efficiency
                        ? formatNumber(m.home_offensive_efficiency - m.home_defensive_efficiency)
                        : '-';
                const count = m.home_games ? ` (${m.home_games})` : '';
                return `${net}${count}`;
            },
        },
        {
            label: 'Away',
            value: (m: any) => {
                const net =
                    m.away_offensive_efficiency && m.away_defensive_efficiency
                        ? formatNumber(m.away_offensive_efficiency - m.away_defensive_efficiency)
                        : '-';
                const count = m.away_games ? ` (${m.away_games})` : '';
                return `${net}${count}`;
            },
        },
    ],
};
</script>

<template>
    <SportTeamMetrics :config="config" />
</template>
