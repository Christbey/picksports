<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';
import { computed } from 'vue';
import { formatPercent, formatSignedPercent } from '@/lib/homePresentation';
import { performance as performanceRoute } from '@/routes';
import type { PerformanceData } from '@/types/home';
const props = defineProps<{ performance: PerformanceData }>();
const metrics = computed(() => {
    const overall = props.performance.overall,
        recent = props.performance.recent.overall,
        roi = props.performance.roi;
    const hasOverall = overall.total_predictions > 0,
        hasRecent = recent.total_predictions > 0,
        hasRoi = (roi.priced_bets ?? roi.total_bets) > 0;
    return [
        {
            label: 'All-time accuracy',
            value: formatPercent(overall.winner_accuracy, hasOverall),
            detail: hasOverall
                ? `${overall.win_record} across ${overall.total_predictions.toLocaleString()} graded predictions`
                : 'Awaiting a graded prediction sample',
        },
        {
            label: 'Last 30 days',
            value: formatPercent(recent.winner_accuracy, hasRecent),
            detail: hasRecent
                ? `${recent.win_record} from ${recent.total_predictions} predictions`
                : 'No graded predictions in this window',
        },
        {
            label: 'Settled decision ROI',
            value: formatSignedPercent(roi.roi_percentage, hasRoi),
            detail: hasRoi
                ? `${roi.priced_bets ?? roi.total_bets} priced pregame-safe settled bets`
                : 'No settled qualifying bets yet',
        },
        {
            label: 'Average spread error',
            value:
                hasOverall && overall.avg_spread_error !== null
                    ? overall.avg_spread_error.toFixed(2)
                    : 'Pending',
            detail: hasOverall
                ? 'Points from the final margin'
                : 'Requires graded spread targets',
        },
    ];
});
</script>
<template>
    <section aria-labelledby="record-title" class="ps-container py-14 sm:py-20">
        <div
            class="flex flex-col justify-between gap-5 sm:flex-row sm:items-end"
        >
            <div>
                <p class="ps-eyebrow">Measured, not promised</p>
                <h2
                    id="record-title"
                    class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl"
                >
                    The record, in full view.
                </h2>
            </div>
            <Link
                :href="performanceRoute()"
                class="inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-primary"
                >Full methodology<ArrowRight aria-hidden="true" class="size-4"
            /></Link>
        </div>
        <dl
            class="mt-8 grid gap-x-8 gap-y-7 border-y border-border py-7 sm:grid-cols-2 lg:grid-cols-4 lg:gap-x-10"
            data-testid="performance-record"
        >
            <div v-for="metric in metrics" :key="metric.label">
                <dt class="text-sm text-muted-foreground">
                    {{ metric.label }}
                </dt>
                <dd
                    class="mt-3 text-3xl font-semibold tracking-tight tabular-nums"
                >
                    {{ metric.value }}
                </dd>
                <dd class="mt-3 text-xs leading-6 text-muted-foreground">
                    {{ metric.detail }}
                </dd>
            </div>
        </dl>
        <p class="mt-4 max-w-3xl text-xs leading-6 text-muted-foreground">
            Prediction accuracy and settled betting returns measure different
            things. Review the sample, pricing coverage, and methodology before
            drawing conclusions.
        </p>
    </section>
</template>
