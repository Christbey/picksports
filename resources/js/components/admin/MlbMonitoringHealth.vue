<script setup lang="ts">
import { Activity, Database, Target, Users } from 'lucide-vue-next';
import { useMlbMonitoringContext } from '@/composables/useMlbMonitoring';
const { formatPercent, freshnessLabel, data_health, summary } =
    useMlbMonitoringContext();
</script>
<template>
    <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-md border bg-card p-4">
            <Database class="h-4 w-4 text-sky-600" />
            <div class="mt-3 text-2xl font-bold tabular-nums">
                {{ data_health.pregame_safe_snapshots }}
            </div>
            <div class="text-xs text-muted-foreground">
                Pregame-safe snapshots
            </div>
            <div class="mt-2 text-xs">
                {{ freshnessLabel(data_health.snapshot_age_hours) }}
            </div>
        </div>
        <div class="rounded-md border bg-card p-4">
            <Users class="h-4 w-4 text-teal-600" />
            <div class="mt-3 text-2xl font-bold tabular-nums">
                {{ formatPercent(data_health.probable_pitcher_coverage, 0) }}
            </div>
            <div class="text-xs text-muted-foreground">
                Probable-pitcher coverage
            </div>
            <div class="mt-2 text-xs">
                {{ data_health.probable_pitchers_ready }} /
                {{ data_health.upcoming_games }} upcoming
            </div>
        </div>
        <div class="rounded-md border bg-card p-4">
            <Target class="h-4 w-4 text-violet-600" />
            <div class="mt-3 text-2xl font-bold tabular-nums">
                {{ formatPercent(data_health.market_quote_coverage, 0) }}
            </div>
            <div class="text-xs text-muted-foreground">
                Pregame quote coverage
            </div>
            <div class="mt-2 text-xs">
                {{ freshnessLabel(data_health.quote_age_hours) }}
            </div>
        </div>
        <div class="rounded-md border bg-card p-4">
            <Activity class="h-4 w-4 text-emerald-600" />
            <div class="mt-3 text-2xl font-bold tabular-nums">
                {{ summary.shadow_observations }}
            </div>
            <div class="text-xs text-muted-foreground">Live shadow outputs</div>
            <div class="mt-2 text-xs">
                {{ summary.settled_decisions }} settled decisions
            </div>
        </div>
    </section>
</template>
