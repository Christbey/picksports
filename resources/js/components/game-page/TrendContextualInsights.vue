<script setup lang="ts">
import { useTrendsComparisonContext } from '@/composables/useTrendsComparison';
const { contextualTrendCards } = useTrendsComparisonContext();
</script>

<template>
    <section v-if="contextualTrendCards.length" aria-label="Key patterns">
        <h4 class="mb-1 text-sm font-semibold">Key patterns</h4>
        <ul
            role="list"
            aria-label="Selected historical patterns"
            class="divide-y divide-border"
        >
            <li
                v-for="insight in contextualTrendCards"
                :key="insight.key"
                class="py-2.5"
            >
                <div
                    class="mb-1 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 text-xs"
                >
                    <p class="font-medium text-muted-foreground">
                        {{ insight.label }} · {{ insight.detail }}
                    </p>
                    <p class="text-muted-foreground tabular-nums">
                        <template
                            v-if="
                                insight.sampleSize != null &&
                                insight.sampleSize > 0
                            "
                        >
                            {{ insight.sampleSize }}
                            {{ insight.sampleSize === 1 ? 'game' : 'games' }}
                        </template>
                        <template v-else>Sample size unavailable</template>
                    </p>
                </div>
                <p class="text-sm leading-relaxed">{{ insight.message }}</p>
            </li>
        </ul>
    </section>
</template>
