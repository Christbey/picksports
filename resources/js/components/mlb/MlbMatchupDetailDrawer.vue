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
import {
    candidateRecommendation,
    getPredictionRecommendation,
} from '@/lib/predictionRecommendation';
import {
    labelizeMlbCode,
    safeMlbPickStatus,
    tierFromScore,
} from '@/lib/mlbRecommendationLabels';
import {
    predictionTiming,
    predictionTimingBadgeClass,
} from '@/lib/predictionTiming';
import type {
    ApiV2Prediction,
    ApiV2Record,
    MlbPeriodInsight,
    MlbPeriodInsightTeam,
} from '@/types';
import type {
    MlbDailyPick,
    MlbPeriodModelContext,
    MlbSignalGroup,
} from '@/types/mlb-daily-picks';

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

const BETTOR_SIGNAL_LABELS: Record<string, string> = {
    acceptable_model_margin: 'Model margin is inside the playable range',
    blend_probability_strong: 'Blended probability clears the signal screen',
    bullpen_quality_home_edge: 'Bullpen quality supports the home side',
    historical_matchup_context: 'Historical matchup context supports the read',
    home_field_support: 'Home-field context supports the pick',
    model_market_agrees: 'Model and market point to the same side',
    model_projection: 'Model projection supports this angle',
    model_total_over_market: 'Model projects more runs than the market total',
    model_total_under_market: 'Model projects fewer runs than the market total',
    moneyline_bet_filter: 'Moneyline side passed the pick screen',
    moneyline_pick_filter: 'Moneyline side passed the pick screen',
    park_support: 'Ballpark run environment supports the angle',
    positive_no_vig_edge: 'Positive edge after removing sportsbook vig',
    probable_pitchers_confirmed: 'Probable starters are confirmed',
    selective_bet_filter_applied: 'Passed the selective pick screen',
    selective_pick_filter_applied: 'Passed the selective pick screen',
    weather_supports_over: 'Weather leans toward more runs',
    weather_supports_under: 'Weather leans toward fewer runs',
};

const BETTOR_RISK_LABELS: Record<string, string> = {
    away_pick_underperforming_split:
        'Away-side picks have underperformed this split',
    low_confidence_bucket: 'Model confidence is still modest',
    missing_market_context: 'Market context is incomplete',
    missing_odds_timestamp: 'Odds timestamp is missing',
    moneyline_price_missing: 'Moneyline price is missing',
    pitcher_uncertainty: 'Starting pitcher situation is uncertain',
    pitcher_uncertainty_risk: 'Starting pitcher situation is uncertain',
    stale_odds: 'Odds may be stale',
    total_model_over_bias: 'Totals model has shown over-bias',
    unconfirmed_pitcher: 'Starting pitcher is not confirmed',
};

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

function modelResult(
    prediction: ApiV2Prediction | null,
): 'win' | 'loss' | 'ungraded' | null {
    if (!isFinal(prediction)) return null;

    if (prediction?.winner_correct === true) return 'win';
    if (prediction?.winner_correct === false) return 'loss';

    return 'ungraded';
}

function modelResultLabel(prediction: ApiV2Prediction | null): string {
    const result = modelResult(prediction);

    if (result === 'win') return 'Projection won';
    if (result === 'loss') return 'Projection lost';
    if (result === 'ungraded') return 'Ungraded';

    return 'Pending';
}

