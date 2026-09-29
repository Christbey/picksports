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
    <Card class="gap-4 py-4 shadow-none">
        <CardHeader class="gap-1 px-5">
            <CardTitle class="text-base tracking-tight"
                >Prediction Model</CardTitle
            >
            <p class="text-xs text-muted-foreground">
                Pregame forecast · model estimates
            </p>
        </CardHeader>
        <CardContent class="space-y-4 px-5">
            <div class="grid gap-5 md:grid-cols-2 md:gap-8">
                <dl class="grid grid-cols-2 divide-x divide-border/60">
                    <div class="pr-4">
                        <dt class="text-xs text-muted-foreground">
                            {{ homeLabel || 'Home' }} model spread
                        </dt>
                        <dd
                            class="mt-1 text-3xl font-semibold tracking-tight tabular-nums"
                        >
                            {{ margin === null ? '—' : formatSpread(-margin) }}
                        </dd>
                        <dd class="mt-1 text-xs text-muted-foreground">
                            {{ favorite }}
                        </dd>
                    </div>
                    <div class="pl-4">
                        <dt class="text-xs text-muted-foreground">
                            Model total
                        </dt>
                        <dd
                            class="mt-1 text-3xl font-semibold tracking-tight tabular-nums"
                        >
                            {{ total === null ? '—' : formatNumber(total) }}
                        </dd>
                        <dd class="mt-1 text-xs text-muted-foreground">
                            Combined points
                        </dd>
                    </div>
                </dl>
                <div
                    class="border-t border-border/60 pt-4 md:border-t-0 md:border-l md:pt-0 md:pl-8"
                >
                    <p class="text-xs text-muted-foreground">
                        Model win probability
                    </p>
                    <div v-if="probability !== null" class="mt-2 space-y-2">
                        <div class="flex items-end justify-between gap-4">
                            <div>
                                <span
                                    class="text-xs font-medium text-muted-foreground"
                                    >{{ awayLabel || 'Away' }}</span
                                >
                                <p class="text-xl font-semibold tabular-nums">
                                    {{
                                        formatNumber(
                                            (1 - probability) * 100,
                                            1,
                                        )
                                    }}%
                                </p>
                            </div>
                            <div class="text-right">
                                <span
                                    class="text-xs font-medium text-muted-foreground"
                                    >{{ homeLabel || 'Home' }}</span
                                >
                                <p class="text-xl font-semibold tabular-nums">
                                    {{ formatNumber(probability * 100, 1) }}%
                                </p>
                            </div>
                        </div>
                        <div
                            aria-hidden="true"
                            class="flex h-1.5 gap-1 overflow-hidden rounded-full"
                        >
                            <div
                                class="rounded-full bg-sky-500"
                                :style="{
                                    width: `${(1 - probability) * 100}%`,
                                }"
                            />
                            <div
                                class="rounded-full bg-slate-400"
                                :style="{ width: `${probability * 100}%` }"
                            />
                        </div>
                    </div>
                    <p v-else class="mt-2 text-sm text-muted-foreground">
                        Win probability unavailable.
                    </p>
                </div>
            </div>
            <p
                v-if="
                    prediction.spread_assessment?.recommendation ===
                    'pass_small_edge'
                "
                class="text-xs text-muted-foreground"
            >
                Spread: pass. The model differs from the market by only
                {{
                    formatNumber(
                        Math.abs(prediction.spread_assessment.edge_points ?? 0),
                        1,
                    )
                }}
                points; the minimum is
                {{ prediction.spread_assessment.minimum_edge_points }}.
            </p>
            <details class="border-t border-border/60">
                <summary
                    class="min-h-11 cursor-pointer content-center text-xs text-muted-foreground hover:text-foreground"
                >
                    Model details &amp; team ratings
                </summary>
                <div class="space-y-3 pb-1 text-xs text-muted-foreground">
                    <p>
                        Model estimates, not sportsbook lines or an approved
                        bet.
                    </p>
                    <p
                        v-if="
                            prediction.sport === 'cfb' ||
                            prediction.sport === 'nfl'
                        "
                    >
                        <span
                            v-if="
                                prediction.confidence_context
                                    ?.probability_status ===
                                'uncalibrated_model_estimate'
                            "
                            >Win probability is an uncalibrated model estimate.
                        </span>
                        Win probability does not measure the chance of covering
                        the spread.
                    </p>
                    <dl class="flex flex-wrap gap-x-8 gap-y-2">
                        <div class="flex gap-2">
                            <dt>{{ awayLabel || 'Away' }} Elo</dt>
                            <dd
                                class="font-medium text-foreground tabular-nums"
                            >
                                {{
                                    formatNumber(finite(prediction.away_elo), 0)
                                }}
                            </dd>
                        </div>
                        <div class="flex gap-2">
                            <dt>{{ homeLabel || 'Home' }} Elo</dt>
                            <dd
                                class="font-medium text-foreground tabular-nums"
                            >
                                {{
                                    formatNumber(finite(prediction.home_elo), 0)
                                }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </details>
        </CardContent>
    </Card>
</template>
