<script setup lang="ts">
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { LoaderCircle } from 'lucide-vue-next';
import MlbSignalGroups from '@/components/mlb/MlbSignalGroups.vue';
import { labelizeMlbCode } from '@/lib/mlbRecommendationLabels';
import {
    predictionTiming,
    predictionTimingBadgeClass,
} from '@/lib/predictionTiming';
import type { ApiV2Prediction } from '@/types';
import type { MlbDailyPick } from '@/types/mlb-daily-picks';
import {
    matchupLabel,
    candidateStatusLabel,
    candidatePanelClass,
    candidateModeLabel,
    summaryBadgeClass,
    candidateSummary,
    formatTime,
    signalDriverLabels,
    watchoutLabels,
    signalGroups,
} from '@/lib/mlbMatchupPresentation';
import MlbMatchupResult from './MlbMatchupResult.vue';
import MlbMatchupPeriodInsights from './MlbMatchupPeriodInsights.vue';
import MlbMatchupOverview from './MlbMatchupOverview.vue';
import MlbMatchupPeriodModels from './MlbMatchupPeriodModels.vue';
defineProps<{
    prediction: ApiV2Prediction | null;
    candidate?: MlbDailyPick | null;
    candidates?: MlbDailyPick[];
    loadingDetails?: boolean;
    detailError?: string | null;
}>();

const emit = defineEmits<{
    selectCandidate: [candidate: MlbDailyPick];
}>();

const open = defineModel<boolean>('open', { default: false });
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-2xl">
            <SheetHeader v-if="prediction">
                <SheetTitle>{{ matchupLabel(prediction) }}</SheetTitle>
                <SheetDescription>
                    {{
                        formatTime(
                            prediction.game?.game_date,
                            prediction.game?.game_time,
                        )
                    }}
                    <template
                        v-if="predictionTiming(prediction).phase === 'live'"
                    >
                        · {{ predictionTiming(prediction).label }}
                    </template>
                    · {{ candidateStatusLabel(candidate) }}
                </SheetDescription>
            </SheetHeader>

            <div v-if="prediction" class="mt-6 space-y-5">
                <div
                    v-if="loadingDetails"
                    class="flex items-center gap-2 rounded-lg border bg-muted/30 px-3 py-2 text-sm text-muted-foreground"
                >
                    <LoaderCircle class="h-4 w-4 animate-spin" />
                    Loading full market and signal details
                </div>

                <div
                    v-else-if="detailError"
                    class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-300"
                >
                    {{ detailError }}
                </div>

                <section
                    v-if="(candidates?.length ?? 0) > 1"
                    class="border-b pb-4"
                >
                    <div
                        class="mb-2 text-xs font-semibold text-muted-foreground"
                    >
                        Markets
                    </div>
                    <div class="flex gap-2 overflow-x-auto pb-1">
                        <button
                            v-for="option in candidates"
                            :key="option.id"
                            type="button"
                            class="shrink-0 rounded-lg border px-3 py-2 text-xs font-semibold transition"
                            :class="
                                option.id === candidate?.id
                                    ? 'border-sky-500 bg-sky-500/10 text-sky-700 dark:text-sky-300'
                                    : 'bg-background text-muted-foreground hover:bg-muted'
                            "
                            @click="emit('selectCandidate', option)"
                        >
                            {{ labelizeMlbCode(option.market_type) }} ·
                            {{ option.label }}
                        </button>
                    </div>
                </section>

                <section
                    v-if="predictionTiming(prediction).phase === 'live'"
                    class="rounded-2xl border border-red-500/25 bg-red-500/[0.04] p-4"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <div class="text-sm font-semibold">
                                Live Context
                            </div>
                            <p
                                class="mt-1 max-w-xl text-sm leading-6 text-muted-foreground"
                            >
                                {{ predictionTiming(prediction).description }}
                            </p>
                        </div>
                        <span
                            class="rounded-full border px-3 py-1 text-xs font-semibold"
                            :class="
                                predictionTimingBadgeClass(
                                    predictionTiming(prediction),
                                )
                            "
                        >
                            {{ predictionTiming(prediction).label }}
                        </span>
                    </div>
                </section>

                <section
                    v-if="candidate"
                    class="rounded-2xl border p-4"
                    :class="candidatePanelClass(prediction, candidate)"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                                    :class="
                                        summaryBadgeClass(prediction, candidate)
                                    "
                                >
                                    {{ candidateStatusLabel(candidate) }}
                                </span>
                                <span
                                    class="rounded-full border bg-background/80 px-2.5 py-1 text-xs font-semibold text-muted-foreground"
                                >
                                    {{ labelizeMlbCode(candidate.market_type) }}
                                </span>
                            </div>
                            <div class="mt-3 text-2xl font-bold">
                                {{ candidate.label }}
                            </div>
                            <p
                                class="mt-2 max-w-xl text-sm leading-6 text-muted-foreground"
                            >
                                {{ candidateSummary(prediction, candidate) }}
                            </p>
                        </div>
                        <div
                            class="hidden max-w-36 shrink-0 rounded-2xl border bg-background p-3 text-right sm:block"
                        >
                            <div class="text-xs font-semibold">
                                {{ candidateModeLabel(candidate) }}
                            </div>
                            <div
                                class="mt-1 text-[11px] leading-4 text-muted-foreground"
                            >
                                Model review
                            </div>
                        </div>
                    </div>
                </section>

                <MlbMatchupResult
                    :prediction="prediction"
                    :candidate="candidate"
                />

                <MlbMatchupPeriodInsights
                    :prediction="prediction"
                    :candidate="candidate"
                />

                <MlbMatchupOverview
                    :prediction="prediction"
                    :candidate="candidate"
                />

                <MlbMatchupPeriodModels
                    :prediction="prediction"
                    :candidate="candidate"
                />

                <section
                    v-if="signalGroups(candidate).length"
                    class="space-y-3"
                >
                    <div>
                        <div class="text-sm font-semibold">Why It Shows</div>
                        <p class="mt-1 text-sm leading-6 text-muted-foreground">
                            Plain-English drivers behind the pick and the main
                            reasons to be careful.
                        </p>
                    </div>
                    <MlbSignalGroups
                        :groups="signalGroups(candidate)"
                        :show-drivers="true"
                        :show-score-delta="false"
                    />
                </section>

                <section v-else class="rounded-2xl border p-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <div class="mb-3 text-sm font-semibold">
                                Signal Drivers
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="driver in signalDriverLabels(
                                        prediction,
                                        candidate,
                                    )"
                                    :key="driver"
                                    class="rounded-full border bg-background px-2.5 py-1 text-xs"
                                >
                                    {{ driver }}
                                </span>
                            </div>
                        </div>

                        <div>
                            <div class="mb-3 text-sm font-semibold">
                                Watchouts
                            </div>
                            <div
                                v-if="
                                    watchoutLabels(prediction, candidate)
                                        .length > 0
                                "
                                class="flex flex-wrap gap-2"
                            >
                                <span
                                    v-for="risk in watchoutLabels(
                                        prediction,
                                        candidate,
                                    )"
                                    :key="risk"
                                    class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-1 text-xs text-amber-700 dark:text-amber-300"
                                >
                                    {{ risk }}
                                </span>
                            </div>
                            <div v-else class="text-sm text-muted-foreground">
                                No major watchouts were returned.
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </SheetContent>
    </Sheet>
</template>
