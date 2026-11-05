<script setup lang="ts">
import type { ApiV2Prediction } from '@/types';
import type { MlbDailyPick } from '@/types/mlb-daily-picks';
import {
    numberValue,
    formatNumber,
    isFinal,
    modelResult,
    modelResultLabel,
    resultBadgeClass,
    finalScoreLabel,
    totalResultLabel,
    totalPickResult,
    totalPickLabel,
    formatDateTime,
    periodScore,
} from '@/lib/mlbMatchupPresentation';
defineProps<{ prediction: ApiV2Prediction; candidate?: MlbDailyPick | null }>();
</script>

<template>
    <section v-if="isFinal(prediction)" class="rounded-2xl border p-4">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="text-sm font-semibold">Prediction Result</div>
                <div class="mt-1 text-sm text-muted-foreground">
                    {{ finalScoreLabel(prediction) }}
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <span
                    class="rounded-full border px-3 py-1 text-xs font-semibold"
                    :class="resultBadgeClass(modelResult(prediction))"
                >
                    {{ modelResultLabel(prediction) }}
                </span>
                <span
                    v-if="totalPickResult(prediction, candidate)"
                    class="rounded-full border px-3 py-1 text-xs font-semibold"
                    :class="
                        resultBadgeClass(totalPickResult(prediction, candidate))
                    "
                >
                    {{ totalResultLabel(prediction, candidate) }}
                </span>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
            <div class="rounded-xl bg-muted/45 p-3">
                <div class="text-xs text-muted-foreground">Final margin</div>
                <div class="mt-1 font-semibold">
                    {{ formatNumber(numberValue(prediction.actual_spread)) }}
                </div>
            </div>
            <div class="rounded-xl bg-muted/45 p-3">
                <div class="text-xs text-muted-foreground">Game total</div>
                <div class="mt-1 font-semibold">
                    {{ formatNumber(numberValue(prediction.actual_total)) }}
                </div>
            </div>
            <div class="rounded-xl bg-muted/45 p-3">
                <div class="text-xs text-muted-foreground">Total Pick</div>
                <div class="mt-1 font-semibold">
                    {{ totalPickLabel(prediction, candidate) }}
                </div>
            </div>
            <div class="rounded-xl bg-muted/45 p-3">
                <div class="text-xs text-muted-foreground">Total Result</div>
                <div class="mt-1 font-semibold">
                    {{ totalResultLabel(prediction, candidate) }}
                </div>
            </div>
            <div class="rounded-xl bg-muted/45 p-3">
                <div class="text-xs text-muted-foreground">
                    Model margin miss
                </div>
                <div class="mt-1 font-semibold">
                    {{ formatNumber(numberValue(prediction.spread_error)) }}
                </div>
            </div>
            <div class="rounded-xl bg-muted/45 p-3">
                <div class="text-xs text-muted-foreground">Graded</div>
                <div class="mt-1 font-semibold">
                    {{ formatDateTime(prediction.graded_at) }}
                </div>
            </div>
            <div class="rounded-xl bg-muted/45 p-3">
                <div class="text-xs text-muted-foreground">F3 score</div>
                <div class="mt-1 font-semibold">
                    {{ periodScore(prediction, 3) }}
                </div>
            </div>
            <div class="rounded-xl bg-muted/45 p-3">
                <div class="text-xs text-muted-foreground">F5 score</div>
                <div class="mt-1 font-semibold">
                    {{ periodScore(prediction, 5) }}
                </div>
            </div>
        </div>
    </section>
</template>
