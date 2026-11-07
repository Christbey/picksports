<script setup lang="ts">
import { useMyBetsContext } from '@/composables/useMyBets';
const { statistics, error, hasLoaded, formatCurrency } = useMyBetsContext();
</script>
<template>
    <div
        v-if="!error || hasLoaded"
        class="grid auto-rows-min gap-4 md:grid-cols-4"
    >
        <!-- Total Bets -->
        <div
            class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 bg-gradient-to-br from-blue-500 to-blue-700 p-6 dark:border-sidebar-border"
        >
            <div class="flex h-full flex-col justify-between">
                <div class="text-sm font-medium text-white/80">Total Bets</div>
                <div class="text-5xl font-bold text-white">
                    {{ statistics.total_bets }}
                </div>
                <div class="text-xs text-white/60">All time</div>
            </div>
        </div>

        <!-- Win Rate -->
        <div
            class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 bg-gradient-to-br from-green-500 to-green-700 p-6 dark:border-sidebar-border"
        >
            <div class="flex h-full flex-col justify-between">
                <div class="text-sm font-medium text-white/80">Win Rate</div>
                <div class="text-5xl font-bold text-white">
                    {{ statistics.win_rate }}%
                </div>
                <div class="text-xs text-white/60">
                    {{ statistics.wins }}W - {{ statistics.losses }}L
                </div>
            </div>
        </div>

        <!-- Total Profit -->
        <div
            :class="[
                'relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border',
                statistics.total_profit >= 0
                    ? 'bg-gradient-to-br from-emerald-500 to-emerald-700'
                    : 'bg-gradient-to-br from-red-500 to-red-700',
            ]"
        >
            <div class="flex h-full flex-col justify-between">
                <div class="text-sm font-medium text-white/80">
                    Total Profit
                </div>
                <div class="text-4xl font-bold text-white">
                    {{ formatCurrency(statistics.total_profit) }}
                </div>
                <div class="text-xs text-white/60">Net return</div>
            </div>
        </div>

        <!-- ROI -->
        <div
            :class="[
                'relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border',
                statistics.roi >= 0
                    ? 'bg-gradient-to-br from-purple-500 to-purple-700'
                    : 'bg-gradient-to-br from-orange-500 to-orange-700',
            ]"
        >
            <div class="flex h-full flex-col justify-between">
                <div class="text-sm font-medium text-white/80">ROI</div>
                <div class="text-5xl font-bold text-white">
                    {{ statistics.roi }}%
                </div>
                <div class="text-xs text-white/60">
                    {{ formatCurrency(statistics.total_wagered) }}
                    wagered
                </div>
            </div>
        </div>
    </div>
</template>
