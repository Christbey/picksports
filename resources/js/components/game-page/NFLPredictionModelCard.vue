<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { NflPagePrediction } from '@/types';

defineProps<{
    prediction: NflPagePrediction;
    awayLabel?: string | null;
    homeLabel?: string | null;
    formatNumber: (
        value: number | string | null | undefined,
        decimals?: number,
    ) => string;
    formatSpread: (spread: number | string) => string;
}>();
</script>

<template>
    <Card>
        <CardHeader>
            <div class="ui-kicker">Forecast</div>
            <CardTitle class="tracking-tight">Prediction Model</CardTitle>
        </CardHeader>
        <CardContent>
            <div class="mb-6">
                <div
                    class="mb-2 flex items-center justify-between text-sm font-medium"
                >
                    <span
                        >{{ awayLabel }}
                        {{
                            formatNumber(
                                (1 - Number(prediction.win_probability)) * 100,
                                0,
                            )
                        }}%</span
                    >
                    <span
                        >{{ homeLabel }}
                        {{
                            formatNumber(
                                Number(prediction.win_probability) * 100,
                                0,
                            )
                        }}%</span
                    >
                </div>
                <div class="flex h-3 overflow-hidden rounded-full">
                    <div
                        class="bg-green-500 transition-all dark:bg-green-600"
                        :style="{
                            width: `${(1 - Number(prediction.win_probability)) * 100}%`,
                        }"
                    ></div>
                    <div
                        class="bg-green-800 transition-all dark:bg-green-400"
                        :style="{
                            width: `${Number(prediction.win_probability) * 100}%`,
                        }"
                    ></div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="ui-surface-subtle p-3 text-center">
                    <div class="text-sm text-muted-foreground">Away ELO</div>
                    <div class="text-2xl font-semibold tracking-tight">
                        {{ formatNumber(prediction.away_elo, 0) }}
                    </div>
                    <div class="mt-0.5 text-xs text-muted-foreground">
                        {{ awayLabel }}
                    </div>
                </div>
                <div class="ui-surface-subtle p-3 text-center">
                    <div class="text-sm text-muted-foreground">Home ELO</div>
                    <div class="text-2xl font-semibold tracking-tight">
                        {{ formatNumber(prediction.home_elo, 0) }}
                    </div>
                    <div class="mt-0.5 text-xs text-muted-foreground">
                        {{ homeLabel }}
                    </div>
                </div>
                <div
                    class="ui-surface-subtle border-primary/25 bg-primary/8 p-3 text-center"
                >
                    <div class="text-sm text-muted-foreground">Spread</div>
                    <div
                        class="text-2xl font-semibold tracking-tight text-primary"
                    >
                        {{
                            prediction.predicted_spread !== undefined
                                ? formatSpread(prediction.predicted_spread)
                                : '-'
                        }}
                    </div>
                    <div class="mt-0.5 text-xs text-muted-foreground">
                        {{
                            Number(prediction.predicted_spread) > 0
                                ? homeLabel || 'Home'
                                : awayLabel || 'Away'
                        }}
                        favored
                    </div>
                </div>
                <div
                    class="ui-surface-subtle border-primary/25 bg-primary/8 p-3 text-center"
                >
                    <div class="text-sm text-muted-foreground">Win Prob</div>
                    <div
                        class="text-2xl font-semibold tracking-tight text-primary"
                    >
                        {{
                            formatNumber(
                                Number(prediction.win_probability) * 100,
                                1,
                            )
                        }}%
                    </div>
                    <div class="mt-0.5 text-xs text-muted-foreground">
                        {{ homeLabel }}
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
