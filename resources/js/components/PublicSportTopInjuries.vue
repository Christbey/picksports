<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { usePublicSportContext } from '@/composables/usePublicSport';
const { formatMetric, impactTone, injuries } = usePublicSportContext();
</script>
<template>
    <Card
        class="overflow-hidden border-white/10 bg-white/5 text-slate-50 shadow-[0_20px_60px_-35px_rgba(239,68,68,0.35)]"
    >
        <CardHeader>
            <CardTitle class="flex items-center justify-between gap-3">
                <span>Top Injuries</span>
                <span
                    class="rounded-full border border-rose-400/20 bg-rose-400/10 px-3 py-1 text-xs font-semibold tracking-[0.18em] text-rose-200 uppercase"
                    >Line Movers</span
                >
            </CardTitle>
            <CardDescription class="text-slate-400"
                >Most impactful active injuries right now.</CardDescription
            >
        </CardHeader>
        <CardContent class="space-y-3">
            <div
                v-if="injuries.top.length === 0"
                class="text-sm text-slate-400"
            >
                No active injuries available.
            </div>
            <div
                v-for="injury in injuries.top"
                :key="injury.id"
                class="rounded-2xl border border-white/10 bg-gradient-to-r from-rose-500/10 to-transparent p-4"
            >
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="font-medium text-white">
                            {{ injury.player_name }}
                        </div>
                        <div class="mt-1 text-sm text-slate-400">
                            {{ injury.team_abbreviation }}
                            <span v-if="injury.detail"
                                >· {{ injury.detail }}</span
                            >
                        </div>
                    </div>
                    <Badge :variant="impactTone(injury.impact_label)">{{
                        injury.impact_label ?? 'Low'
                    }}</Badge>
                </div>
                <div class="mt-3 flex flex-wrap gap-2 text-xs text-slate-400">
                    <span>{{ injury.status || 'Status TBD' }}</span>
                    <span v-if="injury.type">· {{ injury.type }}</span>
                    <span
                        v-if="
                            injury.impact_score !== null &&
                            injury.impact_score !== undefined
                        "
                        >· Impact {{ formatMetric(injury.impact_score) }}</span
                    >
                </div>
            </div>
        </CardContent>
    </Card>
</template>
