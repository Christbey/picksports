<script setup lang="ts">
import { Gauge } from 'lucide-vue-next';

import { useMlbDailyPicksBoardContext } from '@/composables/useMlbDailyPicksBoard';
const { boardHealth, healthRows, healthBarClass } =
    useMlbDailyPicksBoardContext();
</script>
<template>
    <section class="rounded-lg border bg-card p-4 shadow-sm">
        <div class="mb-3 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 font-semibold">
                <Gauge class="h-4 w-4 text-emerald-500" />
                Board Health
            </div>
            <span
                class="rounded-full border px-2.5 py-1 text-xs font-semibold text-muted-foreground"
            >
                {{ boardHealth?.status?.replaceAll('_', ' ') ?? 'pending' }}
            </span>
        </div>

        <div class="space-y-3">
            <div
                v-for="item in healthRows"
                :key="item.label"
                class="rounded-lg border bg-background/70 p-3"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex gap-2">
                        <component
                            :is="item.icon"
                            class="mt-0.5 h-4 w-4 text-muted-foreground"
                        />
                        <div>
                            <div class="text-sm font-semibold">
                                {{ item.label }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ item.detail }}
                            </div>
                        </div>
                    </div>
                    <div class="text-right text-sm font-bold">
                        {{ item.value }}
                    </div>
                </div>
                <div
                    v-if="item.percent && item.percent > 0"
                    class="mt-3 h-1.5 rounded-full bg-muted"
                >
                    <div
                        class="h-full rounded-full"
                        :class="healthBarClass(item.tone)"
                        :style="{
                            width: `${Math.min(item.percent * 100, 100)}%`,
                        }"
                    />
                </div>
            </div>
        </div>

        <p class="mt-3 text-xs leading-5 text-muted-foreground">
            {{
                boardHealth?.message ??
                'Board health will populate after the daily pick scan runs.'
            }}
        </p>
    </section>
</template>
