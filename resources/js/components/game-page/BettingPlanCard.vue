<script setup lang="ts">
type BettingPlan = {
    bet_pick?: string | null;
    reasoning?: string | null;
};

defineProps<{
    bettingPlan?: BettingPlan | null;
    lockedMessage?: string;
}>();
</script>

<template>
    <section class="ui-surface p-5 md:p-6">
        <h3 class="ui-kicker">
            Betting Plan
        </h3>

        <template v-if="bettingPlan?.bet_pick">
            <p class="mt-3 text-foreground/95">
                <span class="font-semibold">Bet:</span>
                {{ bettingPlan.bet_pick }}
            </p>
            <p v-if="bettingPlan.reasoning" class="mt-1 text-foreground/90">
                <span class="font-semibold">Why:</span>
                {{ bettingPlan.reasoning }}
            </p>
        </template>

        <template v-else>
            <p class="mt-2 text-sm text-foreground/90">
                {{ lockedMessage || 'Betting plan unavailable for the current access tier.' }}
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
                Requires prediction data access to spread, win probability, and confidence score.
            </p>
        </template>
    </section>
</template>
