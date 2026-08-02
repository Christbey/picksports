<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    formatBattingAverage,
    formatNumber,
} from '@/components/sport-team-metrics-helpers';
import type { MlbTeamMetricsData } from '@/types';

type MetricRow = {
    label: string;
    away: number | null;
    home: number | null;
    lowerIsBetter?: boolean;
    format: (value: number | null) => string;
};

const props = defineProps<{
    awayLabel?: string | null;
    homeLabel?: string | null;
    awayMetrics: MlbTeamMetricsData;
    homeMetrics: MlbTeamMetricsData;
}>();

const metricRows = computed<MetricRow[]>(() => [
    {
        label: 'Runs / game',
        away: props.awayMetrics.runs_per_game ?? null,
        home: props.homeMetrics.runs_per_game ?? null,
        format: (value) => formatNumber(value, 2),
    },
    {
        label: 'Runs allowed / game',
        away: props.awayMetrics.runs_allowed_per_game ?? null,
        home: props.homeMetrics.runs_allowed_per_game ?? null,
        lowerIsBetter: true,
        format: (value) => formatNumber(value, 2),
    },
    {
        label: 'Run differential',
        away: props.awayMetrics.run_differential_per_game ?? null,
        home: props.homeMetrics.run_differential_per_game ?? null,
        format: (value) => formatNumber(value, 2),
    },
    {
        label: 'OPS',
        away: props.awayMetrics.ops ?? null,
        home: props.homeMetrics.ops ?? null,
        format: formatBattingAverage,
    },
    {
        label: 'ERA',
        away: props.awayMetrics.team_era ?? null,
        home: props.homeMetrics.team_era ?? null,
        lowerIsBetter: true,
        format: (value) => formatNumber(value, 2),
    },
    {
        label: 'WHIP',
        away: props.awayMetrics.whip ?? null,
        home: props.homeMetrics.whip ?? null,
        lowerIsBetter: true,
        format: (value) => formatNumber(value, 3),
    },
    {
        label: 'Offense+',
        away: props.awayMetrics.offensive_rating ?? null,
        home: props.homeMetrics.offensive_rating ?? null,
        format: (value) => formatNumber(value, 1),
    },
    {
        label: 'Pitching+',
        away: props.awayMetrics.pitching_rating ?? null,
        home: props.homeMetrics.pitching_rating ?? null,
        format: (value) => formatNumber(value, 1),
    },
    {
        label: 'Recent form',
        away: props.awayMetrics.recent_form_rating ?? null,
        home: props.homeMetrics.recent_form_rating ?? null,
        format: (value) => formatNumber(value, 2),
    },
    {
        label: 'Availability Elo',
        away: props.awayMetrics.injury_adjusted_team_rating ?? null,
        home: props.homeMetrics.injury_adjusted_team_rating ?? null,
        format: (value) => formatNumber(value, 1),
    },
    {
        label: 'Schedule Elo',
        away: props.awayMetrics.strength_of_schedule ?? null,
        home: props.homeMetrics.strength_of_schedule ?? null,
        format: (value) => formatNumber(value, 1),
    },
    {
        label: 'Schedule fatigue',
        away: props.awayMetrics.rest_travel_fatigue ?? null,
        home: props.homeMetrics.rest_travel_fatigue ?? null,
        lowerIsBetter: true,
        format: (value) => formatNumber(value, 2),
    },
]);

const recordLabel = (metrics: MlbTeamMetricsData): string =>
    metrics.record_label ||
    (metrics.wins !== null &&
    metrics.wins !== undefined &&
    metrics.losses !== null &&
    metrics.losses !== undefined
        ? `${metrics.wins}-${metrics.losses}`
        : '-');

const betterSide = (row: MetricRow): 'away' | 'home' | null => {
    if (row.away === null || row.home === null || row.away === row.home) {
        return null;
    }

    if (row.lowerIsBetter) {
        return row.away < row.home ? 'away' : 'home';
    }

    return row.away > row.home ? 'away' : 'home';
};
</script>

<template>
    <Card>
        <CardHeader class="gap-1">
            <div class="ui-kicker">Team Strength</div>
            <CardTitle class="text-lg tracking-tight">
                Latest Season Metrics
            </CardTitle>
            <p class="text-xs text-muted-foreground">
                {{ awayLabel || 'Away' }} {{ recordLabel(awayMetrics) }} /
                {{ homeLabel || 'Home' }} {{ recordLabel(homeMetrics) }}
            </p>
        </CardHeader>
        <CardContent>
            <div class="overflow-hidden rounded-lg border border-border/70">
                <div
                    class="grid grid-cols-[minmax(72px,1fr)_minmax(130px,1.4fr)_minmax(72px,1fr)] gap-2 border-b bg-muted/35 px-3 py-2 text-xs font-semibold"
                >
                    <div class="text-right">{{ awayLabel || 'Away' }}</div>
                    <div class="text-center text-muted-foreground">Metric</div>
                    <div>{{ homeLabel || 'Home' }}</div>
                </div>
                <div
                    v-for="row in metricRows"
                    :key="row.label"
                    class="grid grid-cols-[minmax(72px,1fr)_minmax(130px,1.4fr)_minmax(72px,1fr)] items-center gap-2 border-b border-border/60 px-3 py-2.5 text-sm last:border-b-0"
                >
                    <div
                        class="text-right font-medium tabular-nums"
                        :class="
                            betterSide(row) === 'away'
                                ? 'text-emerald-700 dark:text-emerald-300'
                                : ''
                        "
                    >
                        {{ row.format(row.away) }}
                    </div>
                    <div class="text-center text-xs text-muted-foreground">
                        {{ row.label }}
                    </div>
                    <div
                        class="font-medium tabular-nums"
                        :class="
                            betterSide(row) === 'home'
                                ? 'text-emerald-700 dark:text-emerald-300'
                                : ''
                        "
                    >
                        {{ row.format(row.home) }}
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
