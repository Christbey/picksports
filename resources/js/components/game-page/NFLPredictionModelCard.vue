<script setup lang="ts">
import { computed } from 'vue';
import { predictionWeather } from '@/lib/predictionWeather';
import { ChevronDown } from 'lucide-vue-next';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { NflPagePrediction } from '@/types';

const props = defineProps<{
    prediction: NflPagePrediction;
    gameStatus?: string;
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
const weather = computed(() =>
    predictionWeather(props.prediction.weather_forecast),
);
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
    return `${margin.value > 0 ? props.homeLabel || 'Home' : props.awayLabel || 'Away'} projected to win by ${props.formatNumber(Math.abs(margin.value), 1)} points`;
});
const winner = computed(() => {
    if (probability.value === null) return null;
    if (probability.value === 0.5) return { label: 'Even matchup', chance: 50 };
    const homeWins = probability.value > 0.5;
    return {
        label: `${homeWins ? props.homeLabel || 'Home' : props.awayLabel || 'Away'} to win`,
        chance: (homeWins ? probability.value : 1 - probability.value) * 100,
    };
});
const awayRating = computed(() => finite(props.prediction.away_elo));
const homeRating = computed(() => finite(props.prediction.home_elo));
const ratingEdge = computed(() => {
    if (awayRating.value === null || homeRating.value === null) return null;
    const difference = Math.round(homeRating.value - awayRating.value);
    if (difference === 0) return 'Even ratings';
    const team =
        difference > 0 ? props.homeLabel || 'Home' : props.awayLabel || 'Away';
    return `${team} +${Math.abs(difference)} rating edge`;
});
</script>

<template>
    <Card
        class="gap-4 rounded-2xl border-border/70 bg-background/60 py-5 shadow-none"
    >
        <CardHeader class="gap-1 px-5">
            <CardTitle class="text-base tracking-tight"
                >Pregame forecast</CardTitle
            >
            <p class="text-xs text-muted-foreground">
                {{
                    gameStatus === 'STATUS_IN_PROGRESS' ||
                    gameStatus === 'STATUS_HALFTIME'
                        ? 'Before kickoff · these estimates do not update during live play'
                        : 'Before kickoff · model estimates'
                }}
            </p>
        </CardHeader>
        <CardContent class="space-y-4 px-5">
            <p class="text-xs text-muted-foreground">
                Statistical model estimates; AI research provides context, not
                these numbers. Sportsbook lines may be blended into the
                forecast. A model lean is not an approved bet.
            </p>
            <div
                class="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-muted/30 px-4 py-3"
                aria-label="Model pick"
            >
                <div>
                    <p
                        class="text-xs font-semibold tracking-wider text-sky-700 uppercase dark:text-sky-300"
                    >
                        Model winner
                    </p>
                    <p class="mt-1 text-2xl font-semibold tracking-tight">
                        {{ winner?.label ?? 'Model winner unavailable' }}
                    </p>
                </div>
                <div
                    v-if="
                        prediction.spread_assessment?.recommendation ===
                        'pass_small_edge'
                    "
                    class="rounded-lg border border-border/60 bg-background/60 px-4 py-3"
                >
                    <p class="text-xs text-muted-foreground">Spread pick</p>
                    <p class="text-lg font-semibold">Pass</p>
                    <p class="text-xs text-muted-foreground">
                        Edge below threshold
                    </p>
                </div>
            </div>
            <div class="grid gap-5 md:grid-cols-2 md:gap-8">
                <dl class="grid grid-cols-2 divide-x divide-border/60">
                    <div class="pr-4">
                        <dt class="text-xs text-muted-foreground">
                            {{ homeLabel || 'Home' }} model spread
                        </dt>
                        <dd
                            class="mt-1 text-2xl font-semibold tracking-tight tabular-nums"
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
                            class="mt-1 text-2xl font-semibold tracking-tight tabular-nums"
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
                        Pregame win outlook
                    </p>
                    <p
                        v-if="
                            prediction.confidence_context
                                ?.probability_status ===
                            'uncalibrated_model_estimate'
                        "
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Uncalibrated model estimate
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
            <details class="group border-t border-border/60">
                <summary
                    class="flex min-h-12 w-full cursor-pointer list-none items-center justify-between gap-3 rounded-md text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring [&::-webkit-details-marker]:hidden"
                >
                    Team ratings
                    <ChevronDown
                        class="size-4 text-muted-foreground transition-transform group-open:rotate-180"
                        aria-hidden="true"
                    />
                </summary>
                <div class="space-y-4 pt-2 pb-1">
                    <p class="text-xs text-muted-foreground">
                        Pregame Elo rating
                    </p>
                    <dl class="grid grid-cols-2 gap-4">
                        <div>
                            <dt class="text-sm text-muted-foreground">
                                {{ awayLabel || 'Away' }}
                            </dt>
                            <dd
                                class="mt-1 text-2xl font-semibold tabular-nums"
                            >
                                {{
                                    awayRating === null
                                        ? '—'
                                        : formatNumber(awayRating, 0)
                                }}
                            </dd>
                        </div>
                        <div class="text-right">
                            <dt class="text-sm text-muted-foreground">
                                {{ homeLabel || 'Home' }}
                            </dt>
                            <dd
                                class="mt-1 text-2xl font-semibold tabular-nums"
                            >
                                {{
                                    homeRating === null
                                        ? '—'
                                        : formatNumber(homeRating, 0)
                                }}
                            </dd>
                        </div>
                    </dl>
                    <p class="text-sm font-medium">
                        {{ ratingEdge ?? 'Rating comparison unavailable' }}
                    </p>
                    <div class="space-y-2 text-xs text-muted-foreground">
                        <p>
                            Higher ratings indicate stronger teams. These
                            ratings are one input to the forecast.
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
                                >Win probability is an uncalibrated model
                                estimate.
                            </span>
                            Win probability reflects winning outright, not
                            covering the spread.
                        </p>
                    </div>
                </div>
            </details>
            <section
                aria-label="Game weather forecast"
                class="rounded-xl border border-border/60 px-4 py-3 text-sm"
            >
                <p class="font-medium">{{ weather.summary }}</p>
                <details class="mt-1 text-xs text-muted-foreground">
                    <summary class="cursor-pointer">
                        Forecast source and timing
                    </summary>
                    <p class="mt-1">{{ weather.detail }}</p>
                </details>
            </section>
        </CardContent>
    </Card>
</template>
