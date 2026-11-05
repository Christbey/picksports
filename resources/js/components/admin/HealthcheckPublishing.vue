<script setup lang="ts">
import { useHealthchecksContext } from '@/composables/useHealthchecks';
const { labelize, ai_publishing } = useHealthchecksContext();
</script>
<template>
    <div
        class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
    >
        <div
            class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between"
        >
            <div>
                <p
                    class="text-xs font-semibold tracking-[0.18em] text-muted-foreground uppercase"
                >
                    AI Publishing Review
                </p>
                <h2 class="mt-2 text-xl font-semibold">
                    {{ ai_publishing.total }} analyzed pick<span
                        v-if="ai_publishing.total !== 1"
                        >s</span
                    >
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Guardrail mode:
                    <span class="font-medium">
                        {{
                            labelize(ai_publishing.enforcement.mode ?? 'shadow')
                        }}
                    </span>
                </p>
            </div>
            <div
                v-if="Object.keys(ai_publishing.decisions).length > 0"
                class="flex flex-wrap gap-2"
            >
                <span
                    v-for="(count, decision) in ai_publishing.decisions"
                    :key="decision"
                    class="rounded-full border border-sidebar-border px-3 py-1 text-xs font-medium"
                >
                    {{ labelize(String(decision)) }}:
                    {{ count }}
                </span>
            </div>
        </div>

        <p
            v-if="ai_publishing.total === 0"
            class="mt-4 rounded-lg border border-sidebar-border bg-sidebar-accent px-4 py-3 text-sm text-muted-foreground"
        >
            No AI publishing reviews found for this sport today. Run the daily
            AI prediction command for the selected sport to populate guardrail
            data.
        </p>
        <div
            v-else-if="ai_publishing.needs_attention.length > 0"
            class="mt-4 space-y-3"
        >
            <div
                v-for="item in ai_publishing.needs_attention"
                :key="`${item.sport}-${item.matchup}-${item.decision}`"
                class="rounded-lg border border-sidebar-border bg-sidebar-accent px-4 py-3"
            >
                <div
                    class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between"
                >
                    <div>
                        <p class="text-sm font-semibold">
                            {{ item.sport }}
                            {{ item.matchup }}
                        </p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ item.summary }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs font-medium">
                        <span
                            class="rounded-full bg-yellow-100 px-2 py-1 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300"
                        >
                            {{ labelize(item.decision) }}
                            to
                            {{ labelize(item.publishable_classification) }}
                        </span>
                    </div>
                </div>
                <div
                    class="mt-3 flex flex-wrap gap-2 text-xs text-muted-foreground"
                >
                    <span
                        class="rounded-full bg-white px-2 py-1 dark:bg-sidebar"
                    >
                        Freshness:
                        {{ labelize(item.freshness_status) }}
                    </span>
                    <span
                        class="rounded-full bg-white px-2 py-1 dark:bg-sidebar"
                    >
                        Market:
                        {{ labelize(item.market_status) }}
                    </span>
                    <span
                        class="rounded-full bg-white px-2 py-1 dark:bg-sidebar"
                    >
                        Model:
                        {{ labelize(item.model_status) }}
                    </span>
                </div>
                <div
                    v-if="item.required_actions.length > 0"
                    class="mt-3 flex flex-wrap gap-2"
                >
                    <span
                        v-for="action in item.required_actions"
                        :key="action"
                        class="rounded-lg border border-sidebar-border bg-white px-3 py-1 font-mono text-xs dark:bg-sidebar"
                    >
                        {{ action }}
                    </span>
                </div>
            </div>
        </div>
        <p
            v-else
            class="mt-4 rounded-lg bg-sidebar-accent px-4 py-3 text-sm text-muted-foreground"
        >
            Reviews are present, and no downgraded, held, or blocked picks need
            attention for this filter today.
        </p>
    </div>
</template>
