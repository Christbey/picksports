<script setup lang="ts">
import { ChevronRight, Clock } from 'lucide-vue-next';
import { computed } from 'vue';
import {
    labelizeMlbCode,
    safeMlbPickStatus,
    tierFromScore,
} from '@/lib/mlbRecommendationLabels';
import {
    predictionTiming,
    predictionTimingBadgeClass,
} from '@/lib/predictionTiming';
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
const marketAware = computed(
    () => props.prediction.market_aware_projection ?? null,
);
const timing = computed(() => predictionTiming(props.prediction));
const showTimingBadge = computed(() => timing.value.phase === 'live');

const projectedPickAbbreviation = computed(() => {
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

const projectedPickLabel = computed(() => {
    const candidateTeam = props.candidate?.team;
    if (candidateTeam) {
        return (
            candidateTeam.display_name ??
            candidateTeam.abbreviation ??
            'Pending'
        );
    }

    const abbreviation = projectedPickAbbreviation.value;
    if (!abbreviation || abbreviation === 'Pending') return 'Pending';

    const matchingTeam = [awayTeam.value, homeTeam.value].find(
        (team) => teamAbbreviation(team) === abbreviation,
    );

    return matchingTeam ? teamShortName(matchingTeam) : abbreviation;
});

const statusBadge = computed(() => {
    const status = String(
        props.prediction.status ?? game.value?.status ?? '',
    ).toLowerCase();
    if (status.includes('final')) return 'Final';
    if (status.includes('in_progress') || status.includes('live'))
        return 'Live';
    if (status.includes('postponed')) return 'Postponed';

    return 'Pregame';
});

const cardTone = computed(() => {
    if (hasPositiveResult.value) return 'positive';
    if (hasNegativeResult.value) return 'negative';
    if (!props.candidate) return 'quiet';
    if (props.candidate.is_public && !props.candidate.is_tracking_only) {
        return 'candidate';
    }

    return 'tracking';
});

const statusLabel = computed(() => {
    if (!props.candidate) return null;

    if (props.candidate.is_tracking_only || !props.candidate.is_public) {
        return `${tierFromScore(props.candidate.score)} signal`;
    }

    return safeMlbPickStatus(props.candidate);
});

const statusLabelClass = computed(() => {
    if (!props.candidate)
        return 'border-muted bg-muted/40 text-muted-foreground';

    if (hasPositiveResult.value) {
        return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    }

    if (props.candidate.is_tracking_only || !props.candidate.is_public) {
        return 'border-slate-500/25 bg-slate-500/10 text-slate-700 dark:text-slate-300';
    }

    return 'border-sky-500/25 bg-sky-500/10 text-sky-700 dark:text-sky-300';
});

const scoreTextClass = computed(() => {
    if (!props.candidate) return 'text-muted-foreground';

    if (hasPositiveResult.value) return 'text-emerald-500';
    if (hasNegativeResult.value) return 'text-red-500';

    if (props.candidate.is_public && !props.candidate.is_tracking_only) {
        return 'text-sky-500';
    }

    if (props.candidate.score >= 80) return 'text-sky-500';
    if (props.candidate.score >= 68) return 'text-amber-500';

    return 'text-muted-foreground';
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
    if (modelResult.value === 'win') return 'Projection won';
    if (modelResult.value === 'loss') return 'Projection lost';
    if (modelResult.value === 'ungraded') return 'Ungraded';

    return null;
});

const finalScoreLabel = computed(() => {
    if (!isFinal.value) return null;

    const awayScore = numberValue(game.value?.away_score);
    const homeScore = numberValue(game.value?.home_score);

    if (awayScore == null || homeScore == null) return 'Final';

    return `${teamAbbreviation(awayTeam.value)} ${awayScore} - ${teamAbbreviation(homeTeam.value)} ${homeScore}`;
});

const totalResultLabel = computed(() => {
    if (!isFinal.value) return null;

    const side = String(props.prediction.total_pick_side ?? '');
    const result = String(props.prediction.total_pick_result ?? '');

    if (!side || !result) return null;

    return `${labelizeMlbCode(side)} ${labelizeMlbCode(result)}`;
});

const totalPickResult = computed(() => {
    if (!isFinal.value) return null;

    const result = String(props.prediction.total_pick_result ?? '')
        .trim()
        .toLowerCase();

    return result || null;
});

const hasPositiveResult = computed(
    () =>
        modelResult.value === 'win' ||
        totalPickResult.value === 'win' ||
        props.candidate?.result_status === 'win',
);

const hasNegativeResult = computed(
    () =>
        modelResult.value === 'loss' ||
        totalPickResult.value === 'loss' ||
        props.candidate?.result_status === 'loss',
);

const totalScoreLabel = computed(() => {
    if (!isFinal.value || !totalResultLabel.value) return null;

    const line = numberValue(props.prediction.total_pick_line);
    const actual = numberValue(props.prediction.actual_total);

    if (line == null || actual == null) return totalResultLabel.value;

    return `${totalResultLabel.value} ${line.toFixed(1)} | Actual ${actual.toFixed(1)}`;
});

const candidateMarketLabel = computed(() => {
    if (!props.candidate) return null;

    return labelizeMlbCode(props.candidate.market_type);
});

const candidateDetailLabel = computed(() => {
    if (!props.candidate) return null;

    const edge = numberValue(
        props.candidate.edge_no_vig ?? props.candidate.edge_raw,
    );
    if (edge != null) {
        return `Edge ${formatSignedPercent(edge)}`;
    }

    const side = props.candidate.side
        ? labelizeMlbCode(props.candidate.side)
        : null;

    return side ?? 'Tracked candidate';
});

const footerContextLabel = computed(() => {
    if (candidateMarketLabel.value) return candidateMarketLabel.value;

    if (marketAware.value?.agreement_status === 'market_missing') {
        return 'Market pending';
    }

    return 'Projection only';
});

const actionTextClass = computed(() => {
    if (hasPositiveResult.value) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    if (hasNegativeResult.value) {
        return 'text-red-600 dark:text-red-400';
    }

    return 'text-sky-600 dark:text-sky-400';
});

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

function teamShortName(team: unknown): string {
    const payload = (team ?? {}) as ApiV2Record;

    return String(
        payload.short_display_name ??
            payload.name ??
            payload.display_name ??
            teamAbbreviation(team),
    );
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

function formatSignedPercent(value: number): string {
    const percent = value * 100;

    return `${percent > 0 ? '+' : ''}${percent.toFixed(1)}%`;
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
</script>

<template>
    <button
        type="button"
        class="group relative flex min-h-[124px] w-full overflow-hidden rounded-2xl border p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md focus:ring-2 focus:ring-sky-500/35 focus:outline-none"
        :class="
            cardTone === 'quiet'
                ? 'border-border/70 bg-card/80 hover:border-sky-500/30'
                : cardTone === 'positive'
                  ? 'border-emerald-500/45 bg-emerald-950/[0.04] ring-1 ring-emerald-500/15 dark:bg-emerald-500/[0.06]'
                  : cardTone === 'negative'
                    ? 'border-red-500/35 bg-red-950/[0.03] dark:bg-red-500/[0.05]'
                    : cardTone === 'candidate'
                      ? 'border-sky-500/35 bg-sky-950/[0.03] dark:bg-sky-500/[0.05]'
                      : 'border-border/80 bg-card/85 hover:border-sky-500/30'
        "
        @click="emit('select', prediction, candidate ?? null)"
    >
        <div
            class="absolute inset-y-0 left-0 w-1"
            :class="
                cardTone === 'quiet'
                    ? 'bg-slate-500/40'
                    : cardTone === 'positive'
                      ? 'bg-emerald-500'
                      : cardTone === 'negative'
                        ? 'bg-red-500'
                        : cardTone === 'candidate'
                          ? 'bg-sky-500'
                          : 'bg-slate-500/50'
            "
        />

        <div class="flex min-w-0 flex-1 flex-col gap-3 pl-1">
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
                        <span
                            class="inline-flex items-center gap-1 rounded-full border bg-background/75 px-2.5 py-1 text-xs text-muted-foreground"
                        >
                            <Clock class="h-3.5 w-3.5" />
                            {{ formatTime(game?.game_date, game?.game_time) }}
                        </span>
                        <span
                            class="rounded-full border bg-background/75 px-2.5 py-1 text-xs font-semibold text-muted-foreground"
                        >
                            {{ statusBadge }}
                        </span>
                        <span
                            v-if="showTimingBadge"
                            class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                            :class="predictionTimingBadgeClass(timing)"
                        >
                            {{ timing.label }}
                        </span>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <span
                            class="rounded-full border border-sky-500/25 bg-sky-500/10 px-2.5 py-1 text-xs font-semibold text-sky-700 dark:text-sky-300"
                        >
                            Projected pick: {{ projectedPickLabel }}
                        </span>
                        <span
                            v-if="statusLabel"
                            class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                            :class="statusLabelClass"
                        >
                            {{ statusLabel }}
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
                            :class="
                                resultBadgeClass(prediction.total_pick_result)
                            "
                        >
                            {{ totalResultLabel }}
                        </span>
                    </div>
                </div>

                <div
                    v-if="candidate"
                    class="grid h-12 w-12 shrink-0 place-items-center rounded-xl border bg-background/80"
                >
                    <div class="text-center">
                        <div
                            class="text-base font-black"
                            :class="scoreTextClass"
                        >
                            {{ candidate.score }}
                        </div>
                        <div
                            class="text-[9px] font-semibold text-muted-foreground uppercase"
                        >
                            Score
                        </div>
                    </div>
                </div>
            </div>

            <div
                class="flex flex-wrap items-center justify-between gap-3 border-t pt-3"
            >
                <div class="min-w-0 text-xs text-muted-foreground">
                    <span
                        v-if="finalScoreLabel"
                        class="font-semibold text-foreground"
                    >
                        {{ finalScoreLabel }}
                    </span>
                    <span
                        v-if="
                            finalScoreLabel &&
                            (totalScoreLabel || candidateMarketLabel)
                        "
                    >
                        ·
                    </span>
                    <span v-if="totalScoreLabel">
                        {{ totalScoreLabel }}
                    </span>
                    <span v-else>
                        {{ footerContextLabel }}
                        <span v-if="candidateDetailLabel">
                            · {{ candidateDetailLabel }}</span
                        >
                    </span>
                </div>

                <span
                    class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold"
                    :class="actionTextClass"
                >
                    Open
                    <ChevronRight
                        class="h-3.5 w-3.5 transition group-hover:translate-x-0.5"
                    />
                </span>
            </div>
        </div>
    </button>
</template>
