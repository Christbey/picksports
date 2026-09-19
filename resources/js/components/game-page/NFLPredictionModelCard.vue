<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { NflPagePrediction } from '@/types';

const props = defineProps<{
    prediction: NflPagePrediction;
    awayLabel?: string | null;
    homeLabel?: string | null;
    formatNumber: (
        value: number | string | null | undefined,
        decimals?: number,
    ) => string;
    formatSpread: (spread: number | string) => string;
}>();
const finite = (value: unknown): number | null => {
    if (typeof value !== 'number' && typeof value !== 'string') return null;
    if (typeof value === 'string' && !value.trim()) return null;
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
};
const margin = computed(() => finite(props.prediction.predicted_spread));
const total = computed(() => {
    const value = finite(props.prediction.predicted_total);
    return value !== null && value >= 0 ? value : null;
});
const probability = computed(() => {
    const value = finite(props.prediction.win_probability);
    return value !== null && value >= 0 && value <= 1 ? value : null;
});
const favorite = computed(() => {
    if (margin.value === null) return 'Unavailable';
    if (margin.value === 0) return 'Even matchup';
    return `${margin.value > 0 ? props.homeLabel || 'Home' : props.awayLabel || 'Away'} favored`;
});
</script>

<template>
    <Card>
        <CardHeader>
            <div class="ui-kicker">Forecast</div>
            <CardTitle class="tracking-tight">Prediction Model</CardTitle>
            <p class="text-xs text-muted-foreground">
                Model estimates, not sportsbook lines or an approved bet.
            </p>
            <p
                v-if="prediction.sport === 'cfb'"
                class="text-xs text-muted-foreground"
            >
                <span
                    v-if="
                        prediction.confidence_context?.probability_status ===
                        'uncalibrated_model_estimate'
                    "
                    >Win probability is an uncalibrated model estimate.
                </span>
                Win probability does not measure the chance of covering the
                spread.
            </p>
        </CardHeader>
        <CardContent class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <div
                    class="ui-surface-subtle border-primary/25 bg-primary/8 p-3 text-center"
                >
                    <div class="text-sm text-muted-foreground">
                        {{ homeLabel || 'Home' }} model spread
                    </div>
                    <div
                        class="text-2xl font-semibold tracking-tight text-primary"
                    >
                        {{ margin === null ? '—' : formatSpread(-margin) }}
                    </div>
                    <div class="mt-0.5 text-xs text-muted-foreground">
                        {{ favorite }}
                    </div>
                </div>
                <div
                    class="ui-surface-subtle border-primary/25 bg-primary/8 p-3 text-center"
                >
                    <div class="text-sm text-muted-foreground">Model total</div>
                    <div
                        class="text-2xl font-semibold tracking-tight text-primary"
                    >
                        {{ total === null ? '—' : formatNumber(total) }}
                    </div>
                    <div class="mt-0.5 text-xs text-muted-foreground">
                        Combined points
                    </div>
                </div>
            </div>
            <div v-if="probability !== null">
                <p class="mb-2 text-xs text-muted-foreground">
                    Model win probability
                </p>
                <div
                    class="mb-2 flex items-center justify-between gap-3 text-sm font-medium"
                >
                    <span
                        >{{ awayLabel || 'Away' }}
                        {{ formatNumber((1 - probability) * 100, 1) }}%</span
                    >
                    <span
                        >{{ homeLabel || 'Home' }}
                        {{ formatNumber(probability * 100, 1) }}%</span
                    >
                </div>
                <div
                    aria-hidden="true"
                    class="flex h-2 overflow-hidden rounded-full"
                >
                    <div
                        class="bg-green-500 dark:bg-green-600"
                        :style="{ width: `${(1 - probability) * 100}%` }"
                    />
                    <div
                        class="bg-green-800 dark:bg-green-400"
                        :style="{ width: `${probability * 100}%` }"
                    />
                </div>
            </div>
            <p v-else class="text-sm text-muted-foreground">
                Win probability unavailable.
            </p>
            <details class="rounded-lg border px-3">
                <summary
                    class="min-h-11 cursor-pointer content-center text-sm font-medium"
                >
                    Team ratings (Elo)
                </summary>
                <dl class="grid grid-cols-2 gap-3 pb-3 text-sm">
                    <div>
                        <dt class="text-muted-foreground">
                            {{ awayLabel || 'Away' }}
                        </dt>
                        <dd class="font-semibold">
                            {{ formatNumber(finite(prediction.away_elo), 0) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            {{ homeLabel || 'Home' }}
                        </dt>
                        <dd class="font-semibold">
                            {{ formatNumber(finite(prediction.home_elo), 0) }}
                        </dd>
                    </div>
                </dl>
            </details>
        </CardContent>
    </Card>
</template>
