<script setup lang="ts">
import { Activity } from 'lucide-vue-next';

import { useMlbDailyPicksBoardContext } from '@/composables/useMlbDailyPicksBoard';
const { payload, formatRecord, formatHitRate, safeTrackingCopy } =
    useMlbDailyPicksBoardContext();
</script>
<template>
    <section class="rounded-lg border bg-card p-4 shadow-sm">
        <div class="mb-3 flex items-center gap-2 font-semibold">
            <Activity class="h-4 w-4 text-sky-500" />
            Tracking Performance
        </div>
        <div class="space-y-3 text-sm">
            <div class="flex items-center justify-between gap-3">
                <span class="text-muted-foreground">Top Picks</span>
                <span
                    class="rounded-full border px-2 py-0.5 text-xs font-semibold text-muted-foreground"
                >
                    Tracking
                </span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-muted-foreground">Last 7 days</span>
                <span class="font-semibold">{{
                    formatRecord(payload?.performance_summary.last_7_days)
                }}</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-muted-foreground">Last 30 days</span>
                <span class="font-semibold">{{
                    formatRecord(payload?.performance_summary.last_30_days)
                }}</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-muted-foreground">Hit rate</span>
                <span class="font-semibold">{{
                    formatHitRate(payload?.performance_summary.last_30_days)
                }}</span>
            </div>
        </div>
        <div
            v-if="payload?.performance_summary.sample_warning"
            class="mt-4 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs leading-5 text-amber-700 dark:text-amber-300"
        >
            Small sample.
            {{ safeTrackingCopy(payload.performance_summary.sample_warning) }}
        </div>
        <p class="mt-3 text-xs leading-5 text-muted-foreground">
            {{ safeTrackingCopy(payload?.performance_summary.mode_note) }}
        </p>
    </section>
</template>
