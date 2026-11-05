<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, ArrowUpRight, ShieldCheck } from 'lucide-vue-next';
import { computed } from 'vue';
import PickSportsAccountButton from '@/components/brand/PickSportsAccountButton.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import Progress from '@/components/ui/progress/Progress.vue';
import { formatPercent, formatSignedPercent } from '@/lib/homePresentation';
import { performance as performanceRoute } from '@/routes';
import type { PerformanceData } from '@/types/home';
const props = defineProps<{
    authenticated: boolean;
    canRegister: boolean;
    performance: PerformanceData;
}>();
const overall = computed(() => props.performance.overall),
    recent = computed(() => props.performance.recent.overall),
    roi = computed(() => props.performance.roi);
const hasOverallSample = computed(() => overall.value.total_predictions > 0),
    hasRecentSample = computed(() => recent.value.total_predictions > 0),
    hasRoiSample = computed(
        () => (roi.value.priced_bets ?? roi.value.total_bets) > 0,
    );
</script>
<template>
    <section
        aria-labelledby="hero-title"
        class="ps-container grid items-center gap-12 pt-14 pb-12 sm:pt-20 sm:pb-16 lg:grid-cols-[1.1fr_0.9fr] lg:gap-16 lg:pt-24 lg:pb-20"
    >
        <div>
            <div class="mb-7 flex items-center gap-3">
                <span aria-hidden="true" class="h-px w-8 bg-primary" />
                <p class="ps-eyebrow">Model + market intelligence</p>
            </div>
            <h1
                id="hero-title"
                class="max-w-2xl text-5xl leading-[1.04] font-semibold tracking-[-0.055em] text-balance sm:text-6xl xl:text-[5.5rem]"
            >
                Every game.<br /><span class="text-primary"
                    >A clearer picture.</span
                >
            </h1>
            <p class="mt-7 max-w-lg text-lg leading-8 text-muted-foreground">
                Know the probability. Know the price. Know when to pass. Pregame
                forecasts, market context, and an open record—so you can see the
                information behind the pick.
            </p>
            <div class="mt-8 flex flex-wrap gap-3">
                <PickSportsAccountButton
                    :authenticated="authenticated"
                    :can-register="canRegister"
                /><Button as-child variant="outline" size="lg"
                    ><Link :href="performanceRoute()"
                        >View verified performance<ArrowUpRight
                            aria-hidden="true"
                            class="size-4" /></Link
                ></Button>
            </div>
            <p class="mt-5 text-xs leading-5 text-muted-foreground">
                Seven sports. Measured outcomes. No guaranteed wins.
            </p>
        </div>
        <Card class="ps-feature-card gap-0 overflow-hidden py-0">
            <div
                class="flex items-center justify-between gap-3 border-b border-border bg-muted/40 px-6 py-4"
            >
                <p class="ps-eyebrow">The open record</p>
                <Badge variant="outline" class="gap-1.5 bg-card font-normal"
                    ><ShieldCheck aria-hidden="true" />{{
                        hasOverallSample
                            ? 'Graded results'
                            : 'Collecting results'
                    }}</Badge
                >
            </div>
            <CardContent class="px-6 pt-7 pb-6 sm:px-8">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-medium text-muted-foreground">
                        All-time winner accuracy
                    </h2>
                    <span class="font-mono text-[10px] text-muted-foreground"
                        >01 / RECORD</span
                    >
                </div>
                <div
                    class="mt-4 text-6xl font-semibold tracking-[-0.05em] tabular-nums sm:text-7xl"
                    data-testid="hero-accuracy"
                >
                    {{
                        formatPercent(overall.winner_accuracy, hasOverallSample)
                    }}
                </div>
                <Progress
                    v-if="hasOverallSample"
                    :value="overall.winner_accuracy"
                    aria-label="All-time winner accuracy"
                    class="mt-5 h-1.5 bg-muted [&>div]:bg-primary"
                />
                <div
                    v-else
                    class="mt-5 h-1.5 rounded-full bg-muted"
                    aria-hidden="true"
                />
                <p class="mt-4 text-sm leading-6 text-muted-foreground">
                    {{
                        hasOverallSample
                            ? `${overall.win_record} across ${overall.total_predictions.toLocaleString()} graded predictions`
                            : 'Awaiting a graded prediction sample'
                    }}
                </p>
                <div
                    class="mt-7 grid grid-cols-2 gap-4 border-t border-border pt-6"
                >
                    <div>
                        <p class="text-xs text-muted-foreground">
                            Last 30 days
                        </p>
                        <p
                            class="mt-2 text-2xl font-semibold tracking-tight tabular-nums"
                        >
                            {{
                                formatPercent(
                                    recent.winner_accuracy,
                                    hasRecentSample,
                                )
                            }}
                        </p>
                        <p class="mt-1 text-xs leading-5 text-muted-foreground">
                            {{
                                hasRecentSample
                                    ? `${recent.total_predictions.toLocaleString()} graded predictions`
                                    : 'No graded predictions yet'
                            }}
                        </p>
                    </div>
                    <div class="border-l border-border pl-5">
                        <p class="text-xs text-muted-foreground">
                            Settled decision ROI
                        </p>
                        <p
                            class="mt-2 text-2xl font-semibold tracking-tight tabular-nums"
                        >
                            {{
                                formatSignedPercent(
                                    roi.roi_percentage,
                                    hasRoiSample,
                                )
                            }}
                        </p>
                        <p class="mt-1 text-xs leading-5 text-muted-foreground">
                            {{
                                hasRoiSample
                                    ? `${roi.priced_bets ?? roi.total_bets} priced settled bets`
                                    : 'No qualifying settled bets'
                            }}
                        </p>
                    </div>
                </div>
            </CardContent>
            <Link
                :href="performanceRoute()"
                class="flex min-h-14 items-center justify-between gap-4 border-t border-border bg-muted/30 px-6 text-sm font-medium transition-colors hover:bg-muted sm:px-8"
                >Explore the full performance record<ArrowRight
                    aria-hidden="true"
                    class="size-4 text-primary"
            /></Link>
        </Card>
    </section>
</template>
