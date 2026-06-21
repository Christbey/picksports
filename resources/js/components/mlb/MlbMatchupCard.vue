<script setup lang="ts">
import {
    AlertTriangle,
    BarChart3,
    ChevronRight,
    Clock,
    ShieldCheck,
    Sparkles,
} from 'lucide-vue-next';
import { computed } from 'vue';
import {
    candidateRecommendation,
    getPredictionRecommendation,
} from '@/lib/predictionRecommendation';
import {
    labelizeMlbCode,
    safeMlbPickStatus,
    tierFromScore,
} from '@/lib/mlbRecommendationLabels';
import type { ApiV2Prediction, ApiV2Record } from '@/types';
import type { MlbDailyPick } from '@/types/mlb-daily-picks';

const props = defineProps<{
    prediction: ApiV2Prediction;
    candidate?: MlbDailyPick | null;
}>();

const emit = defineEmits<{
    select: [prediction: ApiV2Prediction, candidate: MlbDailyPick | null];
}>();

const game = computed(() => props.prediction.game ?? null);
const awayTeam = computed(() => game.value?.away_team ?? null);
const homeTeam = computed(() => game.value?.home_team ?? null);
const projection = computed(() => props.prediction.projection ?? {});
const marketAware = computed(() => props.prediction.market_aware_projection ?? null);
const publicRecommendation = computed(() =>
    getPredictionRecommendation(props.prediction),
);
const trackingRecommendation = computed(() =>
    candidateRecommendation(props.prediction),
);

const hasCandidate = computed(() => Boolean(props.candidate));

const matchupLabel = computed(() => {
    const away = teamAbbreviation(awayTeam.value) || 'Away';
    const home = teamAbbreviation(homeTeam.value) || 'Home';

    return `${away} @ ${home}`;
});

const modelPickLabel = computed(() => {
    if (props.candidate?.team?.abbreviation) {
        return props.candidate.team.abbreviation;
    }

    const modelPick = marketAware.value?.model_pick;
    if (modelPick?.team_abbreviation) {
        return modelPick.team_abbreviation;
    }

    const projectionPick = marketAware.value?.projection_pick;
    if (projectionPick?.team_abbreviation) {
        return projectionPick.team_abbreviation;
    }

    const homeProbability = numberValue(projection.value.home_win_probability);
    if (homeProbability != null) {
        return homeProbability >= 0.5
            ? teamAbbreviation(homeTeam.value)
            : teamAbbreviation(awayTeam.value);
    }

    return 'Pending';
});

const statusBadge = computed(() => {
    const status = String(props.prediction.status ?? game.value?.status ?? '').toLowerCase();
    if (status.includes('final')) return 'Final';
    if (status.includes('in_progress') || status.includes('live')) return 'Live';
    if (status.includes('postponed')) return 'Postponed';

    return 'Pregame';
});

const cardTone = computed(() => {
    if (!props.candidate) return 'quiet';
    if (props.candidate.score >= 80) return 'strong';
    if (props.candidate.score >= 68) return 'candidate';

    return 'watch';
});

const statusLabel = computed(() => {
    if (props.candidate) return safeMlbPickStatus(props.candidate);
    if (marketAware.value?.agreement_status === 'market_missing') {
        return 'Waiting for market data';
    }

    return 'No Public Pick';
});

const isFinal = computed(() =>
    String(props.prediction.status ?? game.value?.status ?? '')
        .toLowerCase()
        .includes('final'),
);

const modelResult = computed<'win' | 'loss' | 'ungraded' | null>(() => {
    if (!isFinal.value) return null;

    if (props.prediction.winner_correct === true) return 'win';
    if (props.prediction.winner_correct === false) return 'loss';

    return 'ungraded';
});

const modelResultLabel = computed(() => {
    if (modelResult.value === 'win') return 'Model Win';
    if (modelResult.value === 'loss') return 'Model Loss';
    if (modelResult.value === 'ungraded') return 'Ungraded';

    return null;
});

const finalScoreLabel = computed(() => {
    if (!isFinal.value) return null;

    const awayScore = numberValue(game.value?.away_score);
    const homeScore = numberValue(game.value?.home_score);

    if (awayScore == null || homeScore == null) return 'Final';

    return `Final ${teamAbbreviation(awayTeam.value)} ${awayScore} - ${teamAbbreviation(homeTeam.value)} ${homeScore}`;
});

const totalResultLabel = computed(() => {
    if (!isFinal.value) return null;

    const side = String(props.prediction.total_pick_side ?? '');
    const result = String(props.prediction.total_pick_result ?? '');

    if (!side || !result) return null;

    return `${labelizeMlbCode(side)} ${labelizeMlbCode(result)}`;
});

