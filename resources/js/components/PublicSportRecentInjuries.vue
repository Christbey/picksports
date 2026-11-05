<script setup lang="ts">
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { usePublicSportContext } from '@/composables/usePublicSport';
const { formatGameDate, injuries } = usePublicSportContext();
</script>
<template>
    <Card
        class="overflow-hidden border-white/10 bg-white/5 text-slate-50 shadow-[0_20px_60px_-35px_rgba(56,189,248,0.35)]"
    >
        <CardHeader>
            <CardTitle class="flex items-center justify-between gap-3">
                <span>Recent Injury Updates</span>
                <span
                    class="rounded-full border border-sky-400/20 bg-sky-400/10 px-3 py-1 text-xs font-semibold tracking-[0.18em] text-sky-200 uppercase"
                    >Fresh Feed</span
                >
            </CardTitle>
            <CardDescription class="text-slate-400"
                >Newest active injury reports and refreshes.</CardDescription
            >
        </CardHeader>
        <CardContent class="space-y-3">
            <div
                v-if="injuries.recent.length === 0"
                class="text-sm text-slate-400"
            >
                No recent injury updates available.
            </div>
            <div
                v-for="injury in injuries.recent"
                :key="injury.id"
                class="rounded-2xl border border-white/10 bg-gradient-to-r from-sky-500/10 to-transparent p-4"
            >
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="font-medium text-white">
                            {{ injury.player_name }}
                        </div>
                        <div class="mt-1 text-sm text-slate-400">
                            {{ injury.team_abbreviation }}
                            <span v-if="injury.status"
                                >· {{ injury.status }}</span
                            >
                        </div>
                    </div>
                    <div class="text-xs text-slate-400">
                        {{
                            formatGameDate(
                                injury.source_updated_at || injury.updated_at,
                            )
                        }}
                    </div>
                </div>
                <div v-if="injury.detail" class="mt-3 text-sm text-slate-300">
                    {{ injury.detail }}
                </div>
            </div>
        </CardContent>
    </Card>
</template>
