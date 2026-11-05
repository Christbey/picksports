<script setup lang="ts">
import {
    Ban,
    CircleDollarSign,
    Clock3,
    GitCompare,
    ShieldCheck,
} from 'lucide-vue-next';
import { useMlbMonitoringContext } from '@/composables/useMlbMonitoring';
const { formatPercent, formatNumber, summary } = useMlbMonitoringContext();
</script>
<template>
    <section class="grid grid-cols-2 gap-3 lg:grid-cols-6">
        <div class="rounded-md border p-3">
            <GitCompare class="h-4 w-4 text-sky-600" />
            <div class="mt-2 text-xl font-bold">
                {{ summary.decisions }}
            </div>
            <div class="text-xs text-muted-foreground">Decisions</div>
        </div>
        <div class="rounded-md border p-3">
            <CircleDollarSign class="h-4 w-4 text-emerald-600" />
            <div class="mt-2 text-xl font-bold">
                {{ summary.tracking_bets }}
            </div>
            <div class="text-xs text-muted-foreground">Shadow bets</div>
        </div>
        <div class="rounded-md border p-3">
            <Ban class="h-4 w-4 text-amber-600" />
            <div class="mt-2 text-xl font-bold">
                {{ summary.no_bets }}
            </div>
            <div class="text-xs text-muted-foreground">No bets</div>
        </div>
        <div class="rounded-md border p-3">
            <Clock3 class="h-4 w-4 text-slate-600" />
            <div class="mt-2 text-xl font-bold">
                {{ summary.pending_decisions }}
            </div>
            <div class="text-xs text-muted-foreground">Pending</div>
        </div>
        <div class="rounded-md border p-3">
            <CircleDollarSign class="h-4 w-4 text-teal-600" />
            <div class="mt-2 text-xl font-bold">
                {{ formatPercent(summary.roi) }}
            </div>
            <div class="text-xs text-muted-foreground">Settled ROI</div>
        </div>
        <div class="rounded-md border p-3">
            <ShieldCheck class="h-4 w-4 text-violet-600" />
            <div class="mt-2 text-xl font-bold">
                {{ formatNumber(summary.average_clv, 3) }}
            </div>
            <div class="text-xs text-muted-foreground">Average CLV</div>
        </div>
    </section>
</template>
