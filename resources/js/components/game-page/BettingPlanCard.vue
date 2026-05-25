<script setup lang="ts">
type BettingPlan = {
    bet_pick?: string | null;
    reasoning?: string | null;
    classification?: string | null;
    for_bet?: string[];
    against_bet?: string[];
    pass_reasons?: string[];
    reason_codes?: string[];
};

defineProps<{
    bettingPlan?: BettingPlan | null;
    lockedMessage?: string;
}>();
</script>

<template>
    <section class="ui-surface p-5 md:p-6">
        <h3 class="ui-kicker">Betting Plan</h3>

        <template v-if="bettingPlan?.bet_pick">
            <p
                v-if="bettingPlan.classification"
                class="mt-3 inline-flex text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {{ bettingPlan.classification.replaceAll('_', ' ') }}
            </p>
            <p class="mt-3 text-foreground/95">
                <span class="font-semibold">Bet:</span>
                {{ bettingPlan.bet_pick }}
            </p>
            <p v-if="bettingPlan.reasoning" class="mt-1 text-foreground/90">
                <span class="font-semibold">Why:</span>
                {{ bettingPlan.reasoning }}
            </p>

            <div
                v-if="
                    bettingPlan.for_bet?.length ||
                    bettingPlan.against_bet?.length
                "
                class="mt-4 grid gap-4 text-sm md:grid-cols-2"
            >
                <div v-if="bettingPlan.for_bet?.length">
                    <p class="font-semibold text-foreground">Supports</p>
                    <ul class="mt-2 space-y-1 text-muted-foreground">
                        <li v-for="item in bettingPlan.for_bet" :key="item">
                            {{ item }}
                        </li>
                    </ul>
                </div>

                <div v-if="bettingPlan.against_bet?.length">
                    <p class="font-semibold text-foreground">Pushes Back</p>
                    <ul class="mt-2 space-y-1 text-muted-foreground">
                        <li v-for="item in bettingPlan.against_bet" :key="item">
                            {{ item }}
                        </li>
                    </ul>
                </div>
            </div>

            <div
                v-if="
                    bettingPlan.pass_reasons?.length ||
                    bettingPlan.reason_codes?.length
                "
                class="mt-4 flex flex-wrap gap-2"
            >
                <span
                    v-for="code in [
                        ...(bettingPlan.pass_reasons || []),
                        ...(bettingPlan.reason_codes || []),
                    ]"
                    :key="code"
                    class="rounded border border-border px-2 py-1 text-xs text-muted-foreground"
                >
                    {{ code.replaceAll('_', ' ') }}
                </span>
            </div>
        </template>

        <template v-else>
            <p class="mt-2 text-sm text-foreground/90">
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
