<script setup lang="ts">
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    candidateRecommendation,
    getPredictionRecommendation,
} from '@/lib/predictionRecommendation';
import { labelizeMlbCode, safeMlbPickStatus } from '@/lib/mlbRecommendationLabels';
import type { ApiV2Prediction, ApiV2Record } from '@/types';
import type { MlbDailyPick } from '@/types/mlb-daily-picks';

const props = defineProps<{
    prediction: ApiV2Prediction | null;
    candidate?: MlbDailyPick | null;
}>();

const open = defineModel<boolean>('open', { default: false });

function numberValue(value: unknown): number | null {
    if (value === null || value === undefined || value === '') return null;

    const numeric = Number(value);

    return Number.isFinite(numeric) ? numeric : null;
}

function teamAbbreviation(team: unknown): string {
    const payload = (team ?? {}) as ApiV2Record;

    return String(
        payload.abbreviation ??
            payload.short_display_name ??
            payload.display_name ??
            '',
    );
}

function matchupLabel(prediction: ApiV2Prediction | null): string {
    const game = prediction?.game;
    if (!game) return 'MLB Matchup';

    return `${teamAbbreviation(game.away_team)} @ ${teamAbbreviation(game.home_team)}`;
}

function formatPercent(value?: number | null): string {
    if (value == null) return '-';

    return `${(value * 100).toFixed(1)}%`;
}

function formatSignedPercent(value?: number | null): string {
    if (value == null) return '-';

    const percent = value * 100;

    return `${percent > 0 ? '+' : ''}${percent.toFixed(1)}%`;
}

function formatNumber(value?: number | null): string {
    if (value == null) return '-';

    return Number(value).toFixed(2);
}

function formatOdds(value?: number | null): string {
    if (value == null) return '-';

    return value > 0 ? `+${value}` : `${value}`;
}

function isFinal(prediction: ApiV2Prediction | null): boolean {
    return String(prediction?.status ?? prediction?.game?.status ?? '')
        .toLowerCase()
        .includes('final');
}

function modelResult(prediction: ApiV2Prediction | null): 'win' | 'loss' | 'ungraded' | null {
    if (!isFinal(prediction)) return null;

    if (prediction?.winner_correct === true) return 'win';
    if (prediction?.winner_correct === false) return 'loss';

    return 'ungraded';
}

function modelResultLabel(prediction: ApiV2Prediction | null): string {
    const result = modelResult(prediction);

    if (result === 'win') return 'Model Win';
    if (result === 'loss') return 'Model Loss';
    if (result === 'ungraded') return 'Ungraded';

    return 'Pending';
}

function resultBadgeClass(result: 'win' | 'loss' | 'push' | 'ungraded' | string | null): string {
    if (result === 'win') {
        return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    }

    if (result === 'loss') {
        return 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-300';
    }

    if (result === 'push') {
        return 'border-slate-500/30 bg-slate-500/10 text-slate-700 dark:text-slate-300';
    }

    return 'border-muted bg-muted/40 text-muted-foreground';
}

function finalScoreLabel(prediction: ApiV2Prediction | null): string {
    if (!isFinal(prediction)) return 'Not Final';

    const awayScore = numberValue(prediction?.game?.away_score);
    const homeScore = numberValue(prediction?.game?.home_score);

    if (awayScore == null || homeScore == null) return 'Final';

    return `${teamAbbreviation(prediction?.game?.away_team)} ${awayScore} - ${teamAbbreviation(prediction?.game?.home_team)} ${homeScore}`;
}

function totalResultLabel(prediction: ApiV2Prediction | null): string {
    const side = String(prediction?.total_pick_side ?? '');
    const result = String(prediction?.total_pick_result ?? '');

    if (!side || !result) return 'Not Available';

    return `${labelizeMlbCode(side)} ${labelizeMlbCode(result)}`;
}

function totalPickLabel(prediction: ApiV2Prediction | null): string {
    const side = String(prediction?.total_pick_side ?? '');
    const line = numberValue(prediction?.total_pick_line);

    if (!side || line === null) return '-';

    return `${labelizeMlbCode(side)} ${line.toFixed(1)}`;
}

