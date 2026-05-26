<script setup lang="ts">
import { computed } from 'vue';

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
    lockedMessage?: string;
}>();

const classificationLabel = computed(() => {
    const value = props.bettingPlan?.classification;

    return value ? value.replaceAll('_', ' ') : 'model lean';
});

const isPass = computed(() => {
    const text =
        `${props.bettingPlan?.classification || ''} ${props.bettingPlan?.bet_pick || ''}`.toLowerCase();

    return text.includes('pass') || text.includes('no bet');
});

const planToneClass = computed(() =>
    isPass.value
        ? 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900'
        : 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900',
);

const uniqueCodes = computed(() =>
    [
        ...(props.bettingPlan?.pass_reasons || []),
        ...(props.bettingPlan?.reason_codes || []),
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
                v-if="bettingPlan?.bet_pick"
                :class="[
                    'rounded-full px-3 py-1 text-xs font-semibold capitalize ring-1',
                    planToneClass,
                ]"
            >
                {{ classificationLabel }}
            </span>
        </div>

        <template v-if="bettingPlan?.bet_pick">
            <div
                class="mt-4 rounded-lg border border-border/60 bg-background/55 p-3"
            >
                <div
                    class="text-[11px] font-semibold text-muted-foreground uppercase"
                >
                    Recommendation
                </div>
                <p class="mt-1 text-base font-semibold text-foreground">
                    {{ bettingPlan.bet_pick }}
                </p>
                <p
                    v-if="bettingPlan.reasoning"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    {{ bettingPlan.reasoning }}
                </p>
            </div>

            <div
                v-if="
                    bettingPlan.for_bet?.length ||
                    bettingPlan.against_bet?.length
                "
                class="mt-3 grid gap-3 text-sm md:grid-cols-2"
            >
                <div
                    v-if="bettingPlan.for_bet?.length"
                    class="rounded-lg border border-border/60 bg-card/70 p-3"
                >
                    <p
                        class="text-xs font-semibold text-muted-foreground uppercase"
                    >
                        Supports
                    </p>
                    <ul class="mt-2 space-y-1.5 text-muted-foreground">
                        <li v-for="item in bettingPlan.for_bet" :key="item">
                            {{ item }}
                        </li>
                    </ul>
                </div>

                <div
                    v-if="bettingPlan.against_bet?.length"
                    class="rounded-lg border border-border/60 bg-card/70 p-3"
                >
                    <p
                        class="text-xs font-semibold text-muted-foreground uppercase"
                    >
                        Risk / Pushback
                    </p>
                    <ul class="mt-2 space-y-1.5 text-muted-foreground">
                        <li v-for="item in bettingPlan.against_bet" :key="item">
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
