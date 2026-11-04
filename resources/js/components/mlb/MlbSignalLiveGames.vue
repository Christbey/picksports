<script setup lang="ts">
import { Activity } from 'lucide-vue-next';
import type { SignalRow } from '@/types/mlb-signals';
import {
    formatPercent,
    formatNumber,
    formatLiveScore,
    labelize,
} from '@/lib/mlbSignalPresentation';
defineProps<{ liveRows: SignalRow[] }>();
</script>

<template>
    <div
        v-if="liveRows.length > 0"
        class="rounded-lg border border-sky-500/30 bg-sky-500/5 p-3"
    >
        <div class="mb-3 flex items-center gap-2">
            <Activity class="h-4 w-4 text-sky-600" />
            <div class="text-sm font-semibold">Live Monitor</div>
        </div>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <div
                v-for="row in liveRows.slice(0, 4)"
                :key="`live-${row.game_id}`"
                class="rounded-lg border border-border/80 bg-card/70 p-3"
            >
                <div class="mb-2 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-semibold">
                            {{ row.matchup }}
                        </div>
                        <div class="truncate text-xs text-muted-foreground">
                            {{ formatLiveScore(row) }}
                        </div>
                    </div>
                    <span
                        class="shrink-0 rounded-full border px-2 py-1 text-xs font-medium"
                        :class="
                            row.is_stale
                                ? 'border-amber-500/50 text-amber-700 dark:text-amber-300'
                                : 'border-sky-500/50 text-sky-700 dark:text-sky-300'
                        "
                    >
                        {{ labelize(row.signal) }}
                    </span>
                </div>
                <div class="grid gap-2 text-xs sm:grid-cols-3">
                    <div class="rounded-md bg-muted/40 px-2 py-1">
                        <div class="text-muted-foreground">Live WP</div>
                        <div class="font-medium">
                            {{ formatPercent(row.live_win_probability) ?? '-' }}
                        </div>
                    </div>
                    <div class="rounded-md bg-muted/40 px-2 py-1">
                        <div class="text-muted-foreground">Spread</div>
                        <div class="font-medium">
                            {{ formatNumber(row.live_predicted_spread) }}
                        </div>
                    </div>
                    <div class="rounded-md bg-muted/40 px-2 py-1">
                        <div class="text-muted-foreground">Total</div>
                        <div class="font-medium">
                            {{ formatNumber(row.live_predicted_total) }}
                        </div>
                    </div>
                </div>
                <div class="mt-2 text-xs text-muted-foreground">
                    {{ row.live_outs_remaining ?? '-' }} outs remaining
                </div>
            </div>
        </div>
    </div>
</template>