const totalScoreLabel = computed(() => {
    if (!isFinal.value || !totalResultLabel.value) return null;

    const line = numberValue(props.prediction.total_pick_line);
    const actual = numberValue(props.prediction.actual_total);

    if (line == null || actual == null) return totalResultLabel.value;

    return `Total ${labelizeMlbCode(String(props.prediction.total_pick_side))} ${line.toFixed(1)} · Actual ${actual.toFixed(1)}`;
});

const contextCopy = computed(() => {
    if (props.candidate?.explanation) {
        return props.candidate.explanation;
    }

    if (marketAware.value?.agreement_status === 'market_missing') {
        return 'Odds are unavailable or not pregame-safe for this matchup.';
    }

    if (trackingRecommendation.value?.no_bet_reason) {
        return labelizeMlbCode(trackingRecommendation.value.no_bet_reason);
    }

    return 'This matchup did not clear the candidate threshold.';
});

const topReasons = computed(() => {
    const reasons =
        props.candidate?.reason_codes ??
        trackingRecommendation.value?.reason_codes ??
        publicRecommendation.value?.reason_codes ??
        [];

    const baseReasons = reasons.length > 0 ? reasons : ['model_projection'];

    return baseReasons.slice(0, 3).map(labelizeMlbCode);
});

const topRisks = computed(() => {
    const risks = [
        ...(props.candidate?.risk_flags ?? []),
        ...(trackingRecommendation.value?.risk_flags ?? []),
        ...(publicRecommendation.value?.risk_flags ?? []),
    ];

    if (!props.candidate) {
        risks.push('no_public_pick');
    }

    if (
        marketAware.value?.agreement_status === 'market_missing' ||
        marketAware.value?.market_probability == null
    ) {
        risks.push('market_data_unavailable');
    }

    if (marketAware.value?.point_in_time_status === 'unsafe') {
        risks.push(...(marketAware.value.point_in_time_reasons ?? []));
    }

    return Array.from(new Set(risks)).slice(0, 3).map(labelizeMlbCode);
});

const metricRow = computed(() => {
    const modelProbability =
        props.candidate?.model_probability ??
        sideProbability('model') ??
        marketAware.value?.model_probability ??
        numberValue(projection.value.home_win_probability);

    const marketProbability =
        props.candidate?.market_probability ??
        sideProbability('market') ??
        marketAware.value?.market_probability ??
        trackingRecommendation.value?.no_vig_implied_probability ??
        trackingRecommendation.value?.raw_implied_probability;

    const blendProbability =
        props.candidate?.blend_probability ??
        sideProbability('blend') ??
        marketAware.value?.blended_probability;

    const edge =
        props.candidate?.edge_no_vig ??
        props.candidate?.edge_raw ??
        trackingRecommendation.value?.no_vig_edge ??
        trackingRecommendation.value?.raw_edge;

    return [
        { label: 'Model', value: formatPercent(modelProbability) },
        { label: 'Market', value: formatPercent(marketProbability) },
        { label: 'Blend', value: formatPercent(blendProbability) },
        { label: 'Edge', value: formatSignedPercent(edge) },
    ];
});

const scoreLabel = computed(() =>
    props.candidate ? tierFromScore(props.candidate.score) : null,
);

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

function teamName(team: unknown): string {
    const payload = (team ?? {}) as ApiV2Record;

    return String(
        payload.display_name ??
            payload.location ??
            payload.name ??
            teamAbbreviation(team),
    );
}

function sideProbability(kind: 'model' | 'market' | 'blend'): number | null {
    const side = marketAware.value?.model_pick?.side;
    if (side !== 'home' && side !== 'away') return null;

    const key =
        kind === 'model'
            ? `${side}_${kind}_probability`
            : kind === 'market'
              ? `${side}_${kind}_probability`
              : `${side}_blended_probability`;

    return numberValue(marketAware.value?.[key]);
}

