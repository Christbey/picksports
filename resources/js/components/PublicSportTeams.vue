<script setup lang="ts">
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { usePublicSportContext } from '@/composables/usePublicSport';
const { formatMetric, sportLabel, topTeams } = usePublicSportContext();
</script>
<template>
    <Card class="border-white/10 bg-white/5 text-slate-50">
        <CardHeader>
            <CardTitle class="flex items-center justify-between gap-3">
                <span>Top 25 Teams</span>
                <span
                    class="rounded-full border border-amber-400/20 bg-amber-400/10 px-3 py-1 text-xs font-semibold tracking-[0.18em] text-amber-200 uppercase"
                    >Power Map</span
                >
            </CardTitle>
            <CardDescription class="text-slate-400"
                >Best current team metric snapshot for
                {{ sportLabel }}.</CardDescription
            >
        </CardHeader>
        <CardContent>
            <div v-if="topTeams.length === 0" class="text-sm text-slate-400">
                No team metrics available.
            </div>
            <div v-else class="grid gap-3 md:grid-cols-2">
                <div
                    v-for="team in topTeams"
                    :key="`${team.rank}-${team.name}`"
                    class="flex items-center gap-4 rounded-2xl border border-white/10 bg-gradient-to-br from-white/[0.08] to-slate-950/50 p-4 transition hover:border-amber-300/30 hover:bg-white/[0.09]"
                >
                    <div
                        class="flex size-10 shrink-0 items-center justify-center rounded-full bg-amber-400/15 text-sm font-semibold text-amber-200"
                    >
                        {{ team.rank }}
                    </div>
                    <img
                        v-if="team.logo"
                        :src="team.logo"
                        :alt="team.name"
                        class="size-10 rounded-full bg-white/5 object-contain p-1"
                    />
                    <div
                        v-else
                        class="flex size-10 items-center justify-center rounded-full bg-white/10 text-xs font-semibold text-slate-300"
                    >
                        {{
                            team.abbreviation ||
                            team.name.slice(0, 3).toUpperCase()
                        }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium text-white">
                            {{ team.name }}
                        </div>
                        <div class="mt-1 text-xs text-slate-400">
                            {{ team.primary_metric_label }}
                            {{ formatMetric(team.primary_metric_value) }}
                            <span v-if="team.record">· {{ team.record }}</span>
                        </div>
                        <div
                            class="mt-3 h-1.5 overflow-hidden rounded-full bg-white/10"
                        >
                            <div
                                class="h-full rounded-full bg-gradient-to-r from-amber-300 via-orange-300 to-rose-300"
                                :style="{
                                    width: `${Math.min(100, Math.max(12, 100 - (team.rank - 1) * 3.5))}%`,
                                }"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
