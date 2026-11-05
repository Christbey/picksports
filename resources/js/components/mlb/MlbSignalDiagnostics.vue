<script setup lang="ts">
import type { SignalsPayload } from '@/types/mlb-signals';
import { labelize } from '@/lib/mlbSignalPresentation';
defineProps<{ payload: SignalsPayload | null }>();
</script>

<template>
    <details
        v-if="
            payload?.backend_review ||
            payload?.bet_filter ||
            payload?.pass_summary
        "
        class="group rounded-lg border border-border/70 bg-muted/20"
    >
        <summary
            class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 text-sm font-semibold [&::-webkit-details-marker]:hidden"
        >
            <span>Model controls and diagnostics</span>
            <span
                class="text-xs font-medium text-muted-foreground transition-transform group-open:rotate-180"
            >
                v
            </span>
        </summary>
        <div
            class="grid gap-4 border-t border-border/70 p-3 text-sm md:grid-cols-2"
        >
            <div v-if="payload?.backend_review">
                <div class="mb-2 font-medium">Model Inputs</div>
                <div class="flex flex-wrap gap-2">
                    <span
                        v-for="item in payload.backend_review.strengths.slice(
                            0,
                            8,
                        )"
                        :key="item"
                        class="rounded-md border px-2 py-1 text-xs text-muted-foreground"
                    >
                        {{ labelize(item) }}
                    </span>
                </div>
            </div>
            <div v-if="payload?.backend_review">
                <div class="mb-2 font-medium">Watch Items</div>
                <div class="flex flex-wrap gap-2">
                    <span
                        v-for="item in payload.backend_review.watch_items"
                        :key="item"
                        class="rounded-md border border-amber-300/60 px-2 py-1 text-xs text-muted-foreground"
                    >
                        {{ labelize(item) }}
                    </span>
                </div>
            </div>
            <div v-if="payload?.pass_summary">
                <div class="mb-2 font-medium">Pass Reasons</div>
                <div class="flex flex-wrap gap-2">
                    <span
                        v-for="reason in payload.pass_summary.top_reasons.slice(
                            0,
                            6,
                        )"
                        :key="reason.reason"
                        class="rounded-md border px-2 py-1 text-xs text-muted-foreground"
                    >
                        {{ labelize(reason.reason) }}:
                        {{ reason.count }}
                    </span>
                </div>
            </div>
            <div v-if="payload?.bet_filter">
                <div class="mb-2 font-medium">Risk Controls</div>
                <div class="flex flex-wrap gap-2">
                    <span
                        v-for="item in payload.bet_filter.risk_controls.slice(
                            0,
                            8,
                        )"
                        :key="item"
                        class="rounded-md border px-2 py-1 text-xs text-muted-foreground"
                    >
                        {{ labelize(item) }}
                    </span>
                </div>
            </div>
        </div>
    </details>
</template>