function resultBadgeClass(
    result: 'win' | 'loss' | 'push' | 'ungraded' | string | null,
): string {
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

type TotalPickContext = {
    side: string | null;
    line: number | null;
    result: string | null;
    actualTotal: number | null;
};

function totalPickContext(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): TotalPickContext {
    const nested = (prediction?.total_result ?? {}) as ApiV2Record;
    const isTotalCandidate = candidate?.market_type === 'total';
    const side = String(
        prediction?.total_pick_side ??
            nested.side ??
            (isTotalCandidate ? candidate?.side : '') ??
            '',
    )
        .trim()
        .toLowerCase();
    const line =
        numberValue(prediction?.total_pick_line) ??
        numberValue(nested.line) ??
        (isTotalCandidate ? numberValue(candidate?.line) : null);
    const actualTotal =
        numberValue(prediction?.actual_total) ??
        numberValue(nested.actual_total);
    let result = String(prediction?.total_pick_result ?? nested.result ?? '')
        .trim()
        .toLowerCase();

    if (
        !result &&
        isFinal(prediction) &&
        isTotalCandidate &&
        side &&
        line !== null &&
        actualTotal !== null
    ) {
        const margin = actualTotal - line;
        if (Math.abs(margin) < 0.0001) {
            result = 'push';
        } else if (side === 'over') {
            result = margin > 0 ? 'win' : 'loss';
        } else if (side === 'under') {
            result = margin < 0 ? 'win' : 'loss';
        }
    }

    return {
        side: side || null,
        line,
        result: result || null,
        actualTotal,
    };
}

function totalResultLabel(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string {
    const total = totalPickContext(prediction, candidate);

    if (!total.side || !total.result) return 'Not Available';

    return `${labelizeMlbCode(total.side)} ${labelizeMlbCode(total.result)}`;
}

function totalPickResult(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string | null {
    if (!isFinal(prediction)) return null;

    return totalPickContext(prediction, candidate).result;
}

function hasPositiveResult(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): boolean {
    return (
        modelResult(prediction) === 'win' ||
        totalPickResult(prediction, candidate) === 'win' ||
        candidate?.result_status === 'win'
    );
}

function hasNegativeResult(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): boolean {
    return (
        modelResult(prediction) === 'loss' ||
        totalPickResult(prediction, candidate) === 'loss' ||
        candidate?.result_status === 'loss'
    );
}

function candidateStatusLabel(candidate?: MlbDailyPick | null): string {
    if (!candidate) return 'No public pick';

    if (candidate.is_tracking_only || !candidate.is_public) {
        return `${tierFromScore(candidate.score)} tracking signal`;
    }

    return safeMlbPickStatus(candidate);
}

function candidatePanelClass(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string {
    if (hasPositiveResult(prediction, candidate)) {
        return 'border-emerald-500/30 bg-emerald-500/[0.04]';
    }

    if (hasNegativeResult(prediction, candidate)) {
        return 'border-red-500/30 bg-red-500/[0.04]';
    }

    return 'border-border bg-card';
}

function candidateModeLabel(candidate?: MlbDailyPick | null): string {
    if (!candidate) return 'No pick';

    return candidate.is_tracking_only || !candidate.is_public
        ? 'Tracking only'
        : 'Official pick';
}

function candidateModeDescription(candidate?: MlbDailyPick | null): string {
    if (!candidate) return 'No candidate was selected for this matchup.';

    return candidate.is_tracking_only || !candidate.is_public
        ? 'Use this for model review only. It is not an official betting recommendation.'
        : 'This pick passed the current public recommendation checks.';
}

function summaryBadgeClass(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string {
    if (hasPositiveResult(prediction, candidate)) {
        return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    }

    if (hasNegativeResult(prediction, candidate)) {
        return 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-300';
    }

    return 'border-slate-500/25 bg-slate-500/10 text-slate-700 dark:text-slate-300';
}

function totalPickLabel(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string {
    const total = totalPickContext(prediction, candidate);

    if (!total.side || total.line === null) return '-';

    return `${labelizeMlbCode(total.side)} ${total.line.toFixed(1)}`;
}

function candidateSummary(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string {
    if (!candidate) {
        return 'No tracked pick is attached to this matchup yet.';
    }

    const watchouts = watchoutLabels(prediction, candidate);
    const caution =
        watchouts.length > 0
            ? ` Main caution: ${watchouts[0].toLowerCase()}.`
            : ' No major caution flags were returned.';

    return `${candidateModeDescription(candidate)} Our read is ${candidate.label}.${caution}`;
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

function rawReasonCodes(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string[] {
    return (
        candidate?.reason_codes ??
        candidateRecommendation(prediction)?.reason_codes ??
        getPredictionRecommendation(prediction)?.reason_codes ?? [
            'model_projection',
        ]
    );
}

function rawRiskCodes(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string[] {
    return (
        candidate?.risk_flags ??
        candidateRecommendation(prediction)?.risk_flags ??
        []
    );
}

function isWatchoutCode(code: string): boolean {
    const normalized = code.toLowerCase();

    return [
        'bias',
        'missing',
        'risk',
        'stale',
        'uncertain',
        'uncertainty',
        'unconfirmed',
        'underperforming',
        'low_confidence',
    ].some((needle) => normalized.includes(needle));
}

function friendlyCodeLabel(code: string, type: 'signal' | 'risk'): string {
    const normalized = code.toLowerCase();

    return (
        (type === 'risk'
            ? BETTOR_RISK_LABELS[normalized]
            : BETTOR_SIGNAL_LABELS[normalized]) ??
        BETTOR_SIGNAL_LABELS[normalized] ??
        BETTOR_RISK_LABELS[normalized] ??
        labelizeMlbCode(code)
            .replace(/\bFilter\b/g, 'screen')
            .replace(/\bNo Vig\b/g, 'no-vig')
            .replace(/\bPick\b/g, 'pick')
    );
}

function uniqueLabels(codes: string[], type: 'signal' | 'risk'): string[] {
    return Array.from(
        new Set(
            codes.filter(Boolean).map((code) => friendlyCodeLabel(code, type)),
        ),
    );
}

function signalDriverLabels(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string[] {
    const labels = uniqueLabels(
        rawReasonCodes(prediction, candidate).filter(
            (code) => !isWatchoutCode(code),
        ),
        'signal',
    );

    return labels.length > 0
        ? labels
        : ['Model projection supports this angle'];
}

function watchoutLabels(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string[] {
    return uniqueLabels(
        [
            ...rawReasonCodes(prediction, candidate).filter(isWatchoutCode),
            ...rawRiskCodes(prediction, candidate),
        ],
        'risk',
    );
}

function signalGroups(candidate?: MlbDailyPick | null): MlbSignalGroup[] {
    return candidate?.signal_layer?.signal_groups ?? [];
}

function periodModels(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): MlbPeriodModelContext[] {
    return candidate?.period_models?.length
        ? candidate.period_models
        : (prediction?.period_models ?? []);
}

function periodInsights(
    prediction: ApiV2Prediction | null,
): MlbPeriodInsight[] {
    return prediction?.period_insights ?? [];
}

function periodStateLabel(state: string): string {
    const labels: Record<string, string> = {
        insight_only_no_market: 'Insight only',
        no_bet_missing_quote: 'No quote',
        shadow_model_available: 'Shadow model',
        priced_candidate: 'Priced candidate',
        market_available_pending_candidate: 'Market pending',
    };

    return labels[state] ?? labelizeMlbCode(state);
}

function periodStateClass(state: string): string {
    if (state === 'priced_candidate') {
        return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    }
    if (state === 'shadow_model_available') {
        return 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300';
    }
    if (state === 'no_bet_missing_quote') {
        return 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300';
    }

    return 'border-muted bg-muted/40 text-muted-foreground';
}

function periodRecord(team: MlbPeriodInsightTeam): string {
    const record = team.record;

    return `${record.wins}-${record.losses}-${record.ties}`;
}

function periodLeanLabel(insight: MlbPeriodInsight): string {
    if (insight.lean.side === 'neutral') return 'No clear Elo lean';

    return `${insight.lean.team_abbreviation ?? insight.lean.side} Elo lean`;
}

function signedNumber(value?: number | null, digits = 2): string {
    if (value == null) return '-';

    return `${value > 0 ? '+' : ''}${value.toFixed(digits)}`;
}

function periodSignalLabel(code: string): string {
    const labels: Record<string, string> = {
        home_period_elo_edge: 'Home period Elo edge',
        away_period_elo_edge: 'Away period Elo edge',
        home_recent_period_form_edge: 'Home recent-form edge',
        away_recent_period_form_edge: 'Away recent-form edge',
        home_starter_edge: 'Home starter edge',
        away_starter_edge: 'Away starter edge',
    };

    return labels[code] ?? labelizeMlbCode(code);
}

function periodRiskLabel(code: string): string {
    const labels: Record<string, string> = {
        limited_period_sample: 'Limited period sample',
        pregame_pitcher_context_missing: 'Pregame pitcher ratings unavailable',
        period_market_quote_missing: 'No F3/F5 market quote',
        short_period_variance: 'Higher short-window variance',
    };

    return labels[code] ?? labelizeMlbCode(code);
}

function modelSourceLabel(candidate?: MlbDailyPick | null): string {
    return candidate?.model_source === 'promoted_period_model'
        ? 'Promoted period model'
        : candidate?.model_source === 'elo_heuristic'
          ? 'Elo heuristic fallback'
          : 'Rules model';
}

function periodScore(
    prediction: ApiV2Prediction | null,
    innings: number,
): string {
    const away = prediction?.game?.away_linescores?.slice(0, innings) ?? [];
    const home = prediction?.game?.home_linescores?.slice(0, innings) ?? [];
    if (away.length < innings || home.length < innings) return '-';

    const sum = (values: Array<number | string | null>): number =>
        values.reduce<number>(
            (total, value) => total + (numberValue(value) ?? 0),
            0,
        );

    return `${teamAbbreviation(prediction?.game?.away_team)} ${sum(away)} - ${teamAbbreviation(prediction?.game?.home_team)} ${sum(home)}`;
}

function compactId(value?: string | null): string {
    if (!value) return '-';

    return value.length > 18
        ? `${value.slice(0, 8)}...${value.slice(-6)}`
        : value;
}
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

                <section
                    v-if="isFinal(prediction)"
                    class="rounded-2xl border p-4"
                >
                    <div
                        class="mb-3 flex flex-wrap items-center justify-between gap-3"
                    >
                        <div>
                            <div class="text-sm font-semibold">
                                Prediction Result
                            </div>
                            <div class="mt-1 text-sm text-muted-foreground">
                                {{ finalScoreLabel(prediction) }}
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <span
                                class="rounded-full border px-3 py-1 text-xs font-semibold"
                                :class="
                                    resultBadgeClass(modelResult(prediction))
                                "
                            >
                                {{ modelResultLabel(prediction) }}
                            </span>
                            <span
                                v-if="totalPickResult(prediction, candidate)"
                                class="rounded-full border px-3 py-1 text-xs font-semibold"
                                :class="
                                    resultBadgeClass(
                                        totalPickResult(prediction, candidate),
                                    )
                                "
                            >
                                {{ totalResultLabel(prediction, candidate) }}
                            </span>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">
                                Final margin
                            </div>
                            <div class="mt-1 font-semibold">
                                {{
                                    formatNumber(
                                        numberValue(prediction.actual_spread),
                                    )
                                }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">
                                Game total
                            </div>
                            <div class="mt-1 font-semibold">
                                {{
                                    formatNumber(
                                        numberValue(prediction.actual_total),
                                    )
                                }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">
                                Total Pick
                            </div>
                            <div class="mt-1 font-semibold">
                                {{ totalPickLabel(prediction, candidate) }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">
                                Total Result
                            </div>
                            <div class="mt-1 font-semibold">
                                {{ totalResultLabel(prediction, candidate) }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">
                                Model margin miss
                            </div>
                            <div class="mt-1 font-semibold">
                                {{
                                    formatNumber(
                                        numberValue(prediction.spread_error),
                                    )
                                }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">
                                Graded
                            </div>
                            <div class="mt-1 font-semibold">
                                {{ formatDateTime(prediction.graded_at) }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">
                                F3 score
                            </div>
                            <div class="mt-1 font-semibold">
                                {{ periodScore(prediction, 3) }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">
                                F5 score
                            </div>
                            <div class="mt-1 font-semibold">
                                {{ periodScore(prediction, 5) }}
                            </div>
                        </div>
                    </div>
                </section>

                <section
                    v-if="periodInsights(prediction).length"
                    class="rounded-2xl border p-4"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <div class="text-sm font-semibold">F3/F5 Insights</div>
                        <span class="text-xs text-muted-foreground">
                            Pregame point-in-time form
                        </span>
                    </div>

                    <div
                        v-for="insight in periodInsights(prediction)"
                        :key="insight.market_type"
                        class="mt-4 border-t pt-4 first:mt-3"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <div class="flex items-center gap-2">
                                <span class="font-semibold">{{
                                    insight.label
                                }}</span>
                                <span class="text-sm text-muted-foreground">
                                    {{ periodLeanLabel(insight) }}
                                </span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <span
                                    class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                                    :class="periodStateClass(insight.state)"
                                >
                                    {{ periodStateLabel(insight.state) }}
                                </span>
                                <span
                                    class="rounded-full border px-2.5 py-1 text-xs font-semibold text-muted-foreground"
                                >
                                    {{
                                        labelizeMlbCode(
                                            insight.confidence.level,
                                        )
                                    }}
                                    ·
                                    {{ insight.confidence.sample_games }} games
                                </span>
                            </div>
                        </div>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div class="border-l-2 border-sky-500/50 pl-3">
                                <div class="text-xs text-muted-foreground">
                                    {{
                                        teamAbbreviation(
                                            prediction.game?.away_team,
                                        )
                                    }}
                                    away
                                </div>
                                <div class="mt-1 font-semibold">
                                    {{ periodRecord(insight.away) }}
                                    ·
                                    {{
                                        formatPercent(
                                            insight.away.win_probability,
                                        )
                                    }}
                                </div>
                                <div class="mt-1 text-xs text-muted-foreground">
                                    L10
                                    {{
                                        formatPercent(
                                            insight.away.last_10
                                                .win_probability,
                                        )
                                    }}
                                    · RD/G
                                    {{
                                        signedNumber(
                                            insight.away
                                                .run_difference_per_game,
                                        )
                                    }}
                                    · Tie
                                    {{ formatPercent(insight.away.tie_rate) }}
                                </div>
                            </div>
                            <div class="border-l-2 border-emerald-500/50 pl-3">
                                <div class="text-xs text-muted-foreground">
                                    {{
                                        teamAbbreviation(
                                            prediction.game?.home_team,
                                        )
                                    }}
                                    home
                                </div>
                                <div class="mt-1 font-semibold">
                                    {{ periodRecord(insight.home) }}
                                    ·
                                    {{
                                        formatPercent(
                                            insight.home.win_probability,
                                        )
                                    }}
                                </div>
                                <div class="mt-1 text-xs text-muted-foreground">
                                    L10
                                    {{
                                        formatPercent(
                                            insight.home.last_10
                                                .win_probability,
                                        )
                                    }}
                                    · RD/G
                                    {{
                                        signedNumber(
                                            insight.home
                                                .run_difference_per_game,
                                        )
                                    }}
                                    · Tie
                                    {{ formatPercent(insight.home.tie_rate) }}
                                </div>
                            </div>
                        </div>

                        <div
                            class="mt-3 grid grid-cols-2 gap-3 border-t pt-3 text-sm sm:grid-cols-4"
                        >
                            <div>
                                <div class="text-xs text-muted-foreground">
                                    Home Elo lean
                                </div>
                                <div class="font-semibold">
                                    {{
                                        formatPercent(
                                            insight.lean
                                                .two_way_home_probability,
                                        )
                                    }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-muted-foreground">
                                    Period Elo diff
                                </div>
                                <div class="font-semibold">
                                    {{
                                        signedNumber(
                                            insight.lean.period_elo_difference,
                                            1,
                                        )
                                    }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-muted-foreground">
                                    Starter diff
                                </div>
                                <div class="font-semibold">
                                    {{
                                        signedNumber(
                                            insight.starter_context
                                                .rating_difference,
                                            1,
                                        )
                                    }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-muted-foreground">
                                    Market
                                </div>
                                <div class="font-semibold">
                                    {{
                                        insight.market_available
                                            ? 'Captured'
                                            : 'Missing'
                                    }}
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="
                                insight.signals.length ||
                                insight.risk_flags.length
                            "
                            class="mt-3 flex flex-wrap gap-2"
                        >
                            <span
                                v-for="signal in insight.signals"
                                :key="signal"
                                class="rounded-full border border-emerald-500/25 bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:text-emerald-300"
                            >
                                {{ periodSignalLabel(signal) }}
                            </span>
                            <span
                                v-for="risk in insight.risk_flags"
                                :key="risk"
                                class="rounded-full border border-amber-500/25 bg-amber-500/10 px-2.5 py-1 text-xs font-medium text-amber-700 dark:text-amber-300"
                            >
                                {{ periodRiskLabel(risk) }}
                            </span>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border p-4">
                    <div class="mb-4">
                        <div class="text-sm font-semibold">Model vs Market</div>
                        <p class="mt-1 text-sm leading-6 text-muted-foreground">
                            This compares our model to sportsbook odds. The
                            blended read leans the model back toward the market,
                            and edge is the possible value gap we are tracking.
                        </p>
                    </div>
                    <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">
                                Model says
                            </div>
                            <div class="mt-1 font-semibold">
                                {{
                                    formatPercent(
                                        candidate?.model_probability ??
                                            prediction.market_aware_projection
                                                ?.model_probability ??
                                            numberValue(
                                                prediction.projection
                                                    ?.home_win_probability,
                                            ),
                                    )
                                }}
                            </div>
                            <div class="mt-1 text-[11px] text-muted-foreground">
                                {{ modelSourceLabel(candidate) }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">
                                Market says
                            </div>
                            <div class="mt-1 font-semibold">
                                {{
                                    formatPercent(
                                        candidate?.market_probability ??
                                            prediction.market_aware_projection
                                                ?.market_probability ??
                                            candidateRecommendation(prediction)
                                                ?.no_vig_implied_probability,
                                    )
                                }}
                            </div>
                            <div class="mt-1 text-[11px] text-muted-foreground">
                                What the odds imply.
                            </div>
                        </div>
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">
                                Blended read
                            </div>
                            <div class="mt-1 font-semibold">
                                {{
                                    formatPercent(
                                        candidate?.blend_probability ??
                                            prediction.market_aware_projection
                                                ?.blended_probability,
                                    )
                                }}
                            </div>
                            <div class="mt-1 text-[11px] text-muted-foreground">
                                Model plus market caution.
                            </div>
                        </div>
                        <div class="rounded-xl bg-muted/45 p-3">
                            <div class="text-xs text-muted-foreground">
                                Edge
                            </div>
                            <div class="mt-1 font-semibold">
                                {{
                                    formatSignedPercent(
                                        candidate?.edge_no_vig ??
                                            candidate?.edge_raw ??
                                            candidateRecommendation(prediction)
                                                ?.no_vig_edge ??
                                            candidateRecommendation(prediction)
                                                ?.raw_edge,
                                    )
                                }}
                            </div>
                            <div class="mt-1 text-[11px] text-muted-foreground">
                                Positive means possible value, not a guarantee.
                            </div>
                        </div>
                    </div>

                    <div
                        class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4"
                    >
                        <div>
                            <div class="text-muted-foreground">
                                Model margin
                            </div>
                            <div class="font-semibold">
                                {{
                                    formatNumber(
                                        numberValue(
                                            prediction.projection
                                                ?.predicted_spread,
                                        ),
                                    )
                                }}
                            </div>
                        </div>
                        <div>
                            <div class="text-muted-foreground">Model total</div>
                            <div class="font-semibold">
                                {{
                                    formatNumber(
                                        numberValue(
                                            prediction.projection
                                                ?.predicted_total,
                                        ),
                                    )
                                }}
                            </div>
                        </div>
                        <div>
                            <div class="text-muted-foreground">Odds price</div>
                            <div class="font-semibold">
                                {{ formatOdds(candidate?.price) }}
                            </div>
                        </div>
                        <div>
                            <div class="text-muted-foreground">Market line</div>
                            <div class="font-semibold">
                                {{ formatNumber(candidate?.line) }}
                            </div>
                        </div>
                    </div>
                </section>

                <section
                    v-if="periodModels(prediction, candidate).length"
                    class="rounded-2xl border p-4"
                >
                    <div class="text-sm font-semibold">
                        F3/F5 Model Tracking
                    </div>
                    <div
                        v-for="model in periodModels(prediction, candidate)"
                        :key="`${model.market_type}-${model.lineage.artifact_id}`"
                        class="mt-4 border-t pt-4 first:mt-3"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
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

                        <div
                            class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4"
                        >
                            <div>
                                <div class="text-muted-foreground">Home</div>
                                <div class="font-semibold">
                                    {{
                                        formatPercent(
                                            model.probabilities.home_win,
                                        )
                                    }}
                                </div>
                            </div>
                            <div>
                                <div class="text-muted-foreground">Away</div>
                                <div class="font-semibold">
                                    {{
                                        formatPercent(
                                            model.probabilities.away_win,
                                        )
                                    }}
                                </div>
                            </div>
                            <div>
                                <div class="text-muted-foreground">Tie</div>
                                <div class="font-semibold">
                                    {{ formatPercent(model.probabilities.tie) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-muted-foreground">
                                    Uncertainty
                                </div>
                                <div class="font-semibold">
                                    {{ formatPercent(model.uncertainty) }}
                                </div>
                            </div>
                        </div>

                        <div
                            class="mt-3 grid gap-2 border-t pt-3 text-xs sm:grid-cols-2"
                        >
                            <div>
                                <span class="text-muted-foreground"
                                    >Artifact
                                </span>
                                <span
                                    class="font-mono"
                                    :title="model.lineage.artifact_id ?? ''"
                                    >{{
                                        compactId(model.lineage.artifact_id)
                                    }}</span
                                >
                            </div>
                            <div>
                                <span class="text-muted-foreground"
                                    >Model run
                                </span>
                                <span
                                    class="font-mono"
                                    :title="model.lineage.model_run_id ?? ''"
                                    >{{
                                        compactId(model.lineage.model_run_id)
                                    }}</span
                                >
                            </div>
                            <div>
                                <span class="text-muted-foreground"
                                    >Dataset
                                </span>
                                <span
                                    class="font-mono"
                                    :title="model.lineage.dataset_hash ?? ''"
                                    >{{
                                        compactId(model.lineage.dataset_hash)
                                    }}</span
                                >
                            </div>
                            <div>
                                <span class="text-muted-foreground"
                                    >Feature hash
                                </span>
                                <span
                                    class="font-mono"
                                    :title="model.lineage.feature_hash ?? ''"
                                    >{{
                                        compactId(model.lineage.feature_hash)
                                    }}</span
                                >
                            </div>
                        </div>

                        <div
                            v-if="model.decision"
                            class="mt-3 border-t pt-3 text-sm"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <span class="font-semibold">{{
                                    labelizeMlbCode(model.decision.status)
                                }}</span>
                                <span>
                                    Edge
                                    {{
                                        formatSignedPercent(model.decision.edge)
                                    }}
                                    · EV
                                    {{
                                        formatSignedPercent(
                                            model.decision.expected_value,
                                        )
                                    }}
                                </span>
                            </div>
                            <div
                                v-if="model.decision.eligibility_reasons.length"
                                class="mt-2 flex flex-wrap gap-2"
                            >
                                <span
                                    v-for="reason in model.decision
                                        .eligibility_reasons"
                                    :key="reason"
                                    class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-1 text-xs text-amber-700 dark:text-amber-300"
                                >
                                    {{ labelizeMlbCode(reason) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </section>

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
