<script setup lang="ts">
import { computed } from 'vue';
import type { PredictionSummary } from '@/types';

const props = defineProps<{
    context?: PredictionSummary['depth_chart_context'] | null;
}>();

const lines = computed(() => {
    const context = props.context;
    if (!context) return [];

    if (context.type === 'injury_weighting') {
        const items: string[] = [];

        items.push(
            `Weighted injuries: home ${(context.home_out_weighted ?? 0).toFixed(2)} out / ${(context.home_questionable_weighted ?? 0).toFixed(2)} questionable, away ${(context.away_out_weighted ?? 0).toFixed(2)} out / ${(context.away_questionable_weighted ?? 0).toFixed(2)} questionable.`,
        );

        if (
            context.spread_adjustment !== null &&
            context.spread_adjustment !== undefined
        ) {
            const spread =
                context.spread_adjustment > 0
                    ? `+${context.spread_adjustment.toFixed(1)}`
                    : context.spread_adjustment.toFixed(1);
            items.push(`Depth-chart injuries moved the spread ${spread}.`);
        }

        if (
            context.total_adjustment !== null &&
            context.total_adjustment !== undefined
        ) {
            const total =
                context.total_adjustment > 0
                    ? `+${context.total_adjustment.toFixed(1)}`
                    : context.total_adjustment.toFixed(1);
            items.push(`Total adjustment from availability was ${total}.`);
        }

        return items;
    }

    const items: string[] = [];
    if (context.home_pitcher_source || context.away_pitcher_source) {
        items.push(
            `Pitcher source: home ${String(context.home_pitcher_source ?? 'unknown').replaceAll('_', ' ')}, away ${String(context.away_pitcher_source ?? 'unknown').replaceAll('_', ' ')}.`,
        );
    }

    if (
        context.home_depth_chart_fallback_used ||
        context.away_depth_chart_fallback_used
    ) {
        items.push(
            `Depth-chart starter fallback was used${context.home_depth_chart_fallback_used && context.away_depth_chart_fallback_used ? ' on both sides' : context.home_depth_chart_fallback_used ? ' for the home side' : ' for the away side'}.`,
        );
    }

    if (context.probable_pitcher_injury_applied) {
        items.push('Probable pitcher availability adjustments were applied.');
    }

    return items;
});
</script>

<template>
    <section v-if="context && lines.length" class="ui-surface p-5 md:p-6">
        <h3 class="ui-kicker">Depth Chart Context</h3>
        <div class="mt-3 space-y-2 text-sm text-foreground/90">
            <p v-for="line in lines" :key="line">{{ line }}</p>
        </div>
    </section>
</template>
