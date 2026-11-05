<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {} from '@/routes';
import { usePublicSportContext } from '@/composables/usePublicSport';
const {
    formatMetric,
    formatHomeSpreadLine,
    formatGameDate,
    predictionBarWidth,
    featuredPredictions,
} = usePublicSportContext();
</script>
<template>
    <Card class="overflow-hidden border-white/10 bg-white/5 text-slate-50">
        <CardHeader>
            <CardTitle class="flex items-center justify-between gap-3">
                <span>5 Predictions</span>
                <span
                    class="rounded-full border border-sky-400/20 bg-sky-400/10 px-3 py-1 text-xs font-semibold tracking-[0.18em] text-sky-200 uppercase"
                    >Model Board</span
                >
            </CardTitle>
            <CardDescription class="text-slate-400"
                >Best current prediction snapshots on the
                board.</CardDescription
            >
        </CardHeader>
        <CardContent class="space-y-3">
            <div
                v-if="featuredPredictions.length === 0"
                class="text-sm text-slate-400"
            >
                No predictions available.
            </div>
            <div
                v-for="(prediction, index) in featuredPredictions"
                :key="prediction.id"
                class="rounded-2xl border border-white/10 p-4 transition hover:border-sky-300/30 hover:bg-white/[0.06]"
                :class="
                    index === 0
                        ? 'bg-gradient-to-br from-sky-400/18 via-white/[0.06] to-transparent shadow-[0_18px_50px_-30px_rgba(56,189,248,0.45)]'
                        : 'bg-gradient-to-br from-sky-500/10 via-white/[0.04] to-transparent'
                "
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <span
                                v-if="index === 0"
                                class="rounded-full border border-sky-300/20 bg-sky-300/10 px-2 py-1 text-[10px] font-semibold tracking-[0.18em] text-sky-200 uppercase"
                                >Featured</span
                            >
                            <span class="font-medium text-white">{{
                                prediction.matchup
                            }}</span>
                        </div>
                        <div class="mt-1 text-sm text-slate-400">
                            {{ formatGameDate(prediction.game_date) }}
                        </div>
                    </div>
                    <Badge
                        variant="secondary"
                        class="border border-white/10 bg-white/10 text-white"
                        >{{ prediction.pick }}</Badge
                    >
                </div>
                <div class="mt-3 grid grid-cols-3 gap-3 text-sm">
                    <div>
                        <div
                            class="text-xs tracking-[0.16em] text-slate-500 uppercase"
                        >
                            Home Win%
                        </div>
                        <div class="mt-1 font-medium text-slate-100">
                            {{ formatMetric(prediction.home_win_probability) }}%
                        </div>
                    </div>
                    <div>
                        <div
                            class="text-xs tracking-[0.16em] text-slate-500 uppercase"
                        >
                            Home Spread
                        </div>
                        <div class="mt-1 font-medium text-slate-100">
                            {{
                                formatHomeSpreadLine(
                                    prediction.predicted_spread,
                                )
                            }}
                        </div>
                    </div>
                    <div>
                        <div
                            class="text-xs tracking-[0.16em] text-slate-500 uppercase"
                        >
                            Confidence
                        </div>
                        <div class="mt-1 font-medium text-slate-100">
                            {{ formatMetric(prediction.confidence_score) }}
                        </div>
                    </div>
                </div>
                <div class="mt-4">
                    <div
                        class="flex items-center justify-between text-[11px] tracking-[0.18em] text-slate-500 uppercase"
                    >
                        <span>Model edge</span>
                        <span
                            >{{
                                formatMetric(prediction.home_win_probability)
                            }}% home win</span
                        >
                    </div>
                    <div
                        class="mt-2 h-2 overflow-hidden rounded-full bg-white/10"
                    >
                        <div
                            class="h-full rounded-full bg-gradient-to-r from-sky-300 to-cyan-400"
                            :style="{
                                width: predictionBarWidth(prediction),
                            }"
                        />
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
