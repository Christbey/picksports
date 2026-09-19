<script setup lang="ts">
import { computed } from 'vue';
import { finiteValue } from '@/lib/gameOutcome';
import type { GamePageGame, NflPagePrediction } from '@/types';
const props = defineProps<{
    game: GamePageGame;
    prediction?: NflPagePrediction | null;
    homeLabel?: string | null;
}>();
const actual = computed(() => {
    const home = finiteValue(props.game.home_score);
    const away = finiteValue(props.game.away_score);
    return home === null || away === null || home < 0 || away < 0
        ? null
        : { margin: home - away, total: home + away };
});
const modelTotal = computed(() => {
    const value = finiteValue(props.prediction?.predicted_total);
    return value !== null && value >= 0 ? value : null;
});
const rows = computed(() => [
    {
        label: `${props.homeLabel || 'Home'} scoring margin`,
        model: finiteValue(props.prediction?.predicted_spread),
        actual: actual.value?.margin ?? null,
    },
    {
        label: 'Combined points',
        model: modelTotal.value,
        actual: actual.value?.total ?? null,
    },
]);
const number = (value: number | null) =>
    value === null ? 'Unavailable' : value.toFixed(1);
</script>
<template>
    <section
        class="min-w-0 space-y-3 rounded-xl border bg-card p-4"
        aria-label="Final result summary"
    >
        <h2 class="text-lg font-semibold">Final result · model review</h2>
        <p class="text-sm">
            Recorded winner grade:
            <strong>{{
                !actual
                    ? 'Awaiting final scores'
                    : prediction?.winner_correct === true
                      ? 'W'
                      : prediction?.winner_correct === false
                        ? 'L'
                        : 'Ungraded'
            }}</strong>
        </p>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr>
                        <th scope="col">Measure</th>
                        <th scope="col">Model</th>
                        <th scope="col">Actual</th>
                        <th scope="col">Abs. error</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.label" class="border-t">
                        <th scope="row" class="py-2 pr-2 font-normal">
                            {{ row.label }}
                        </th>
                        <td>{{ number(row.model) }}</td>
                        <td>{{ number(row.actual) }}</td>
                        <td>
                            {{
                                number(
                                    row.model === null || row.actual === null
                                        ? null
                                        : Math.abs(row.actual - row.model),
                                )
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-muted-foreground">
            Stored model values, not a verified pregame snapshot. Winner grade
            is the recorded model result, not wager performance.
        </p>
        <p class="text-xs text-muted-foreground">
            Spread/total bet grades are not shown: this view does not establish
            a preserved pregame market line. Model error is not an ATS or
            over/under result.
        </p>
    </section>
</template>
