<script setup lang="ts">
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { usePublicSportContext } from '@/composables/usePublicSport';
const {
    formatMetric,
    coverageBreakdown,
    propRecommendationTone,
    propCardTone,
    hasPlayerProps,
    featuredProps,
} = usePublicSportContext();
</script>
<template>
    <Card class="overflow-hidden border-white/10 bg-white/5 text-slate-50">
        <CardHeader>
            <CardTitle class="flex items-center justify-between gap-3">
                <span>5 Player Props</span>
                <span
                    class="rounded-full border border-violet-400/20 bg-violet-400/10 px-3 py-1 text-xs font-semibold tracking-[0.18em] text-violet-200 uppercase"
                    >Edge Signals</span
                >
            </CardTitle>
            <CardDescription class="text-slate-400">
                Highest-confidence player prop recommendations currently
                available.
            </CardDescription>
        </CardHeader>
        <CardContent class="space-y-3">
            <div
                v-if="featuredProps.length === 0"
                class="text-sm text-slate-400"
            >
                {{
                    hasPlayerProps
                        ? 'No public prop recommendations available right now.'
                        : 'Player props are not enabled for this sport.'
                }}
            </div>
            <div
                v-for="(prop, index) in featuredProps"
                :key="prop.id"
                class="rounded-2xl border border-white/10 p-4 transition hover:border-white/20 hover:bg-white/[0.06]"
                :class="[
                    propCardTone(prop.recommendation),
                    index === 0
                        ? 'shadow-[0_18px_50px_-30px_rgba(16,185,129,0.35)]'
                        : '',
                ]"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <span
                                v-if="index === 0"
                                class="rounded-full border border-amber-300/20 bg-amber-300/10 px-2 py-1 text-[10px] font-semibold tracking-[0.18em] text-amber-200 uppercase"
                                >Most clickable</span
                            >
                            <span class="font-medium text-white">{{
                                prop.player.name
                            }}</span>
                        </div>
                        <div class="mt-1 text-sm text-slate-400">
                            {{ prop.player.team }} ·
                            {{ prop.market }}
                        </div>
                    </div>
                    <span
                        class="rounded-full border px-3 py-1 text-xs font-semibold tracking-[0.16em] uppercase"
                        :class="propRecommendationTone(prop.recommendation)"
                    >
                        {{ prop.recommendation }}
                        {{ formatMetric(prop.line) }}
                    </span>
                </div>
                <div class="mt-3 grid grid-cols-3 gap-3 text-sm">
                    <div>
                        <div
                            class="text-xs tracking-[0.16em] text-slate-500 uppercase"
                        >
                            Confidence
                        </div>
                        <div class="mt-1 font-medium text-slate-100">
                            {{ formatMetric(prop.confidence) }}
                        </div>
                    </div>
                    <div>
                        <div
                            class="text-xs tracking-[0.16em] text-slate-500 uppercase"
                        >
                            Edge
                        </div>
                        <div class="mt-1 font-medium text-slate-100">
                            {{ formatMetric(prop.edge) }}
                        </div>
                    </div>
                    <div>
                        <div
                            class="text-xs tracking-[0.16em] text-slate-500 uppercase"
                        >
                            Odds
                        </div>
                        <div class="mt-1 font-medium text-slate-100">
                            {{ prop.odds > 0 ? `+${prop.odds}` : prop.odds }}
                        </div>
                    </div>
                </div>
                <div
                    v-if="coverageBreakdown(prop)"
                    class="mt-4 rounded-2xl border border-white/10 bg-slate-950/45 p-3"
                >
                    <div
                        class="flex items-center justify-between gap-3 text-xs tracking-[0.16em] text-slate-400 uppercase"
                    >
                        <span>{{ coverageBreakdown(prop)?.label }}</span>
                        <span
                            >{{ coverageBreakdown(prop)?.hits }}/{{
                                coverageBreakdown(prop)?.games
                            }}</span
                        >
                    </div>
                    <div
                        class="mt-3 flex h-3 overflow-hidden rounded-full bg-white/10"
                    >
                        <div
                            class="bg-emerald-400"
                            :style="{
                                width: `${coverageBreakdown(prop)?.hitPct ?? 0}%`,
                            }"
                        />
                        <div
                            class="bg-rose-400"
                            :style="{
                                width: `${100 - (coverageBreakdown(prop)?.hitPct ?? 0)}%`,
                            }"
                        />
                    </div>
                    <div
                        class="mt-2 flex items-center justify-between text-xs text-slate-400"
                    >
                        <span class="text-emerald-300"
                            >Covered {{ coverageBreakdown(prop)?.hits }}</span
                        >
                        <span class="text-rose-300"
                            >Missed {{ coverageBreakdown(prop)?.misses }}</span
                        >
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
