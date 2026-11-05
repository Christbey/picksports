<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { isConditionalPattern } from '@/lib/trendContext';
import { useTrendsComparisonContext } from '@/composables/useTrendsComparison';
const {
    homeView,
    awayView,
    allTrendCategories,
    signalsForCategory,
    totalForCategory,
    formatCategoryName,
    isLockedCategory,
    formatTierName,
    getRequiredTier,
    awayLabel,
    homeLabel,
} = useTrendsComparisonContext();
</script>
<template>
    <details v-if="allTrendCategories.length" class="rounded-lg border p-3">
        <summary
            class="min-h-11 cursor-pointer content-center text-sm font-semibold"
        >
            All patterns · both teams ({{ allTrendCategories.length }}
            categories)
        </summary>
        <p class="my-2 text-xs text-muted-foreground">
            Selected highlights exclude explicitly conditional in-game
            observations. All recorded patterns remain available here; ranks are
            not betting probabilities.
        </p>
        <details
            v-for="category in allTrendCategories"
            :key="category"
            class="group mt-2 rounded-lg border border-border/70 bg-card"
        >
            <summary
                class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 [&::-webkit-details-marker]:hidden"
            >
                <div class="min-w-0">
                    <h4 class="truncate text-sm font-semibold">
                        {{ formatCategoryName(category) }}
                    </h4>
                    <p class="text-xs text-muted-foreground">
                        {{ awayLabel || 'Away' }}
                        {{ signalsForCategory(awayView, category) }}
                        / {{ homeLabel || 'Home' }}
                        {{ signalsForCategory(homeView, category) }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <Badge variant="outline" class="text-[11px]">
                        {{ totalForCategory(awayView, homeView, category) }}
                        signals
                    </Badge>
                    <span
                        class="text-xs text-muted-foreground transition-transform group-open:rotate-180"
                    >
                        v
                    </span>
                </div>
            </summary>

            <div
                v-if="isLockedCategory(category)"
                class="mx-3 mb-3 rounded-lg border border-zinc-200/70 bg-zinc-50 py-4 text-center dark:border-zinc-800 dark:bg-zinc-900/70"
            >
                <div class="text-sm text-muted-foreground">
                    Upgrade to
                    {{ formatTierName(getRequiredTier(category)) }}
                    to unlock this trend
                </div>
            </div>

            <div
                v-else
                class="grid gap-3 border-t border-border/70 p-3 md:grid-cols-2"
            >
                <div class="rounded-lg border border-border/70 bg-muted/25 p-3">
                    <div class="mb-2 flex items-center justify-between">
                        <div class="text-sm font-medium text-muted-foreground">
                            {{ awayLabel }}
                        </div>
                        <span class="text-xs text-muted-foreground"
                            >{{
                                signalsForCategory(awayView, category)
                            }}
                            signals</span
                        >
                    </div>
                    <ul
                        v-if="awayView?.trends?.[category]?.length"
                        class="space-y-1 text-sm"
                    >
                        <li
                            v-for="(trend, idx) in awayView.trends[category]"
                            :key="idx"
                            class="flex items-start gap-2"
                        >
                            <span
                                class="mt-1 h-1.5 w-1.5 rounded-full bg-zinc-500"
                            />
                            <span
                                >{{ trend
                                }}<span
                                    v-if="isConditionalPattern(category, trend)"
                                    class="mt-1 block text-xs text-amber-600"
                                    >In-game condition—not standalone pregame
                                    evidence.</span
                                ></span
                            >
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        No trends available
                    </p>
                </div>
                <div class="rounded-lg border border-border/70 bg-muted/25 p-3">
                    <div class="mb-2 flex items-center justify-between">
                        <div class="text-sm font-medium text-muted-foreground">
                            {{ homeLabel }}
                        </div>
                        <span class="text-xs text-muted-foreground"
                            >{{
                                signalsForCategory(homeView, category)
                            }}
                            signals</span
                        >
                    </div>
                    <ul
                        v-if="homeView?.trends?.[category]?.length"
                        class="space-y-1 text-sm"
                    >
                        <li
                            v-for="(trend, idx) in homeView.trends[category]"
                            :key="idx"
                            class="flex items-start gap-2"
                        >
                            <span
                                class="mt-1 h-1.5 w-1.5 rounded-full bg-zinc-500"
                            />
                            <span
                                >{{ trend
                                }}<span
                                    v-if="isConditionalPattern(category, trend)"
                                    class="mt-1 block text-xs text-amber-600"
                                    >In-game condition—not standalone pregame
                                    evidence.</span
                                ></span
                            >
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        No trends available
                    </p>
                </div>
            </div>
        </details>
    </details>
</template>
