<script setup lang="ts">
import { ChevronRight, Clock } from 'lucide-vue-next';
import { computed } from 'vue';
import {
    predictionTiming,
    predictionTimingBadgeClass,
} from '@/lib/predictionTiming';
import type { ApiV2Prediction, ApiV2Record } from '@/types';

const props = defineProps<{
    prediction: ApiV2Prediction;
}>();

const emit = defineEmits<{
    select: [prediction: ApiV2Prediction];
}>();

const game = computed(() => props.prediction.game ?? null);
const awayTeam = computed(() => game.value?.away_team ?? null);
const homeTeam = computed(() => game.value?.home_team ?? null);
const proLayer = computed<ApiV2Record>(
    () => (props.prediction.pro_signal_layer as ApiV2Record | null) ?? {},
);
const predictionAnalysis = computed<ApiV2Record>(
    () => (props.prediction.prediction_analysis as ApiV2Record | null) ?? {},
);
const marketContext = computed<ApiV2Record>(
    () => (proLayer.value.market_context as ApiV2Record | null) ?? {},
);
const timing = computed(() => predictionTiming(props.prediction));
const showTimingBadge = computed(() => timing.value.phase === 'live');

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

const cardTone = computed(() => {
    if (modelResult.value === 'win') return 'positive';
    if (modelResult.value === 'loss') return 'negative';

    const classification = betClassification.value;
    if (classification === 'bet') return 'candidate';
    if (['lean', 'validated_winner_watchlist'].includes(classification)) {
        return 'tracking';
    }

    return 'quiet';
});

const awayScore = computed(() => numberValue(game.value?.away_score));
const homeScore = computed(() => numberValue(game.value?.home_score));
const showGameScore = computed(
    () =>
        (isFinal.value || statusBadge.value === 'Live') &&
        awayScore.value != null &&
        homeScore.value != null,
);

const pickSide = computed(() =>
    String(marketContext.value.pick_side ?? props.prediction.pick?.side ?? ''),
);

const projectedPickLabel = computed(() => {
    const side = pickSide.value === 'away' ? 'away' : 'home';
    const team = side === 'away' ? awayTeam.value : homeTeam.value;

    return teamShortName(team) || 'Pending';
});

const winnerTier = computed(() =>
    String(
        (
            (proLayer.value.market_scores as ApiV2Record | null)
                ?.winner as ApiV2Record | null
        )?.tier ??
            proLayer.value.tier ??
            '',
    ),
);

const spreadTier = computed(() =>
    String(
        (
            (proLayer.value.market_scores as ApiV2Record | null)
                ?.spread as ApiV2Record | null
        )?.tier ?? '',
    ),
);

const totalTier = computed(() =>
    String(
        (
            (proLayer.value.market_scores as ApiV2Record | null)
                ?.total as ApiV2Record | null
        )?.tier ?? '',
    ),
);

const betClassification = computed(() =>
    String(
        predictionAnalysis.value.bet_classification ??
            proLayer.value.bet_classification ??
            proLayer.value.classification ??
            '',
    )
        .trim()
        .toLowerCase(),
);

const classificationLabel = computed(() => {
    const layerClassification = String(
        predictionAnalysis.value.bet_classification ??
            proLayer.value.bet_classification ??
            '',
    );

    if (layerClassification) return labelize(layerClassification);
    if (betClassification.value) return labelize(betClassification.value);

    return null;
});

const modelResultLabel = computed(() => {
    if (modelResult.value === 'win') return 'Projection won';
    if (modelResult.value === 'loss') return 'Projection lost';
    if (modelResult.value === 'ungraded') return 'Ungraded';

    return null;
});

const spreadEdge = computed(() => numberValue(marketContext.value.spread_edge));
const totalEdge = computed(() => numberValue(marketContext.value.total_edge));
const marketSpread = computed(() =>
    numberValue(marketContext.value.market_spread),
);
const marketTotal = computed(() =>
    numberValue(marketContext.value.market_total),
);
const trustScore = computed(
    () =>
        numberValue(predictionAnalysis.value.trust_score) ??
        numberValue(proLayer.value.score) ??
        numberValue(props.prediction.confidence_score),
);

const footerContextLabel = computed(() => {
    if (spreadEdge.value != null && Math.abs(spreadEdge.value) >= 2.5) {
        return `Spread edge ${formatSigned(spreadEdge.value, 1)} pts`;
    }

    if (totalEdge.value != null && Math.abs(totalEdge.value) >= 2) {
        return `Total edge ${formatSigned(totalEdge.value, 1)} pts`;
    }

    if (classificationLabel.value) return classificationLabel.value;

    return 'Projection only';
});

