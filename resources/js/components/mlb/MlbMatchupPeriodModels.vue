<script setup lang="ts">
import type { ApiV2Prediction } from '@/types';
import type { MlbDailyPick } from '@/types/mlb-daily-picks';
import { labelizeMlbCode } from '@/lib/mlbRecommendationLabels';
import {
    formatPercent,
    formatSignedPercent,
    periodModels,
    compactId,
} from '@/lib/mlbMatchupPresentation';
defineProps<{ prediction: ApiV2Prediction; candidate?: MlbDailyPick | null }>();
</script>

<template>
    <section
        v-if="periodModels(prediction, candidate).length"
        class="rounded-2xl border p-4"
    >
        <div class="text-sm font-semibold">F3/F5 Model Tracking</div>
        <div
            v-for="model in periodModels(prediction, candidate)"
            :key="`${model.market_type}-${model.lineage.artifact_id}`"
            class="mt-4 border-t pt-4 first:mt-3"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="font-semibold">
                    {{ labelizeMlbCode(model.market_type) }}
                </div>
                <div class="flex flex-wrap gap-2">
                    <span
                        class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                    >
                        {{ labelizeMlbCode(model.role) }}
                    </span>
                    <span
                        class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                        :class="
                            model.qualified_for_candidates
                                ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                : 'border-slate-500/25 bg-slate-500/10 text-slate-700 dark:text-slate-300'
                        "
                    >
                        {{
                            model.qualified_for_candidates
                                ? 'Qualified'
                                : 'Shadow only'
                        }}
                    </span>
                </div>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div>
                    <div class="text-muted-foreground">Home</div>
                    <div class="font-semibold">
                        {{ formatPercent(model.probabilities.home_win) }}
                    </div>
                </div>
                <div>
                    <div class="text-muted-foreground">Away</div>
                    <div class="font-semibold">
                        {{ formatPercent(model.probabilities.away_win) }}
                    </div>
                </div>
                <div>
                    <div class="text-muted-foreground">Tie</div>
                    <div class="font-semibold">
                        {{ formatPercent(model.probabilities.tie) }}
                    </div>
                </div>
                <div>
                    <div class="text-muted-foreground">Uncertainty</div>
                    <div class="font-semibold">
                        {{ formatPercent(model.uncertainty) }}
                    </div>
                </div>
            </div>

            <div class="mt-3 grid gap-2 border-t pt-3 text-xs sm:grid-cols-2">
                <div>
                    <span class="text-muted-foreground">Artifact </span>
                    <span
                        class="font-mono"
                        :title="model.lineage.artifact_id ?? ''"
                        >{{ compactId(model.lineage.artifact_id) }}</span
                    >
                </div>
                <div>
                    <span class="text-muted-foreground">Model run </span>
                    <span
                        class="font-mono"
                        :title="model.lineage.model_run_id ?? ''"
                        >{{ compactId(model.lineage.model_run_id) }}</span
                    >
                </div>
                <div>
                    <span class="text-muted-foreground">Dataset </span>
                    <span
                        class="font-mono"
                        :title="model.lineage.dataset_hash ?? ''"
                        >{{ compactId(model.lineage.dataset_hash) }}</span
                    >
                </div>
                <div>
                    <span class="text-muted-foreground">Feature hash </span>
                    <span
                        class="font-mono"
                        :title="model.lineage.feature_hash ?? ''"
                        >{{ compactId(model.lineage.feature_hash) }}</span
                    >
                </div>
            </div>

            <div v-if="model.decision" class="mt-3 border-t pt-3 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-semibold">{{
                        labelizeMlbCode(model.decision.status)
                    }}</span>
                    <span>
                        Edge
                        {{ formatSignedPercent(model.decision.edge) }}
                        · EV
                        {{ formatSignedPercent(model.decision.expected_value) }}
                    </span>
                </div>
                <div
                    v-if="model.decision.eligibility_reasons.length"
                    class="mt-2 flex flex-wrap gap-2"
                >
                    <span
                        v-for="reason in model.decision.eligibility_reasons"
                        :key="reason"
                        class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-1 text-xs text-amber-700 dark:text-amber-300"
                    >
                        {{ labelizeMlbCode(reason) }}
                    </span>
                </div>
            </div>
        </div>
    </section>
</template>
