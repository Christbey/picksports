<script setup lang="ts">
import { computed } from 'vue';
import { getPredictionRecommendation } from '@/lib/predictionRecommendation';
import type { PredictionSummary } from '@/types';

type BettingPlan = {
    bet_pick?: string | null;
    reasoning?: string | null;
    classification?: string | null;
    for_bet?: string[];
    against_bet?: string[];
    pass_reasons?: string[];
    reason_codes?: string[];
};

const props = defineProps<{
    bettingPlan?: BettingPlan | null;
    prediction?: PredictionSummary | null;
    lockedMessage?: string;
}>();

const humanize = (value: string): string =>
    value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());

const unique = (values: Array<string | null | undefined>): string[] =>
    values.filter(
        (value, index, all): value is string =>
            Boolean(value) && all.indexOf(value) === index,
    );

const recommendationPick = (
    recommendation: NonNullable<PredictionSummary['recommendation']>,
): string => {
    const side = recommendation.team_name || recommendation.pick_side;
    const market = recommendation.market_type
        ? humanize(recommendation.market_type)
        : null;
    const price = recommendation.market_price;
    const priceLabel =
        typeof price === 'number' ? `${price > 0 ? '+' : ''}${price}` : null;

    return (
        [side ? humanize(String(side)) : null, market, priceLabel]
            .filter(Boolean)
            .join(' · ') || 'Public betting recommendation'
    );
};

const resolvedPlan = computed<BettingPlan | null>(() => {
    const prediction = props.prediction;
    const narrativePlan =
        props.bettingPlan ?? prediction?.narrative?.betting_plan;
    if (!prediction) return narrativePlan ?? null;

    const recommendation = getPredictionRecommendation(prediction);
    const publicSummary = prediction.public_recommendation;
    if (!recommendation && !publicSummary) return narrativePlan ?? null;

    const type =
        recommendation?.recommendation_type ?? publicSummary?.type ?? 'no_play';
    const isPregame = recommendation?.prediction_phase === 'pregame';
    const isPublicBet =
        type === 'bet' && recommendation?.is_bet === true && isPregame;
    const isPublicLean = type === 'lean' && isPregame;
    const isPublicPlay = isPublicBet || isPublicLean;
    const marketReason = prediction.market_aware_projection?.reason;
    const noBetReason = recommendation?.no_bet_reason;

    return {
        bet_pick: isPublicPlay
            ? recommendationPick(recommendation ?? {})
            : publicSummary?.label ||
              (type === 'monitor' ? 'Live monitor' : 'No bet'),
        reasoning: isPublicPlay
            ? narrativePlan?.reasoning ||
              marketReason ||
              'This is the current public recommendation after market and risk controls.'
            : marketReason ||
              (noBetReason
                  ? `No public bet: ${humanize(noBetReason)}.`
                  : 'No public bet passed the current market, risk, and promotion controls.'),
        classification: type,
        for_bet: isPublicPlay ? narrativePlan?.for_bet || [] : [],
        against_bet: isPublicPlay ? narrativePlan?.against_bet || [] : [],
        pass_reasons: unique([
            ...(publicSummary?.block_reasons || []),
            ...(recommendation?.block_reasons || []),
            noBetReason,
        ]),
        reason_codes: unique([
            ...(recommendation?.reason_codes || []),
            ...(recommendation?.risk_flags || []),
        ]),
    };
});

const classificationLabel = computed(() => {
    const value = resolvedPlan.value?.classification;

    return value ? value.replaceAll('_', ' ') : 'model lean';
});

const isPass = computed(() => {
    const text =
        `${resolvedPlan.value?.classification || ''} ${resolvedPlan.value?.bet_pick || ''}`.toLowerCase();

    return (
        !['bet', 'lean'].includes(
            resolvedPlan.value?.classification?.toLowerCase() || '',
        ) ||
        text.includes('pass') ||
        text.includes('no bet')
    );
});

const planToneClass = computed(() =>
    isPass.value
        ? 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900'
        : 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900',
);

const uniqueCodes = computed(() =>
    [
        ...(resolvedPlan.value?.pass_reasons || []),
        ...(resolvedPlan.value?.reason_codes || []),
    ].filter((code, index, codes) => code && codes.indexOf(code) === index),
);
</script>

<template>
    <section class="ui-surface p-4 md:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="ui-kicker">Betting Plan</h3>
                <p class="mt-1 text-xs text-muted-foreground">
                    Market-aware recommendation from the model and filter.
                </p>
            </div>
            <span
                v-if="resolvedPlan?.bet_pick"
                :class="[
                    'rounded-full px-3 py-1 text-xs font-semibold capitalize ring-1',
                    planToneClass,
                ]"
            >
                {{ classificationLabel }}
            </span>
        </div>

        <template v-if="resolvedPlan?.bet_pick">
            <div
                class="mt-4 rounded-lg border border-border/60 bg-background/55 p-3"
            >
                <div
                    class="text-[11px] font-semibold text-muted-foreground uppercase"
                >
                    Recommendation
                </div>
                <p class="mt-1 text-base font-semibold text-foreground">
                    {{ resolvedPlan.bet_pick }}
                </p>
                <p
                    v-if="resolvedPlan.reasoning"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    {{ resolvedPlan.reasoning }}
                </p>
            </div>

            <div
                v-if="
                    resolvedPlan.for_bet?.length ||
                    resolvedPlan.against_bet?.length
                "
                class="mt-3 grid gap-3 text-sm md:grid-cols-2"
            >
                <div
                    v-if="resolvedPlan.for_bet?.length"
                    class="rounded-lg border border-border/60 bg-card/70 p-3"
                >
                    <p
                        class="text-xs font-semibold text-muted-foreground uppercase"
                    >
                        Supports
                    </p>
                    <ul class="mt-2 space-y-1.5 text-muted-foreground">
                        <li v-for="item in resolvedPlan.for_bet" :key="item">
                            {{ item }}
                        </li>
                    </ul>
                </div>

                <div
                    v-if="resolvedPlan.against_bet?.length"
                    class="rounded-lg border border-border/60 bg-card/70 p-3"
                >
                    <p
                        class="text-xs font-semibold text-muted-foreground uppercase"
                    >
                        Risk / Pushback
                    </p>
                    <ul class="mt-2 space-y-1.5 text-muted-foreground">
                        <li
                            v-for="item in resolvedPlan.against_bet"
                            :key="item"
                        >
                            {{ item }}
                        </li>
                    </ul>
                </div>
            </div>

            <div v-if="uniqueCodes.length" class="mt-3 flex flex-wrap gap-2">
                <span
                    v-for="code in uniqueCodes"
                    :key="code"
                    class="rounded-full border border-border bg-muted/60 px-2.5 py-1 text-[11px] font-medium text-muted-foreground"
                >
                    {{ code.replaceAll('_', ' ') }}
                </span>
            </div>
        </template>

        <template v-else>
            <p class="mt-3 text-sm text-foreground/90">
                {{ lockedMessage || 'Betting plan is not available yet.' }}
            </p>
            <p v-if="lockedMessage" class="mt-1 text-xs text-muted-foreground">
                Additional access may be required for this betting plan.
            </p>
            <p v-else class="mt-1 text-xs text-muted-foreground">
                Check back after the latest prediction narrative refresh.
            </p>
        </template>
    </section>
</template>
