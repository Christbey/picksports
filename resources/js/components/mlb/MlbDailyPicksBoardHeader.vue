<script setup lang="ts">
import { CalendarDays, RefreshCw, ShieldCheck } from 'lucide-vue-next';
import { useMlbDailyPicksBoardContext } from '@/composables/useMlbDailyPicksBoard';
const {
    payload,
    loading,
    loadPicks,
    statusStrip,
    selectedDate,
    safeMlbBoardMode,
    formatDate,
} = useMlbDailyPicksBoardContext();
</script>
<template>
    <header
        class="relative overflow-hidden rounded-lg border bg-card/95 p-4 shadow-sm md:p-5"
    >
        <div
            class="pointer-events-none absolute inset-y-0 right-0 hidden w-64 opacity-20 md:block"
        >
            <div
                class="absolute top-1/2 right-10 h-52 w-52 -translate-y-1/2 rotate-45 rounded-[2rem] border border-emerald-500/50"
            />
            <div
                class="absolute top-1/2 right-24 h-px w-56 -translate-y-1/2 bg-emerald-500/40"
            />
        </div>

        <div
            class="relative flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"
        >
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex items-center gap-2 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300"
                    >
                        <ShieldCheck class="h-3.5 w-3.5" />
                        {{ safeMlbBoardMode(payload?.mode) }}
                    </span>
                    <span
                        class="rounded-full border border-sky-500/20 bg-sky-500/10 px-2.5 py-1 text-xs font-medium text-sky-700 dark:text-sky-300"
                    >
                        Market-Aware
                    </span>
                    <span
                        class="rounded-full border border-amber-500/20 bg-amber-500/10 px-2.5 py-1 text-xs font-medium text-amber-700 dark:text-amber-300"
                    >
                        Needs Validation
                    </span>
                </div>

                <div class="mt-3 flex flex-wrap items-end gap-x-4 gap-y-1">
                    <h1 class="text-3xl font-bold tracking-normal md:text-4xl">
                        MLB Daily Board
                    </h1>
                    <div class="pb-1 text-sm font-medium text-muted-foreground">
                        {{ formatDate(payload?.date) }}
                    </div>
                </div>
                <p
                    class="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground"
                >
                    Market-aware MLB candidates, model context, and tracking
                    performance.
                </p>
            </div>

            <div class="flex w-full flex-col gap-2 sm:flex-row lg:w-auto">
                <div class="relative min-w-0 flex-1 lg:w-48 lg:flex-none">
                    <CalendarDays
                        class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="selectedDate"
                        aria-label="Daily picks date"
                        type="date"
                        class="h-10 w-full rounded-lg border bg-background pr-3 pl-9 text-sm"
                    />
                </div>
                <button
                    type="button"
                    class="inline-flex h-10 items-center justify-center gap-2 rounded-lg border bg-background px-4 text-sm font-semibold transition hover:bg-muted"
                    :disabled="loading"
                    @click="loadPicks"
                >
                    <RefreshCw class="h-4 w-4" />
                    Load
                </button>
            </div>
        </div>

        <div class="relative mt-4 grid gap-2 sm:grid-cols-4">
            <div
                v-for="item in statusStrip"
                :key="item.label"
                class="rounded-lg border bg-background/70 px-3 py-2"
            >
                <div
                    class="text-[11px] font-semibold text-muted-foreground uppercase"
                >
                    {{ item.label }}
                </div>
                <div class="mt-0.5 text-2xl font-bold" :class="item.tone">
                    {{ item.value }}
                </div>
            </div>
        </div>
    </header>
</template>