function formatDateTime(value?: string | null): string {
    if (!value) return '-';

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

function formatTime(value?: string | null, time?: string | null): string {
    if (!value) return 'Time pending';

    const date = gameDateTime(value, time);

    return new Intl.DateTimeFormat(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date);
}

function gameDateTime(value: string, time?: string | null): Date {
    if (time && /^\d{1,2}:\d{2}/.test(time)) {
        const datePart = value.includes('T')
            ? value.split('T')[0]
            : value.split(' ')[0];

        return new Date(`${datePart}T${time}`);
    }

    return new Date(value);
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-2xl">
            <SheetHeader v-if="prediction">
                <SheetTitle>{{ matchupLabel(prediction) }}</SheetTitle>
                <SheetDescription>
                    {{ formatTime(prediction.game?.game_date, prediction.game?.game_time) }} ·
                    {{ candidate ? safeMlbPickStatus(candidate) : 'No Public Pick' }}
                </SheetDescription>
            </SheetHeader>

            <div v-if="prediction" class="mt-6 space-y-5">
                <section
                    v-if="isFinal(prediction)"
                    class="rounded-2xl border p-4"
                >
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
                                v-if="prediction.total_pick_result"
                                class="rounded-full border px-3 py-1 text-xs font-semibold"
                                :class="resultBadgeClass(prediction.total_pick_result)"
                            >
                                {{ totalResultLabel(prediction) }}
                            </span>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">Actual Spread</div>
                            <div class="mt-1 font-semibold">
                                {{ formatNumber(numberValue(prediction.actual_spread)) }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">Actual Total</div>
                            <div class="mt-1 font-semibold">
                                {{ formatNumber(numberValue(prediction.actual_total)) }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">Total Pick</div>
                            <div class="mt-1 font-semibold">
                                {{ totalPickLabel(prediction) }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">Total Result</div>
                            <div class="mt-1 font-semibold">
                                {{ totalResultLabel(prediction) }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">Spread Error</div>
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
                    </div>
                </section>

                <section
                    v-if="candidate"
                    class="rounded-2xl border border-emerald-500/25 bg-emerald-500/[0.04] p-4"
                >
                    <div class="mb-3 flex items-start justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold text-emerald-600 dark:text-emerald-400">
                                Tracking Candidate
                            </div>
                            <div class="mt-1 text-xl font-bold">{{ candidate.label }}</div>
                        </div>
                        <div class="grid h-14 w-14 place-items-center rounded-2xl border bg-background">
                            <div class="text-center">
                                <div class="text-lg font-black text-emerald-500">
                                    {{ candidate.score }}
                                </div>
                                <div class="text-[10px] font-semibold uppercase text-muted-foreground">
                                    Score
                                </div>
                            </div>
                        </div>
                    </div>
                    <p class="text-sm leading-6 text-muted-foreground">
                        {{ candidate.explanation }}
                    </p>
                </section>

                <section class="rounded-2xl border p-4">
                    <div class="mb-3 text-sm font-semibold">
                        Market-Aware Projection
                    </div>
                    <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">Model</div>
                            <div class="mt-1 font-semibold">
                                {{
                                    formatPercent(
                                        candidate?.model_probability ??
                                            prediction.market_aware_projection?.model_probability ??
                                            numberValue(prediction.projection?.home_win_probability),
                                    )
                                }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">Market</div>
                            <div class="mt-1 font-semibold">
                                {{
                                    formatPercent(
                                        candidate?.market_probability ??
                                            prediction.market_aware_projection?.market_probability ??
                                            candidateRecommendation(prediction)?.no_vig_implied_probability,
                                    )
                                }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">Blend</div>
                            <div class="mt-1 font-semibold">
                                {{
                                    formatPercent(
                                        candidate?.blend_probability ??
                                            prediction.market_aware_projection?.blended_probability,
                                    )
                                }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">Edge</div>
                            <div class="mt-1 font-semibold">
                                {{
                                    formatSignedPercent(
                                        candidate?.edge_no_vig ??
                                            candidate?.edge_raw ??
                                            candidateRecommendation(prediction)?.no_vig_edge ??
                                            candidateRecommendation(prediction)?.raw_edge,
                                    )
                                }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                        <div>
                            <div class="text-muted-foreground">Predicted Spread</div>
                            <div class="font-semibold">
                                {{ formatNumber(numberValue(prediction.projection?.predicted_spread)) }}
                            </div>
                        </div>
                        <div>
                            <div class="text-muted-foreground">Predicted Total</div>
                            <div class="font-semibold">
                                {{ formatNumber(numberValue(prediction.projection?.predicted_total)) }}
                            </div>
                        </div>
                        <div>
                            <div class="text-muted-foreground">Price</div>
                            <div class="font-semibold">{{ formatOdds(candidate?.price) }}</div>
                        </div>
                        <div>
                            <div class="text-muted-foreground">Line</div>
                            <div class="font-semibold">{{ formatNumber(candidate?.line) }}</div>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border p-4">
                    <div class="mb-3 text-sm font-semibold">Reasons</div>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="reason in (candidate?.reason_codes ?? candidateRecommendation(prediction)?.reason_codes ?? getPredictionRecommendation(prediction)?.reason_codes ?? ['model_projection'])"
                            :key="reason"
                            class="rounded-full border bg-background px-2.5 py-1 text-xs"
                        >
                            {{ labelizeMlbCode(reason) }}
                        </span>
                    </div>
                </section>

                <section class="rounded-2xl border p-4">
                    <div class="mb-3 text-sm font-semibold">Risks</div>
                    <div
                        v-if="(candidate?.risk_flags?.length ?? 0) > 0 || (candidateRecommendation(prediction)?.risk_flags?.length ?? 0) > 0"
                        class="flex flex-wrap gap-2"
                    >
                        <span
                            v-for="risk in (candidate?.risk_flags ?? candidateRecommendation(prediction)?.risk_flags ?? [])"
                            :key="risk"
                            class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-1 text-xs text-amber-700 dark:text-amber-300"
                        >
                            {{ labelizeMlbCode(risk) }}
                        </span>
                    </div>
                    <div v-else class="text-sm text-muted-foreground">
                        No card-level risk flags were returned for this matchup.
                    </div>
                </section>

                <section class="rounded-2xl border p-4">
                    <div class="mb-3 text-sm font-semibold">
                        Pitcher, Bullpen, Weather, And Market Context
                    </div>
                    <pre
                        class="max-h-80 overflow-auto rounded-xl bg-muted p-3 text-xs leading-5"
                    >{{ JSON.stringify({
                        market_aware_projection: prediction.market_aware_projection,
                        recommendation: prediction.recommendation,
                        candidate_feature_snapshot: candidate?.feature_snapshot,
                        candidate_market_snapshot: candidate?.market_snapshot,
                    }, null, 2) }}</pre>
                </section>
            </div>
        </SheetContent>
    </Sheet>
</template>