const actionTextClass = computed(() => {
    if (cardTone.value === 'positive')
        return 'text-emerald-600 dark:text-emerald-400';
    if (cardTone.value === 'negative') return 'text-red-600 dark:text-red-400';
    if (cardTone.value === 'candidate') return 'text-sky-600 dark:text-sky-400';

    return 'text-muted-foreground';
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

function teamScoreClass(team: 'away' | 'home'): string {
    if (
        !showGameScore.value ||
        awayScore.value == null ||
        homeScore.value == null
    ) {
        return 'text-foreground';
    }

    const teamScore = team === 'away' ? awayScore.value : homeScore.value;
    const opponentScore = team === 'away' ? homeScore.value : awayScore.value;

    if (teamScore > opponentScore) return 'text-foreground';
    if (teamScore < opponentScore) return 'text-muted-foreground';

    return 'text-foreground';
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

function formatSigned(value: number, digits = 1): string {
    return `${value > 0 ? '+' : ''}${value.toFixed(digits)}`;
}

function formatPercent(value?: number | null): string {
    if (value == null) return '-';

    return `${(value * 100).toFixed(1)}%`;
}

function labelize(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
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
        class="group relative flex min-h-[132px] w-full overflow-hidden rounded-2xl border p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md focus:ring-2 focus:ring-sky-500/35 focus:outline-none"
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
        @click="emit('select', prediction)"
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
                            <span
                                class="inline-flex items-center gap-1.5"
                                :class="teamScoreClass('away')"
                            >
                                <span>{{ teamAbbreviation(awayTeam) }}</span>
                                <span v-if="showGameScore" class="font-bold">
                                    {{ awayScore }}
                                </span>
                            </span>
                            <span class="text-muted-foreground">@</span>
                            <img
                                v-if="homeTeam?.logo_url"
                                :src="homeTeam.logo_url"
                                :alt="teamName(homeTeam)"
                                class="h-6 w-6 rounded-full object-contain"
                            />
                            <span
                                class="inline-flex items-center gap-1.5"
                                :class="teamScoreClass('home')"
                            >
                                <span>{{ teamAbbreviation(homeTeam) }}</span>
                                <span v-if="showGameScore" class="font-bold">
                                    {{ homeScore }}
                                </span>
                            </span>
                        </div>
                        <span
                            class="inline-flex items-center gap-1 rounded-full border bg-background/75 px-2.5 py-1 text-xs text-muted-foreground"
                        >
                            <Clock class="h-3.5 w-3.5" />
                            {{ formatTime(game?.game_date, game?.game_time) }}
                        </span>
                        <span
                            v-if="game?.week"
                            class="rounded-full border bg-background/75 px-2.5 py-1 text-xs font-semibold text-muted-foreground"
                        >
                            Week {{ game.week }}
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
                            Pick: {{ projectedPickLabel }}
                            {{
                                formatPercent(
                                    prediction.win_probability as number | null,
                                )
                            }}
                        </span>
                        <span
                            v-if="classificationLabel"
                            class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                            :class="
                                cardTone === 'candidate'
                                    ? 'border-sky-500/25 bg-sky-500/10 text-sky-700 dark:text-sky-300'
                                    : 'border-muted bg-muted/40 text-muted-foreground'
                            "
                        >
                            {{ classificationLabel }}
                        </span>
                        <span
                            v-if="modelResultLabel"
                            class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                            :class="resultBadgeClass(modelResult)"
                        >
                            {{ modelResultLabel }}
                        </span>
                        <span
                            v-if="trustScore != null"
                            class="rounded-full border bg-background/75 px-2.5 py-1 text-xs font-semibold text-muted-foreground"
                        >
                            Trust {{ trustScore.toFixed(1) }}
                        </span>
                    </div>
                </div>

                <span
                    class="hidden shrink-0 rounded-full border bg-background/75 px-3 py-1 text-xs font-semibold text-muted-foreground sm:inline-flex"
                >
                    {{ winnerTier ? labelize(winnerTier) : 'No signal' }}
                </span>
            </div>

            <div
                class="flex flex-wrap items-center justify-between gap-3 border-t pt-3"
            >
                <div class="min-w-0 text-xs text-muted-foreground">
                    {{ footerContextLabel }}
                    <span v-if="marketSpread != null">
                        · Spread {{ formatSigned(marketSpread, 1) }}
                    </span>
                    <span v-if="marketTotal != null">
                        · Total {{ marketTotal.toFixed(1) }}
                    </span>
                    <span v-if="spreadTier">
                        · Spread {{ labelize(spreadTier) }}</span
                    >
                    <span v-if="totalTier">
                        · Total {{ labelize(totalTier) }}</span
                    >
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
