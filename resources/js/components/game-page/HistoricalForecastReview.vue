<script setup lang="ts">
import type { BasketballHistoricalPrediction } from '@/types';
withDefaults(
    defineProps<{
        historicalPrediction: BasketballHistoricalPrediction | null;
        startLabel?: string;
    }>(),
    { startLabel: 'kickoff' },
);
</script>
<template>
    <section v-if="historicalPrediction" aria-label="Historical forecast">
        <h3>Historical model forecast</h3>
        <p>
            Home scoring margin:
            {{ historicalPrediction.predicted_spread ?? 'Unavailable' }} ·
            Total:
            {{ historicalPrediction.predicted_total ?? 'Unavailable' }}
        </p>
        <p v-if="historicalPrediction.known_before_kickoff">
            Archived forecast observed before {{ startLabel }}. Historical
            review only; no betting approval.
        </p>
        <p v-else>
            Stored historical forecast; pregame timing is unverified. Historical
            review only; no betting approval.
        </p>
    </section>
</template>