function formatTime(value?: string | null, time?: string | null): string {
    if (!value) return 'Time pending';

    const date = gameDateTime(value, time);

    return new Intl.DateTimeFormat(undefined, {
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

function formatPercent(value?: number | null): string {
    if (value == null) return '-';

    return `${(value * 100).toFixed(1)}%`;
}

function formatSignedPercent(value?: number | null): string {
    if (value == null) return '-';

    const percent = value * 100;

    return `${percent > 0 ? '+' : ''}${percent.toFixed(1)}%`;
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
</script>

<template>
    <button
        type="button"
        class="group relative flex h-full min-h-[160px] w-full overflow-hidden rounded-2xl border text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-emerald-500/35"
        :class="[
            cardTone === 'quiet'
                ? 'border-border/70 bg-card/80 hover:border-sky-500/30'
                : cardTone === 'strong'
                  ? 'border-emerald-500/45 bg-emerald-950/[0.04] ring-1 ring-emerald-500/15 dark:bg-emerald-500/[0.06]'
                  : 'border-sky-500/35 bg-sky-950/[0.03] dark:bg-sky-500/[0.05]',
            hasCandidate ? 'p-4 md:p-5' : 'p-4',
        ]"
        @click="emit('select', prediction, candidate ?? null)"
    >
        <div
            class="absolute inset-y-0 left-0 w-1"
            :class="[
                cardTone === 'quiet'
                    ? 'bg-slate-500/40'
                    : cardTone === 'strong'
                      ? 'bg-emerald-500'
                      : 'bg-sky-500',
            ]"
        />

        <div class="flex min-w-0 flex-1 flex-col gap-4 pl-1">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <div class="flex items-center gap-2 font-semibold">
                            <img
                                v-if="awayTeam?.logo_url"
                                :src="awayTeam.logo_url"
                                :alt="teamName(awayTeam)"
                                class="h-6 w-6 rounded-full object-contain"
                            />
                            <span>{{ teamAbbreviation(awayTeam) }}</span>
                            <span class="text-muted-foreground">@</span>
                            <img
                                v-if="homeTeam?.logo_url"
                                :src="homeTeam.logo_url"
                                :alt="teamName(homeTeam)"
                                class="h-6 w-6 rounded-full object-contain"
                            />
                            <span>{{ teamAbbreviation(homeTeam) }}</span>
                        </div>
                        <span class="inline-flex items-center gap-1 rounded-full border bg-background/75 px-2.5 py-1 text-xs text-muted-foreground">
                            <Clock class="h-3.5 w-3.5" />
                            {{ formatTime(game?.game_date, game?.game_time) }}
                        </span>
                        <span class="rounded-full border bg-background/75 px-2.5 py-1 text-xs font-semibold text-muted-foreground">
                            {{ statusBadge }}
                        </span>
                        <span
                            v-if="modelResultLabel"
                            class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                            :class="resultBadgeClass(modelResult)"
                        >
                            {{ modelResultLabel }}
                        </span>
                        <span
                            v-if="totalResultLabel"
                            class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                            :class="resultBadgeClass(prediction.total_pick_result)"
                        >
                            {{ totalResultLabel }}
                        </span>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <span class="rounded-full border border-sky-500/25 bg-sky-500/10 px-2.5 py-1 text-xs font-semibold text-sky-700 dark:text-sky-300">
                            Model Pick: {{ modelPickLabel }}
                        </span>
                        <span
                            class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                            :class="
                                hasCandidate
                                    ? 'border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                    : 'border-muted bg-muted/40 text-muted-foreground'
                            "
                        >
                            {{ statusLabel }}
                        </span>
                    </div>
                </div>

                <div
                    v-if="candidate"
                    class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl border bg-background/80"
                >
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

            <div class="grid gap-2 sm:grid-cols-4">
                <div
                    v-if="finalScoreLabel"
                    class="rounded-xl border bg-background/65 px-3 py-2 sm:col-span-4"
                >
                    <div class="text-[11px] font-semibold uppercase text-muted-foreground">
                        Result
                    </div>
                    <div class="mt-1 text-sm font-bold">
                        {{ finalScoreLabel }}
                    </div>
                    <div
                        v-if="totalScoreLabel"
                        class="mt-1 text-xs font-semibold text-muted-foreground"
                    >
                        {{ totalScoreLabel }}
                    </div>
                </div>

                <div
                    v-for="item in metricRow"
                    :key="item.label"
                    class="rounded-xl border bg-background/65 px-3 py-2"
                >
                    <div class="text-[11px] font-semibold uppercase text-muted-foreground">
                        {{ item.label }}
                    </div>
                    <div class="mt-1 text-sm font-bold">
                        {{ item.value }}
                    </div>
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                <div class="min-w-0">
                    <div class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                        <ShieldCheck class="h-3.5 w-3.5" />
                        Reasons
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <span
                            v-for="reason in topReasons"
                            :key="reason"
                            class="rounded-full border bg-background/70 px-2 py-0.5 text-xs text-muted-foreground"
                        >
                            {{ reason }}
                        </span>
                    </div>
                </div>

                <div class="min-w-0">
                    <div class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-amber-600 dark:text-amber-400">
                        <AlertTriangle class="h-3.5 w-3.5" />
                        Risks
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <span
                            v-for="risk in topRisks"
                            :key="risk"
                            class="rounded-full border border-amber-500/25 bg-amber-500/10 px-2 py-0.5 text-xs text-amber-700 dark:text-amber-300"
                        >
                            {{ risk }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="mt-auto flex flex-wrap items-center justify-between gap-3 border-t pt-3">
                <div class="flex min-w-0 items-center gap-2 text-xs text-muted-foreground">
                    <component
                        :is="hasCandidate ? Sparkles : BarChart3"
                        class="h-3.5 w-3.5 shrink-0"
                    />
                    <span class="truncate">
                        {{ candidate ? scoreLabel : contextCopy }}
                    </span>
                </div>
                <span class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                    Open Matchup
                    <ChevronRight class="h-3.5 w-3.5 transition group-hover:translate-x-0.5" />
                </span>
            </div>
        </div>
    </button>
</template>
