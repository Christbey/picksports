<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { TeamMetricsData } from '@/types';

const props = defineProps<{
    title: string;
    awayLabel?: string | null;
    homeLabel?: string | null;
    awayMetrics: TeamMetricsData;
    homeMetrics: TeamMetricsData;
    formatNumber: (
        value: number | string | null | undefined,
        decimals?: number,
    ) => string;
    getBetterValue: (
        homeValue: number,
        awayValue: number,
        lowerIsBetter?: boolean,
    ) => 'home' | 'away' | null;
}>();

const hasValue = (value: number | string | null | undefined): boolean =>
    value !== null && value !== undefined && value !== '';

const metrics = [
    { key: 'offensive_rating', label: 'Offensive rating', direction: 'higher' },
    { key: 'defensive_rating', label: 'Defensive rating', direction: 'lower' },
    { key: 'net_rating', label: 'Net rating', direction: 'higher' },
    { key: 'pace', label: 'Pace', direction: null },
] as const;

const rows = computed(() =>
    metrics
        .map(({ key, label, direction }) => {
            const away = props.awayMetrics[key];
            const home = props.homeMetrics[key];
            const better =
                direction && hasValue(away) && hasValue(home)
                    ? props.getBetterValue(home, away, direction === 'lower')
                    : null;

            return { key, label, away, home, better };
        })
        .filter(({ away, home }) => hasValue(away) || hasValue(home)),
);
</script>

<template>
    <Card v-if="rows.length" class="gap-3 overflow-hidden pt-4 pb-0">
        <CardHeader class="px-4 sm:px-5">
            <CardTitle class="tracking-tight">{{ title }}</CardTitle>
        </CardHeader>
        <CardContent class="px-0">
            <table
                class="w-full border-t border-border text-sm tabular-nums"
                :aria-label="title"
            >
                <thead class="bg-muted/50 text-xs text-muted-foreground">
                    <tr>
                        <th
                            scope="col"
                            class="px-4 py-2 text-left font-medium sm:px-5"
                        >
                            Metric
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-right font-medium"
                        >
                            {{ awayLabel || 'Away' }}
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-2 text-right font-medium sm:px-5"
                        >
                            {{ homeLabel || 'Home' }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.key"
                        class="border-t border-border"
                    >
                        <th
                            scope="row"
                            class="px-4 py-2.5 text-left font-medium sm:px-5"
                        >
                            {{ row.label }}
                        </th>
                        <td
                            class="px-3 py-2.5 text-right"
                            :class="{
                                'font-semibold text-green-700 dark:text-green-400':
                                    row.better === 'away',
                            }"
                        >
                            {{
                                hasValue(row.away)
                                    ? formatNumber(row.away)
                                    : '—'
                            }}
                        </td>
                        <td
                            class="px-4 py-2.5 text-right sm:px-5"
                            :class="{
                                'font-semibold text-green-700 dark:text-green-400':
                                    row.better === 'home',
                            }"
                        >
                            {{
                                hasValue(row.home)
                                    ? formatNumber(row.home)
                                    : '—'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </CardContent>
    </Card>
</template>
