<script setup lang="ts">
import { Sparkles } from 'lucide-vue-next';

import { useMlbDailyPicksBoardContext } from '@/composables/useMlbDailyPicksBoard';
const { payload, achievementIcon } = useMlbDailyPicksBoardContext();
</script>
<template>
    <section class="rounded-lg border bg-card p-4 shadow-sm">
        <div class="mb-3 flex items-center gap-2 font-semibold">
            <Sparkles class="h-4 w-4 text-emerald-500" />
            Board Signals
        </div>
        <div v-if="(payload?.achievements ?? []).length > 0" class="space-y-2">
            <div
                v-for="achievement in payload?.achievements ?? []"
                :key="achievement.key"
                class="flex gap-3 rounded-lg border bg-background/70 p-3"
            >
                <component
                    :is="achievementIcon(achievement.key)"
                    class="mt-0.5 h-4 w-4 shrink-0 text-emerald-500"
                />
                <div>
                    <div class="text-sm font-semibold">
                        {{ achievement.label }}
                    </div>
                    <div class="text-xs leading-5 text-muted-foreground">
                        {{ achievement.description }}
                    </div>
                </div>
            </div>
        </div>
        <div
            v-else
            class="rounded-lg border border-dashed p-3 text-sm text-muted-foreground"
        >
            Achievements unlock as the board scans and candidates qualify.
        </div>
    </section>
</template>
