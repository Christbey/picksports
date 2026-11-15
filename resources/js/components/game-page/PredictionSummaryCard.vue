<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { PredictionSummary } from '@/types';
import { computed } from 'vue';
import { normalizeTeamColor } from '@/lib/teamColor';

const props = defineProps<{
    title?: string;
    awayLabel?: string | null;
    homeLabel?: string | null;
    prediction: PredictionSummary;
    formatNumber: (
        value: number | string | null | undefined,
        decimals?: number,
    ) => string;
    projectedLabel?: string;
    awayColor?: string | null;
    homeColor?: string | null;
    awayBarClass: string;
    homeBarClass: string;
}>();

const evidence = computed(
    () => props.prediction.audit_context ?? props.prediction.evidence,
);
const reconstructed = computed(
    () => evidence.value?.run_type === 'historical_reconstruction',
);
const provisional = computed(() => evidence.value?.provisional === true);
const confidenceDisplayLabel = (prediction: PredictionSummary) =>
    reconstructed.value
        ? 'Reconstructed'
        : provisional.value
          ? 'Provisional'
          : prediction.confidence_context?.label || prediction.confidence_level;

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
    <Card class="gap-4 py-4">
        <CardHeader class="px-4 sm:px-5">
            <CardTitle class="tracking-tight">{{
                title || 'Model projections'
            }}</CardTitle>
        </CardHeader>
        <CardContent class="space-y-4 px-4 sm:px-5">
            <p
                v-if="reconstructed || provisional"
                class="border-l-2 border-border pl-3 text-sm text-muted-foreground"
            >
                <template v-if="reconstructed">
                    Reconstructed after tipoff. Reported separately from pregame
                    forecasts.
                </template>
                <template v-else>
                    Provisional forecast based on limited current-season data.
                </template>
            </p>
            <div>
                <p class="mb-2 text-xs font-medium text-muted-foreground">
                    Model win probability
                </p>
                <div
                    class="mb-2 flex items-center justify-between text-sm font-semibold tabular-nums"
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
                <div class="flex h-2 overflow-hidden rounded-full">
                    <div
                        :class="`${awayBarClass} transition-all`"
                        :style="{
                            width: `${prediction.away_win_probability * 100}%`,
                            backgroundColor: normalizeTeamColor(awayColor),
                        }"
                    ></div>
                    <div
                        :class="`${homeBarClass} transition-all`"
                        :style="{
                            width: `${prediction.home_win_probability * 100}%`,
                            backgroundColor: normalizeTeamColor(homeColor),
                        }"
                    ></div>
                </div>
            </div>

            <dl
                class="grid grid-cols-2 gap-x-3 gap-y-3 border-t border-border pt-3 sm:grid-cols-3"
            >
                <div class="min-w-0">
                    <dt class="text-xs text-muted-foreground">
                        Projected spread
                    </dt>
                    <dd
                        class="text-xl font-semibold tracking-tight tabular-nums"
                    >
                        {{ homeLabel || 'Home' }}
                        {{ formatSignedHomeSpread(prediction, formatNumber) }}
                    </dd>
                    <dd class="text-xs text-muted-foreground">
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
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs text-muted-foreground">
                        Projected total
                    </dt>
                    <dd
                        class="text-xl font-semibold tracking-tight tabular-nums"
                    >
                        {{ formatNumber(prediction.predicted_total) }}
                    </dd>
                    <dd class="text-xs text-muted-foreground">
                        {{ projectedLabel || 'Projected points' }}
                    </dd>
                </div>
                <div
                    class="col-span-2 min-w-0 border-t border-border pt-3 sm:col-span-1 sm:border-t-0 sm:pt-0"
                >
                    <dt class="text-xs text-muted-foreground">
                        {{
                            prediction.sport === 'cfb'
                                ? 'Win estimate'
                                : prediction.sport === 'nfl'
                                  ? 'Win-model confidence'
                                  : 'Model confidence'
                        }}
                    </dt>
                    <dd class="text-sm leading-7 font-semibold capitalize">
                        {{ confidenceDisplayLabel(prediction) }}
                    </dd>
                    <dd
                        v-if="prediction.confidence_score"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Score: {{ formatNumber(prediction.confidence_score) }}
                    </dd>
                    <dd
                        v-if="prediction.sport === 'cfb'"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        <span
                            v-if="
                                prediction.confidence_context
                                    ?.probability_status ===
                                'uncalibrated_model_estimate'
                            "
                            >Uncalibrated estimate.
                        </span>
                        Not a probability of covering the spread.
                    </dd>
                </div>
            </dl>
        </CardContent>
    </Card>
</template>
