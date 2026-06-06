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

const hasMetricData = computed(() =>
    [
        props.awayMetrics.offensive_rating,
        props.homeMetrics.offensive_rating,
        props.awayMetrics.defensive_rating,
        props.homeMetrics.defensive_rating,
        props.awayMetrics.net_rating,
        props.homeMetrics.net_rating,
        props.awayMetrics.pace,
        props.homeMetrics.pace,
    ].some(hasValue),
);
</script>

<template>
    <Card v-if="hasMetricData">
        <CardHeader>
            <CardTitle class="tracking-tight">{{ title }}</CardTitle>
        </CardHeader>
        <CardContent>
            <div class="space-y-3 rounded-xl border border-border/70 p-3">
                <div
                    class="grid grid-cols-7 gap-2 border-b pb-2 text-sm font-semibold"
                >
                    <div class="col-span-2 text-right text-foreground/90">
                        {{ awayLabel }}
                    </div>
                    <div class="col-span-3 text-center text-muted-foreground">
                        Metric
                    </div>
                    <div class="col-span-2 text-left text-foreground/90">
                        {{ homeLabel }}
                    </div>
                </div>

                <div class="grid grid-cols-7 items-center gap-2">
                    <div
                        class="col-span-2 text-right font-medium"
                        :class="
                            getBetterValue(
                                homeMetrics.offensive_rating,
                                awayMetrics.offensive_rating,
                            ) === 'away'
                                ? 'text-green-600 dark:text-green-400'
                                : ''
                        "
                    >
                        {{ formatNumber(awayMetrics.offensive_rating) }}
                    </div>
                    <div
                        class="col-span-3 text-center text-sm text-muted-foreground"
                    >
                        Offensive Rating
                    </div>
                    <div
                        class="col-span-2 text-left font-medium"
                        :class="
                            getBetterValue(
                                homeMetrics.offensive_rating,
                                awayMetrics.offensive_rating,
                            ) === 'home'
                                ? 'text-green-600 dark:text-green-400'
                                : ''
                        "
                    >
                        {{ formatNumber(homeMetrics.offensive_rating) }}
                    </div>
                </div>

                <div class="grid grid-cols-7 items-center gap-2">
                    <div
                        class="col-span-2 text-right font-medium"
                        :class="
                            getBetterValue(
                                homeMetrics.defensive_rating,
                                awayMetrics.defensive_rating,
                                true,
                            ) === 'away'
                                ? 'text-green-600 dark:text-green-400'
                                : ''
                        "
                    >
                        {{ formatNumber(awayMetrics.defensive_rating) }}
                    </div>
                    <div
                        class="col-span-3 text-center text-sm text-muted-foreground"
                    >
                        Defensive Rating
                    </div>
                    <div
                        class="col-span-2 text-left font-medium"
                        :class="
                            getBetterValue(
                                homeMetrics.defensive_rating,
                                awayMetrics.defensive_rating,
                                true,
                            ) === 'home'
                                ? 'text-green-600 dark:text-green-400'
                                : ''
                        "
                    >
                        {{ formatNumber(homeMetrics.defensive_rating) }}
                    </div>
                </div>

                <div class="grid grid-cols-7 items-center gap-2">
                    <div
                        class="col-span-2 text-right font-medium"
                        :class="
                            getBetterValue(
                                homeMetrics.net_rating,
                                awayMetrics.net_rating,
                            ) === 'away'
                                ? 'text-green-600 dark:text-green-400'
                                : ''
                        "
                    >
                        {{ formatNumber(awayMetrics.net_rating) }}
                    </div>
                    <div
                        class="col-span-3 text-center text-sm text-muted-foreground"
                    >
                        Net Rating
                    </div>
                    <div
                        class="col-span-2 text-left font-medium"
                        :class="
                            getBetterValue(
                                homeMetrics.net_rating,
                                awayMetrics.net_rating,
                            ) === 'home'
                                ? 'text-green-600 dark:text-green-400'
                                : ''
                        "
                    >
                        {{ formatNumber(homeMetrics.net_rating) }}
                    </div>
                </div>

                <div class="grid grid-cols-7 items-center gap-2">
                    <div class="col-span-2 text-right font-medium">
                        {{ formatNumber(awayMetrics.pace) }}
                    </div>
                    <div
                        class="col-span-3 text-center text-sm text-muted-foreground"
                    >
                        Pace
                    </div>
                    <div class="col-span-2 text-left font-medium">
                        {{ formatNumber(homeMetrics.pace) }}
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
