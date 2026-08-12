<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { PredictionSummary } from '@/types';

defineProps<{
    title?: string;
    awayLabel?: string | null;
    homeLabel?: string | null;
    prediction: PredictionSummary;
    formatNumber: (
        value: number | string | null | undefined,
        decimals?: number,
    ) => string;
    projectedLabel?: string;
    awayBarClass: string;
    homeBarClass: string;
}>();

const confidenceDisplayLabel = (prediction: PredictionSummary) =>
    prediction.confidence_context?.label || prediction.confidence_level;

const homeSpreadLine = (prediction: PredictionSummary): number =>
    -Number(prediction.predicted_spread);

const favoriteLabel = (
    prediction: PredictionSummary,
    home?: string | null,
    away?: string | null,
): string => {
    const spread = Number(prediction.predicted_spread);

    if (Math.abs(spread) < 0.05) return 'No team';

    return spread > 0 ? home || 'Home' : away || 'Away';
};

const formatSignedHomeSpread = (
    prediction: PredictionSummary,
    formatter: (
        value: number | string | null | undefined,
        decimals?: number,
    ) => string,
): string => {
    const line = homeSpreadLine(prediction);

    if (Number.isNaN(line)) return '-';
    if (Math.abs(line) < 0.05) return 'PK';

    return `${line > 0 ? '+' : ''}${formatter(line)}`;
};
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="tracking-tight">{{
                title || 'Prediction'
            }}</CardTitle>
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
                                prediction.away_win_probability * 100,
                                0,
                            )
                        }}%</span
                    >
                    <span
                        >{{ homeLabel }}
                        {{
                            formatNumber(
                                prediction.home_win_probability * 100,
                                0,
                            )
                        }}%</span
                    >
                </div>
                <div class="flex h-3 overflow-hidden rounded-full">
                    <div
                        :class="`${awayBarClass} transition-all`"
                        :style="{
                            width: `${prediction.away_win_probability * 100}%`,
                        }"
                    ></div>
                    <div
                        :class="`${homeBarClass} transition-all`"
                        :style="{
                            width: `${prediction.home_win_probability * 100}%`,
                        }"
                    ></div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="ui-surface-subtle p-4 text-center">
                    <div class="text-sm text-muted-foreground">Home Spread</div>
                    <div class="text-2xl font-semibold tracking-tight">
                        {{ formatSignedHomeSpread(prediction, formatNumber) }}
                    </div>
                    <div class="mt-1 text-xs text-muted-foreground">
                        <template
                            v-if="
                                Math.abs(Number(prediction.predicted_spread)) <
                                0.05
                            "
                        >
                            Pick'em
                        </template>
                        <template v-else>
                            {{
                                favoriteLabel(prediction, homeLabel, awayLabel)
                            }}
                            favored
                        </template>
                    </div>
                </div>
                <div class="ui-surface-subtle p-4 text-center">
                    <div class="text-sm text-muted-foreground">Total</div>
                    <div class="text-2xl font-semibold tracking-tight">
                        {{ formatNumber(prediction.predicted_total) }}
                    </div>
                    <div class="mt-1 text-xs text-muted-foreground">
                        {{ projectedLabel || 'Projected points' }}
                    </div>
                </div>
                <div class="ui-surface-subtle p-4 text-center">
                    <div class="text-sm text-muted-foreground">Confidence</div>
                    <div
                        class="text-2xl font-semibold tracking-tight capitalize"
                    >
                        {{ confidenceDisplayLabel(prediction) }}
                    </div>
                    <div
                        v-if="prediction.confidence_score"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Score: {{ formatNumber(prediction.confidence_score) }}
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
