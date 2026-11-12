<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { useTrendsComparisonContext } from '@/composables/useTrendsComparison';
const { contextualTrendCards } = useTrendsComparisonContext();
</script>
<template>
    <div v-if="contextualTrendCards.length > 0" class="space-y-3">
        <h4 class="text-sm font-semibold">
            Both teams · Selected historical patterns
        </h4>
        <ul
            role="list"
            aria-label="Selected historical patterns"
            class="grid gap-3 lg:grid-cols-3"
        >
            <li
                v-for="insight in contextualTrendCards"
                :key="insight.key"
                class="rounded-lg border p-3"
                :class="{
                    'border-emerald-300/70 bg-emerald-500/10 dark:border-emerald-800/60 dark:bg-emerald-500/12':
                        insight.tone === 'team' &&
                        insight.direction === 'support',
                    'border-border/70 bg-muted/25':
                        insight.tone === 'team' &&
                        insight.direction !== 'support',
                    'border-blue-300/70 bg-blue-500/10 dark:border-blue-800/60 dark:bg-blue-500/12':
                        insight.tone === 'total',
                    'border-amber-300/70 bg-amber-500/10 dark:border-amber-800/60 dark:bg-amber-500/12':
                        insight.tone === 'risk',
                }"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p
                            class="text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            {{ insight.label }}
                        </p>
                        <p class="mt-1 text-sm font-semibold">
                            {{ insight.detail }}
                        </p>
                    </div>
                    <Badge variant="outline" class="shrink-0 text-[11px]">
                        Pattern rank {{ insight.score }}/100
                    </Badge>
                </div>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <Badge
                        v-if="insight.quality"
                        variant="secondary"
                        class="text-[10px] capitalize"
                    >
                        Pattern: {{ insight.quality }}
                    </Badge>
                    <Badge
                        v-if="insight.confidence"
                        variant="outline"
                        class="text-[10px] capitalize"
                    >
                        Context:
                        {{ insight.confidence.replace('_', ' ') }}
                    </Badge>
                    <Badge
                        v-if="insight.sampleSize != null"
                        variant="outline"
                        class="text-[10px]"
                    >
                        {{ insight.sampleSize }}
                        {{ insight.sampleSize === 1 ? 'game' : 'games' }}
                    </Badge>
                </div>
                <p class="mt-2 text-sm leading-relaxed">
                    {{ insight.message }}
                </p>
            </li>
        </ul>
    </div>
</template>
