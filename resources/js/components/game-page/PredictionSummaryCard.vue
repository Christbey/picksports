<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { PredictionSummary } from '@/types';

defineProps<{
    title?: string;
    awayLabel?: string | null;
    homeLabel?: string | null;
    prediction: PredictionSummary;
    formatNumber: (value: number | string | null | undefined, decimals?: number) => string;
    projectedLabel?: string;
    awayBarClass: string;
    homeBarClass: string;
}>();
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{ title || 'Prediction' }}</CardTitle>
        </CardHeader>
        <CardContent>
            <div class="mb-6">
                <div class="mb-2 flex items-center justify-between text-sm font-medium">
                    <span>{{ awayLabel }} {{ formatNumber(prediction.away_win_probability * 100, 0) }}%</span>
                    <span>{{ homeLabel }} {{ formatNumber(prediction.home_win_probability * 100, 0) }}%</span>
                </div>
                <div class="flex h-3 overflow-hidden rounded-full">
                    <div :class="`${awayBarClass} transition-all`" :style="{ width: `${prediction.away_win_probability * 100}%` }"></div>
                    <div :class="`${homeBarClass} transition-all`" :style="{ width: `${prediction.home_win_probability * 100}%` }"></div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="rounded-lg border p-4 text-center">
                    <div class="text-sm text-muted-foreground">Spread</div>
                    <div class="text-2xl font-bold">
                        {{ prediction.predicted_spread > 0 ? '+' : '' }}{{ formatNumber(prediction.predicted_spread) }}
                    </div>
                    <div class="mt-1 text-xs text-muted-foreground">{{ prediction.predicted_spread < 0 ? (homeLabel || 'Home') : (awayLabel || 'Away') }} favored</div>
                </div>
                <div class="rounded-lg border p-4 text-center">
                    <div class="text-sm text-muted-foreground">Total</div>
                    <div class="text-2xl font-bold">
                        {{ formatNumber(prediction.predicted_total) }}
                    </div>
                    <div class="mt-1 text-xs text-muted-foreground">{{ projectedLabel || 'Projected points' }}</div>
                </div>
                <div class="rounded-lg border p-4 text-center">
                    <div class="text-sm text-muted-foreground">Confidence</div>
                    <div class="text-2xl font-bold capitalize">
                        {{ prediction.confidence_level }}
                    </div>
                    <div v-if="prediction.confidence_score" class="mt-1 text-xs text-muted-foreground">Score: {{ formatNumber(prediction.confidence_score) }}</div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
