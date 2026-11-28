<script setup lang="ts">
import { computed } from 'vue';
import { formatAmericanOdds } from '@/lib/futuresPresentation';
import type { MarketOdds } from '@/types/futures';
const props = defineProps<{ quote?: MarketOdds | null }>();
const observed = computed(() => {
    const value = props.quote?.market_last_update ?? props.quote?.fetched_at;
    if (!value || !Number.isFinite(Date.parse(value))) return null;
    return new Date(value).toISOString();
});
</script>
<template>
    <span>
        {{ formatAmericanOdds(quote?.price) }}
        <span v-if="quote" class="block text-xs text-muted-foreground">
            Stored quote<span v-if="quote.stale">
                · Stale / unavailable comparison</span
            >
            <time v-if="observed" :datetime="observed" class="block">{{
                observed.replace('T', ' ').replace('.000Z', ' UTC')
            }}</time>
            <span v-else class="block">Observation time unavailable</span>
        </span>
    </span>
</template>
