<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Activity,
    Ban,
    CheckCircle2,
    CircleDollarSign,
    Clock3,
    Database,
    GitCompare,
    ListChecks,
    LockKeyhole,
    RefreshCw,
    ShieldCheck,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { BreadcrumbItem } from '@/types';

type ArtifactOption = {
    id: string;
    model_version: string;
    model_type: string;
    market_type: string;
    status: string;
    created_at: string | null;
    promoted_at: string | null;
};

type Artifact = ArtifactOption & {
    sport: string;
    feature_version: string;
    artifact_hash: string;
    dataset_hash: string;
    evaluation_report_hash: string | null;
    training_run_id: string;
    config_hash: string | null;
    code_version: string | null;
    run_type: string | null;
    metrics: Record<string, unknown> | null;
    evaluation_summary: Record<string, number>;
    promotion_checks: Record<string, boolean>;
    promotion_markets: Record<string, PromotionMarketDecision>;
    promoted_markets: string[];
    delta_convention: {
        normalized?: string;
        positive_means?: string;
    };
    public_output_changed: boolean;
};

type PromotionMarketDecision = {
    available: boolean;
    eligible: boolean;
    promotion_ready?: boolean;
    promoted?: boolean;
    window_count: number;
    challenger_better_window_count: number;
    challenger_better_window_rate: number;
    average_primary_improvement: number | null;
    average_secondary_improvement: number | null;
    worst_primary_window_regression: number | null;
    worst_secondary_window_regression: number | null;
    checks: Record<string, boolean>;
};

type MonitoringSummary = {
    shadow_observations: number;
    decisions: number;
    tracking_bets: number;
    no_bets: number;
    settled_decisions: number;
    pending_decisions: number;
    actual_profit_units: number;
    actual_roi: number | null;
    counterfactual_profit_units: number;
    counterfactual_roi: number | null;
    average_clv: number | null;
    calibration_games: number;
    baseline_brier: number | null;
    challenger_brier: number | null;
    brier_delta: number | null;
};

type Observation = {
    id: number;
    game_id: number;
    matchup: string;
    game_date: string | null;
    game_time: string | null;
    game_status: string | null;
    market_type: string;
    baseline_output: number;
    challenger_output: number;
    output_delta: number;
    baseline_outputs: {
        win_probability: number | null;
        predicted_spread: number | null;
        predicted_total: number | null;
    };
    challenger_outputs: {
        win_probability: number | null;
        predicted_spread: number | null;
        predicted_total: number | null;
        uncertainty: number | null;
    };
    active_source: string;
    public_output_changed: boolean;
    generated_at: string | null;
    snapshot: {
        id: number | null;
        snapshot_run_id: string | null;
        model_run_id: string | null;
        feature_hash: string | null;
        pregame_safe: boolean;
        availability_status: string | null;
        features_available_at: string | null;
        game_start_at: string | null;
    };
    inference_run_id: string;
    decision: {
        id: number;
        status: string;
        recommendation_label: string | null;
        side: string;
        price: number | null;
        bookmaker: string | null;
        market_probability: number | null;
        model_probability: number | null;
        edge: number | null;
        is_bet: boolean;
        is_public: boolean;
        is_tracking_only: boolean;
        pregame_safe: boolean;
        eligibility_reasons: string[];
        model_uncertainty: number | null;
        maximum_model_uncertainty: number | null;
        uncertainty_gate_enabled: boolean;
        decided_at: string | null;
    } | null;
    settlement: {
        status: string;
        result_value: number | null;
        profit_units: number | null;
        counterfactual_profit_units: number | null;
        clv: number | null;
        settled_at: string | null;
    } | null;
};

type EvaluationWindow = {
    evaluation_season?: number;
    test_season?: number;
    games?: number;
    baseline_brier?: number;
    challenger_brier?: number;
    brier_delta?: number;
    baseline_log_loss?: number;
    challenger_log_loss?: number;
    log_loss_delta?: number;
    baseline_spread_mae?: number;
    challenger_spread_mae?: number;
    spread_mae_delta?: number;
    baseline_total_mae?: number;
    challenger_total_mae?: number;
    total_mae_delta?: number;
};

type SignalGradeRow = {
    signal_type: string;
    signal_key: string;
    observation_count: number;
    winner_sample: number;
    winner_accuracy: number | null;
    ats_sample: number;
    ats_hit_rate: number | null;
    total_sample: number;
    total_hit_rate: number | null;
    settlement_sample: number;
    roi: number | null;
    shadow_settlement_sample: number;
    shadow_roi: number | null;
    avg_clv: number | null;
    avg_brier_score: number | null;
    avg_calibration_lift: number | null;
    avg_spread_error: number | null;
    avg_spread_error_lift: number | null;
    avg_total_error: number | null;
    avg_total_error_lift: number | null;
    window_count?: number;
    winner_accuracy_range?: number | null;
    positive_roi_windows?: number;
    roi_window_count?: number;
    season?: number;
    window?: string;
};

type SignalGradeCategory = {
    signal_type: string;
    label: string;
    signals: SignalGradeRow[];
    windows: SignalGradeRow[];
};

const props = defineProps<{
    artifacts: ArtifactOption[];
    artifact: Artifact | null;
    summary: MonitoringSummary;
    observations: Observation[];
    no_bet_reasons: Array<{ reason: string; count: number }>;
    evaluation_windows: EvaluationWindow[];
    signal_grades: SignalGradeCategory[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/settings/admin' },
    { title: 'NFL Model Monitor', href: '/admin/nfl-model-monitoring' },
];
const selectedArtifact = ref(props.artifact?.id ?? '');
const refreshing = ref(false);
const maximumReasonCount = computed(() =>
    Math.max(1, ...props.no_bet_reasons.map((row) => row.count)),
);
const evaluationSummary = computed(
    () => props.artifact?.evaluation_summary ?? {},
);
const promotionMarkets = computed(() =>
    Object.entries(props.artifact?.promotion_markets ?? {}),
);
const worstEvaluationWindow = computed(() => {
    const measured = props.evaluation_windows.filter(
        (window) =>
            window.brier_delta !== null && window.brier_delta !== undefined,
    );

    return measured.length === 0
        ? null
        : measured.reduce((worst, window) =>
              (window.brier_delta ?? 0) < (worst.brier_delta ?? 0)
                  ? window
                  : worst,
          );
});
const activeSignalType = ref(
    props.signal_grades[0]?.signal_type ?? 'reason_code',
);
const selectedSignalKeys = ref<Record<string, string>>(
    Object.fromEntries(
        props.signal_grades.map((category) => [
            category.signal_type,
            category.signals[0]?.signal_key ?? '',
        ]),
    ),
);
const activeSignalCategory = computed(
    () =>
        props.signal_grades.find(
            (category) => category.signal_type === activeSignalType.value,
        ) ?? props.signal_grades[0],
);
const activeSignalWindows = computed(() => {
    const category = activeSignalCategory.value;
    if (!category) return [];

    const signalKey = selectedSignalKeys.value[category.signal_type];

    return category.windows.filter((row) => row.signal_key === signalKey);
});

function changeArtifact(): void {
    router.get(
        '/admin/nfl-model-monitoring',
        { artifact: selectedArtifact.value || undefined },
        { preserveState: true, replace: true },
    );
}

function refresh(): void {
    refreshing.value = true;
    router.reload({
        only: [
            'artifacts',
            'artifact',
            'summary',
            'observations',
            'no_bet_reasons',
            'evaluation_windows',
            'signal_grades',
        ],
        onFinish: () => {
            refreshing.value = false;
        },
    });
}

function formatPercent(value: number | null, digits = 1): string {
    return value === null ? 'N/A' : `${(value * 100).toFixed(digits)}%`;
}

function formatNumber(value: number | null | undefined, digits = 3): string {
    return value === null || value === undefined
        ? 'N/A'
        : value.toFixed(digits);
}

function formatSigned(value: number | null | undefined, digits = 3): string {
    if (value === null || value === undefined) return 'N/A';

    return `${value > 0 ? '+' : ''}${value.toFixed(digits)}`;
}

function formatDate(value: string | null): string {
    if (!value) return 'N/A';

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

function formatGameDate(value: string | null): string {
    if (!value) return 'Date pending';

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${value}T12:00:00Z`));
}

function labelize(value: string | null | undefined): string {
    if (!value) return 'N/A';

    return value
        .replaceAll('_', ' ')
        .replaceAll('-', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function shortHash(value: string | null | undefined): string {
    if (!value) return 'N/A';

    return value.length > 18
        ? `${value.slice(0, 9)}...${value.slice(-7)}`
        : value;
}

function artifactStatusClass(status: string): string {
    if (status === 'promoted') {
        return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    }
    if (status === 'promotion_eligible') {
        return 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300';
    }

    return 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300';
}

function decisionStatusClass(observation: Observation): string {
    if (observation.settlement?.status === 'win') {
        return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    }
    if (observation.settlement?.status === 'loss') {
        return 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300';
    }
    if (observation.decision?.is_bet) {
        return 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300';
    }

    return 'border-border bg-muted text-muted-foreground';
}

function decisionLabel(observation: Observation): string {
    if (observation.settlement) {
        return labelize(observation.settlement.status);
    }
    if (!observation.decision) return 'Awaiting Decision';

    return observation.decision.is_bet ? 'Tracking Bet' : 'No Bet';
}

function decisionReason(observation: Observation): string {
    const reasons = observation.decision?.eligibility_reasons ?? [];
    if (reasons.length > 0) return reasons.map(labelize).join(', ');
    if (observation.decision?.is_bet) return 'Promotion and edge gates passed';

    return 'Decision pending';
}

function outcomeTone(delta: number | null | undefined): string {
    if (delta === null || delta === undefined || delta === 0) {
        return 'text-muted-foreground';
    }

    return delta > 0
        ? 'text-emerald-700 dark:text-emerald-300'
        : 'text-rose-700 dark:text-rose-300';
}

function evaluationSeason(window: EvaluationWindow): number | undefined {
    return window.evaluation_season ?? window.test_season;
}

function improvementTone(value: number | null | undefined): string {
    if (value === null || value === undefined || value === 0) {
        return 'text-muted-foreground';
    }

    return value > 0
        ? 'text-emerald-700 dark:text-emerald-300'
        : 'text-rose-700 dark:text-rose-300';
}

function selectSignal(row: SignalGradeRow): void {
    selectedSignalKeys.value[row.signal_type] = row.signal_key;
}

function signalIsSelected(row: SignalGradeRow): boolean {
    return selectedSignalKeys.value[row.signal_type] === row.signal_key;
}
</script>

<template>
    <Head title="NFL Model Monitor" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsLayout full-width>
            <div class="space-y-6">
                <header
                    class="flex flex-col gap-4 border-b pb-5 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <Activity class="h-5 w-5 text-sky-600" />
                            <h1 class="text-2xl font-bold">
                                NFL Model Monitor
                            </h1>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Shadow inference, decision, and settlement ledger
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <label class="sr-only" for="artifact-selector">
                            Model artifact
                        </label>
                        <select
                            id="artifact-selector"
                            v-model="selectedArtifact"
                            class="h-9 min-w-0 rounded-md border border-input bg-background px-3 text-sm sm:min-w-80"
                            @change="changeArtifact"
                        >
                            <option
                                v-for="option in artifacts"
                                :key="option.id"
                                :value="option.id"
                            >
                                {{ option.model_version }} ·
                                {{ labelize(option.status) }}
                            </option>
                        </select>

                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <button
                                        type="button"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-md border bg-background transition-colors hover:bg-muted disabled:opacity-50"
                                        aria-label="Refresh model monitor"
                                        :disabled="refreshing"
                                        @click="refresh"
                                    >
                                        <RefreshCw
                                            class="h-4 w-4"
                                            :class="{
                                                'animate-spin': refreshing,
                                            }"
                                        />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent>Refresh</TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </div>
                </header>

                <div
                    v-if="!artifact"
                    class="rounded-md border border-dashed px-6 py-14 text-center"
                >
                    <Database class="mx-auto h-8 w-8 text-muted-foreground" />
                    <p class="mt-3 font-semibold">
                        No NFL model artifacts registered
                    </p>
                </div>

                <template v-else>
                    <section
                        class="flex flex-col gap-4 rounded-md border bg-card p-4 md:flex-row md:items-center md:justify-between"
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="rounded-md border px-2 py-1 text-xs font-semibold"
                                    :class="
                                        artifactStatusClass(artifact.status)
                                    "
                                >
                                    {{ labelize(artifact.status) }}
                                </span>
                                <span
                                    class="rounded-md border border-violet-500/30 bg-violet-500/10 px-2 py-1 text-xs font-semibold text-violet-700 dark:text-violet-300"
                                >
                                    Tracking only
                                </span>
                            </div>
                            <p class="mt-2 truncate text-base font-semibold">
                                {{ artifact.model_version }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{ labelize(artifact.model_type) }} ·
                                {{ labelize(artifact.market_type) }}
                            </p>
                        </div>

                        <div
                            class="flex items-center gap-3 rounded-md border px-3 py-2"
                            :class="
                                artifact.public_output_changed
                                    ? 'border-amber-500/30 bg-amber-500/10'
                                    : 'border-emerald-500/30 bg-emerald-500/10'
                            "
                        >
                            <ShieldCheck
                                class="h-5 w-5 shrink-0"
                                :class="
                                    artifact.public_output_changed
                                        ? 'text-amber-700 dark:text-amber-300'
                                        : 'text-emerald-700 dark:text-emerald-300'
                                "
                            />
                            <div>
                                <div
                                    class="text-xs font-semibold"
                                    :class="
                                        artifact.public_output_changed
                                            ? 'text-amber-800 dark:text-amber-200'
                                            : 'text-emerald-800 dark:text-emerald-200'
                                    "
                                >
                                    Public Output
                                </div>
                                <div
                                    class="text-sm"
                                    :class="
                                        artifact.public_output_changed
                                            ? 'text-amber-700 dark:text-amber-300'
                                            : 'text-emerald-700 dark:text-emerald-300'
                                    "
                                >
                                    {{
                                        artifact.public_output_changed
                                            ? 'Model output active'
                                            : 'Baseline unchanged'
                                    }}
                                </div>
                            </div>
                        </div>
                    </section>

                    <section
                        class="grid grid-cols-2 gap-3 lg:grid-cols-4 xl:grid-cols-6"
                    >
                        <div class="rounded-md border bg-card p-3">
                            <Activity class="h-4 w-4 text-sky-600" />
                            <div class="mt-3 text-2xl font-bold">
                                {{ summary.shadow_observations }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                Shadow observations
                            </div>
                        </div>
                        <div class="rounded-md border bg-card p-3">
                            <GitCompare class="h-4 w-4 text-violet-600" />
                            <div class="mt-3 text-2xl font-bold">
                                {{ summary.decisions }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                Decisions
                            </div>
                        </div>
                        <div class="rounded-md border bg-card p-3">
                            <CircleDollarSign
                                class="h-4 w-4 text-emerald-600"
                            />
                            <div class="mt-3 text-2xl font-bold">
                                {{ summary.tracking_bets }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                Tracking bets
                            </div>
                        </div>
                        <div class="rounded-md border bg-card p-3">
                            <Ban class="h-4 w-4 text-amber-600" />
                            <div class="mt-3 text-2xl font-bold">
                                {{ summary.no_bets }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                No-bet decisions
                            </div>
                        </div>
                        <div class="rounded-md border bg-card p-3">
                            <Clock3 class="h-4 w-4 text-slate-600" />
                            <div class="mt-3 text-2xl font-bold">
                                {{ summary.pending_decisions }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                Pending settlement
                            </div>
                        </div>
                        <div class="rounded-md border bg-card p-3">
                            <CheckCircle2 class="h-4 w-4 text-teal-600" />
                            <div class="mt-3 text-2xl font-bold">
                                {{ formatPercent(summary.actual_roi) }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                Settled ROI
                            </div>
                        </div>
                    </section>

                    <Tabs default-value="observations" class="space-y-4">
                        <TabsList
                            class="grid h-auto w-full grid-cols-4 sm:w-auto"
                        >
                            <TabsTrigger value="observations">
                                Observations
                            </TabsTrigger>
                            <TabsTrigger value="evaluation">
                                Evaluation
                            </TabsTrigger>
                            <TabsTrigger value="signals">
                                Signal Grades
                            </TabsTrigger>
                            <TabsTrigger value="lineage">Lineage</TabsTrigger>
                        </TabsList>

                        <TabsContent value="observations" class="space-y-5">
                            <section
                                v-if="no_bet_reasons.length > 0"
                                class="rounded-md border bg-card p-4"
                            >
                                <div
                                    class="flex items-center justify-between gap-3"
                                >
                                    <h2 class="text-sm font-semibold">
                                        No-Bet Reasons
                                    </h2>
                                    <span class="text-xs text-muted-foreground">
                                        {{ summary.no_bets }} decisions
                                    </span>
                                </div>
                                <div class="mt-4 space-y-3">
                                    <div
                                        v-for="row in no_bet_reasons"
                                        :key="row.reason"
                                        class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3"
                                    >
                                        <div class="min-w-0">
                                            <div
                                                class="mb-1 flex justify-between gap-3 text-xs"
                                            >
                                                <span class="truncate">
                                                    {{ labelize(row.reason) }}
                                                </span>
                                                <span
                                                    class="font-semibold tabular-nums"
                                                >
                                                    {{ row.count }}
                                                </span>
                                            </div>
                                            <div
                                                class="h-1.5 overflow-hidden rounded-sm bg-muted"
                                            >
                                                <div
                                                    class="h-full bg-amber-500"
                                                    :style="{
                                                        width: `${(row.count / maximumReasonCount) * 100}%`,
                                                    }"
                                                />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="overflow-hidden rounded-md border">
                                <div
                                    class="flex items-center justify-between border-b bg-muted/40 px-4 py-3"
                                >
                                    <h2 class="text-sm font-semibold">
                                        Recent Shadow Ledger
                                    </h2>
                                    <span class="text-xs text-muted-foreground">
                                        Latest {{ observations.length }}
                                    </span>
                                </div>

                                <div
                                    v-if="observations.length === 0"
                                    class="px-6 py-12 text-center text-sm text-muted-foreground"
                                >
                                    No shadow observations yet.
                                </div>

                                <div v-else class="overflow-x-auto">
                                    <table
                                        class="w-full min-w-[1040px] text-left text-sm"
                                    >
                                        <thead
                                            class="border-b bg-muted/20 text-xs text-muted-foreground"
                                        >
                                            <tr>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Matchup
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Win Probability
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Projection
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Decision
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Market
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Provenance
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y">
                                            <tr
                                                v-for="row in observations"
                                                :key="row.id"
                                                class="align-top hover:bg-muted/20"
                                            >
                                                <td class="px-4 py-4">
                                                    <div class="font-semibold">
                                                        {{ row.matchup }}
                                                    </div>
                                                    <div
                                                        class="mt-1 text-xs text-muted-foreground"
                                                    >
                                                        {{
                                                            formatGameDate(
                                                                row.game_date,
                                                            )
                                                        }}
                                                        ·
                                                        {{
                                                            labelize(
                                                                row.game_status,
                                                            )
                                                        }}
                                                    </div>
                                                </td>
                                                <td class="px-4 py-4">
                                                    <div
                                                        class="flex items-baseline gap-2 tabular-nums"
                                                    >
                                                        <span>
                                                            {{
                                                                formatPercent(
                                                                    row.baseline_output,
                                                                )
                                                            }}
                                                        </span>
                                                        <span
                                                            class="text-muted-foreground"
                                                        >
                                                            →
                                                        </span>
                                                        <span
                                                            class="font-semibold"
                                                        >
                                                            {{
                                                                formatPercent(
                                                                    row.challenger_output,
                                                                )
                                                            }}
                                                        </span>
                                                    </div>
                                                    <div
                                                        class="mt-1 text-xs tabular-nums"
                                                        :class="
                                                            row.output_delta ===
                                                            0
                                                                ? 'text-muted-foreground'
                                                                : 'text-sky-700 dark:text-sky-300'
                                                        "
                                                    >
                                                        {{
                                                            formatSigned(
                                                                row.output_delta,
                                                                3,
                                                            )
                                                        }}
                                                        delta
                                                    </div>
                                                </td>
                                                <td class="px-4 py-4">
                                                    <div
                                                        class="grid grid-cols-[auto_auto_auto] gap-x-3 gap-y-1 text-xs tabular-nums"
                                                    >
                                                        <span
                                                            class="text-muted-foreground"
                                                        />
                                                        <span
                                                            class="text-muted-foreground"
                                                        >
                                                            Base
                                                        </span>
                                                        <span
                                                            class="text-muted-foreground"
                                                        >
                                                            Shadow
                                                        </span>
                                                        <span>Spread</span>
                                                        <span>
                                                            {{
                                                                formatSigned(
                                                                    row
                                                                        .baseline_outputs
                                                                        .predicted_spread,
                                                                    1,
                                                                )
                                                            }}
                                                        </span>
                                                        <span>
                                                            {{
                                                                formatSigned(
                                                                    row
                                                                        .challenger_outputs
                                                                        .predicted_spread,
                                                                    1,
                                                                )
                                                            }}
                                                        </span>
                                                        <span>Total</span>
                                                        <span>
                                                            {{
                                                                formatNumber(
                                                                    row
                                                                        .baseline_outputs
                                                                        .predicted_total,
                                                                    1,
                                                                )
                                                            }}
                                                        </span>
                                                        <span>
                                                            {{
                                                                formatNumber(
                                                                    row
                                                                        .challenger_outputs
                                                                        .predicted_total,
                                                                    1,
                                                                )
                                                            }}
                                                        </span>
                                                    </div>
                                                    <div
                                                        class="mt-2 text-xs text-muted-foreground tabular-nums"
                                                    >
                                                        Uncertainty
                                                        <span
                                                            class="font-semibold text-foreground"
                                                        >
                                                            {{
                                                                formatPercent(
                                                                    row
                                                                        .challenger_outputs
                                                                        .uncertainty,
                                                                )
                                                            }}
                                                        </span>
                                                        <template
                                                            v-if="
                                                                row.decision
                                                                    ?.uncertainty_gate_enabled
                                                            "
                                                        >
                                                            · max
                                                            {{
                                                                formatPercent(
                                                                    row.decision
                                                                        .maximum_model_uncertainty,
                                                                )
                                                            }}
                                                        </template>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-4">
                                                    <span
                                                        class="inline-flex rounded-md border px-2 py-1 text-xs font-semibold"
                                                        :class="
                                                            decisionStatusClass(
                                                                row,
                                                            )
                                                        "
                                                    >
                                                        {{ decisionLabel(row) }}
                                                    </span>
                                                    <div
                                                        class="mt-2 max-w-56 text-xs text-muted-foreground"
                                                    >
                                                        {{
                                                            decisionReason(row)
                                                        }}
                                                    </div>
                                                </td>
                                                <td class="px-4 py-4">
                                                    <template
                                                        v-if="row.decision"
                                                    >
                                                        <div
                                                            class="font-medium"
                                                        >
                                                            {{
                                                                labelize(
                                                                    row.decision
                                                                        .side,
                                                                )
                                                            }}
                                                            <span
                                                                v-if="
                                                                    row.decision
                                                                        .price
                                                                "
                                                                class="tabular-nums"
                                                            >
                                                                {{
                                                                    row.decision
                                                                        .price >
                                                                    0
                                                                        ? '+'
                                                                        : ''
                                                                }}{{
                                                                    row.decision
                                                                        .price
                                                                }}
                                                            </span>
                                                        </div>
                                                        <div
                                                            class="mt-1 text-xs text-muted-foreground"
                                                        >
                                                            Edge
                                                            {{
                                                                formatPercent(
                                                                    row.decision
                                                                        .edge,
                                                                )
                                                            }}
                                                        </div>
                                                    </template>
                                                    <span
                                                        v-else
                                                        class="text-xs text-muted-foreground"
                                                    >
                                                        Awaiting quote
                                                    </span>
                                                </td>
                                                <td class="px-4 py-4">
                                                    <div
                                                        class="flex items-center gap-1.5 text-xs"
                                                        :class="
                                                            row.snapshot
                                                                .pregame_safe
                                                                ? 'text-emerald-700 dark:text-emerald-300'
                                                                : 'text-rose-700 dark:text-rose-300'
                                                        "
                                                    >
                                                        <LockKeyhole
                                                            class="h-3.5 w-3.5"
                                                        />
                                                        {{
                                                            row.snapshot
                                                                .pregame_safe
                                                                ? 'Pregame safe'
                                                                : 'Unsafe'
                                                        }}
                                                    </div>
                                                    <div
                                                        class="mt-2 font-mono text-[11px] text-muted-foreground"
                                                        :title="
                                                            row.snapshot
                                                                .feature_hash ??
                                                            ''
                                                        "
                                                    >
                                                        {{
                                                            shortHash(
                                                                row.snapshot
                                                                    .feature_hash,
                                                            )
                                                        }}
                                                    </div>
                                                    <div
                                                        class="mt-1 text-[11px] text-muted-foreground"
                                                    >
                                                        {{
                                                            formatDate(
                                                                row.generated_at,
                                                            )
                                                        }}
                                                    </div>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        </TabsContent>

                        <TabsContent value="evaluation" class="space-y-5">
                            <section
                                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5"
                            >
                                <div class="rounded-md border bg-card p-4">
                                    <div class="text-xs text-muted-foreground">
                                        Held-Out Seasons
                                    </div>
                                    <div class="mt-2 text-2xl font-bold">
                                        {{
                                            evaluationSummary.window_count ??
                                            evaluation_windows.length
                                        }}
                                    </div>
                                </div>
                                <div class="rounded-md border bg-card p-4">
                                    <div class="text-xs text-muted-foreground">
                                        Better Seasons
                                    </div>
                                    <div class="mt-2 text-2xl font-bold">
                                        {{
                                            evaluationSummary.challenger_better_window_count ??
                                            0
                                        }}
                                    </div>
                                </div>
                                <div class="rounded-md border bg-card p-4">
                                    <div class="text-xs text-muted-foreground">
                                        Average Brier Delta
                                    </div>
                                    <div
                                        class="mt-2 text-2xl font-bold"
                                        :class="
                                            outcomeTone(
                                                evaluationSummary.avg_brier_delta,
                                            )
                                        "
                                    >
                                        {{
                                            formatSigned(
                                                evaluationSummary.avg_brier_delta,
                                                4,
                                            )
                                        }}
                                    </div>
                                    <div
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        Positive is better
                                    </div>
                                </div>
                                <div class="rounded-md border bg-card p-4">
                                    <div class="text-xs text-muted-foreground">
                                        Worst Season
                                    </div>
                                    <div class="mt-2 text-2xl font-bold">
                                        {{
                                            worstEvaluationWindow
                                                ? evaluationSeason(
                                                      worstEvaluationWindow,
                                                  )
                                                : 'N/A'
                                        }}
                                    </div>
                                    <div
                                        class="mt-1 text-xs tabular-nums"
                                        :class="
                                            outcomeTone(
                                                worstEvaluationWindow?.brier_delta,
                                            )
                                        "
                                    >
                                        {{
                                            formatSigned(
                                                worstEvaluationWindow?.brier_delta,
                                                4,
                                            )
                                        }}
                                        Brier
                                    </div>
                                </div>
                                <div class="rounded-md border bg-card p-4">
                                    <div class="text-xs text-muted-foreground">
                                        Live Brier Delta
                                    </div>
                                    <div
                                        class="mt-2 text-2xl font-bold"
                                        :class="
                                            outcomeTone(summary.brier_delta)
                                        "
                                    >
                                        {{
                                            formatSigned(summary.brier_delta, 4)
                                        }}
                                    </div>
                                    <div
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{ summary.calibration_games }} games
                                    </div>
                                </div>
                            </section>

                            <section
                                v-if="promotionMarkets.length > 0"
                                class="rounded-md border bg-card"
                            >
                                <div
                                    class="flex items-center justify-between gap-3 border-b px-4 py-3"
                                >
                                    <h2 class="text-sm font-semibold">
                                        Market Promotion Decisions
                                    </h2>
                                    <span class="text-xs text-muted-foreground">
                                        Evaluated independently
                                    </span>
                                </div>
                                <div class="grid gap-3 p-4 md:grid-cols-3">
                                    <div
                                        v-for="[
                                            market,
                                            decision,
                                        ] in promotionMarkets"
                                        :key="market"
                                        class="rounded-md border p-3"
                                    >
                                        <div
                                            class="flex items-center justify-between gap-2"
                                        >
                                            <span class="text-sm font-semibold">
                                                {{ labelize(market) }}
                                            </span>
                                            <span
                                                class="rounded-md border px-2 py-1 text-xs font-semibold"
                                                :class="
                                                    decision.promoted ||
                                                    decision.promotion_ready
                                                        ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                                        : decision.eligible
                                                          ? 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300'
                                                          : 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300'
                                                "
                                            >
                                                {{
                                                    decision.promoted
                                                        ? 'Promoted'
                                                        : decision.promotion_ready
                                                          ? 'Ready'
                                                          : decision.eligible
                                                            ? 'Offline Eligible'
                                                            : decision.available
                                                              ? 'Blocked'
                                                              : 'No Data'
                                                }}
                                            </span>
                                        </div>
                                        <dl
                                            class="mt-3 grid grid-cols-2 gap-3 text-xs"
                                        >
                                            <div>
                                                <dt
                                                    class="text-muted-foreground"
                                                >
                                                    Better Seasons
                                                </dt>
                                                <dd
                                                    class="mt-1 font-semibold tabular-nums"
                                                >
                                                    {{
                                                        decision.challenger_better_window_count
                                                    }}
                                                    /
                                                    {{ decision.window_count }}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt
                                                    class="text-muted-foreground"
                                                >
                                                    Avg Improvement
                                                </dt>
                                                <dd
                                                    class="mt-1 font-semibold tabular-nums"
                                                    :class="
                                                        outcomeTone(
                                                            decision.average_primary_improvement,
                                                        )
                                                    "
                                                >
                                                    {{
                                                        formatSigned(
                                                            decision.average_primary_improvement,
                                                            4,
                                                        )
                                                    }}
                                                </dd>
                                            </div>
                                            <div class="col-span-2">
                                                <dt
                                                    class="text-muted-foreground"
                                                >
                                                    Worst Regression
                                                </dt>
                                                <dd
                                                    class="mt-1 font-semibold tabular-nums"
                                                    :class="
                                                        (decision.worst_primary_window_regression ??
                                                            0) > 0
                                                            ? 'text-rose-700 dark:text-rose-300'
                                                            : 'text-muted-foreground'
                                                    "
                                                >
                                                    {{
                                                        formatNumber(
                                                            decision.worst_primary_window_regression,
                                                            4,
                                                        )
                                                    }}
                                                </dd>
                                            </div>
                                        </dl>
                                    </div>
                                </div>
                            </section>

                            <section class="overflow-hidden rounded-md border">
                                <div class="border-b bg-muted/40 px-4 py-3">
                                    <h2 class="text-sm font-semibold">
                                        Chronological Evaluation
                                    </h2>
                                </div>
                                <div class="overflow-x-auto">
                                    <table
                                        class="w-full min-w-[900px] text-left text-sm"
                                    >
                                        <thead
                                            class="border-b bg-muted/20 text-xs text-muted-foreground"
                                        >
                                            <tr>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Season
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Games
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Baseline Brier
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Challenger Brier
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Brier Delta
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Log Loss Delta
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Spread MAE Delta
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Total MAE Delta
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y">
                                            <tr
                                                v-for="window in evaluation_windows"
                                                :key="evaluationSeason(window)"
                                            >
                                                <td
                                                    class="px-4 py-3 font-semibold"
                                                >
                                                    {{
                                                        evaluationSeason(window)
                                                    }}
                                                </td>
                                                <td
                                                    class="px-4 py-3 tabular-nums"
                                                >
                                                    {{ window.games }}
                                                </td>
                                                <td
                                                    class="px-4 py-3 tabular-nums"
                                                >
                                                    {{
                                                        formatNumber(
                                                            window.baseline_brier,
                                                            4,
                                                        )
                                                    }}
                                                </td>
                                                <td
                                                    class="px-4 py-3 tabular-nums"
                                                >
                                                    {{
                                                        formatNumber(
                                                            window.challenger_brier,
                                                            4,
                                                        )
                                                    }}
                                                </td>
                                                <td
                                                    class="px-4 py-3 font-semibold tabular-nums"
                                                    :class="
                                                        outcomeTone(
                                                            window.brier_delta,
                                                        )
                                                    "
                                                >
                                                    {{
                                                        formatSigned(
                                                            window.brier_delta,
                                                            4,
                                                        )
                                                    }}
                                                </td>
                                                <td
                                                    class="px-4 py-3 tabular-nums"
                                                    :class="
                                                        outcomeTone(
                                                            window.log_loss_delta,
                                                        )
                                                    "
                                                >
                                                    {{
                                                        formatSigned(
                                                            window.log_loss_delta,
                                                            4,
                                                        )
                                                    }}
                                                </td>
                                                <td
                                                    class="px-4 py-3 tabular-nums"
                                                    :class="
                                                        outcomeTone(
                                                            window.spread_mae_delta,
                                                        )
                                                    "
                                                >
                                                    {{
                                                        formatSigned(
                                                            window.spread_mae_delta,
                                                            3,
                                                        )
                                                    }}
                                                </td>
                                                <td
                                                    class="px-4 py-3 tabular-nums"
                                                    :class="
                                                        outcomeTone(
                                                            window.total_mae_delta,
                                                        )
                                                    "
                                                >
                                                    {{
                                                        formatSigned(
                                                            window.total_mae_delta,
                                                            3,
                                                        )
                                                    }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        </TabsContent>

                        <TabsContent value="signals" class="space-y-5">
                            <section class="overflow-hidden rounded-md border">
                                <div
                                    class="flex flex-col gap-3 border-b bg-muted/40 px-4 py-3 lg:flex-row lg:items-center lg:justify-between"
                                >
                                    <div class="flex items-center gap-2">
                                        <ListChecks
                                            class="h-4 w-4 text-teal-600"
                                        />
                                        <h2 class="text-sm font-semibold">
                                            Settlement-Backed Signal Grades
                                        </h2>
                                    </div>
                                    <Tabs
                                        v-model="activeSignalType"
                                        class="w-full lg:w-auto"
                                    >
                                        <TabsList
                                            class="grid h-auto w-full grid-cols-2 lg:grid-cols-5"
                                        >
                                            <TabsTrigger
                                                v-for="category in signal_grades"
                                                :key="category.signal_type"
                                                :value="category.signal_type"
                                                class="text-xs"
                                            >
                                                {{ category.label }}
                                            </TabsTrigger>
                                        </TabsList>
                                    </Tabs>
                                </div>

                                <div
                                    v-if="
                                        !activeSignalCategory ||
                                        activeSignalCategory.signals.length ===
                                            0
                                    "
                                    class="px-6 py-12 text-center text-sm text-muted-foreground"
                                >
                                    No settled signal grades yet.
                                </div>

                                <div v-else class="overflow-x-auto">
                                    <table
                                        class="w-full min-w-[1260px] text-left text-sm"
                                    >
                                        <thead
                                            class="border-b bg-muted/20 text-xs text-muted-foreground"
                                        >
                                            <tr>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Signal
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Sample
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Winner
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    ATS
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Total
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    ROI
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    CLV
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Calibration Lift
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Error Lift
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Window Stability
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y">
                                            <tr
                                                v-for="row in activeSignalCategory.signals"
                                                :key="`${row.signal_type}:${row.signal_key}`"
                                                class="cursor-pointer align-top transition-colors hover:bg-muted/20"
                                                :class="{
                                                    'bg-muted/40':
                                                        signalIsSelected(row),
                                                }"
                                                @click="selectSignal(row)"
                                            >
                                                <td class="px-4 py-3">
                                                    <button
                                                        type="button"
                                                        class="max-w-64 text-left font-semibold"
                                                        @click.stop="
                                                            selectSignal(row)
                                                        "
                                                    >
                                                        {{
                                                            labelize(
                                                                row.signal_key,
                                                            )
                                                        }}
                                                    </button>
                                                </td>
                                                <td
                                                    class="px-4 py-3 tabular-nums"
                                                >
                                                    {{ row.observation_count }}
                                                </td>
                                                <td class="px-4 py-3">
                                                    <div
                                                        class="font-medium tabular-nums"
                                                    >
                                                        {{
                                                            formatPercent(
                                                                row.winner_accuracy,
                                                            )
                                                        }}
                                                    </div>
                                                    <div
                                                        class="text-xs text-muted-foreground"
                                                    >
                                                        n={{
                                                            row.winner_sample
                                                        }}
                                                    </div>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <div
                                                        class="font-medium tabular-nums"
                                                    >
                                                        {{
                                                            formatPercent(
                                                                row.ats_hit_rate,
                                                            )
                                                        }}
                                                    </div>
                                                    <div
                                                        class="text-xs text-muted-foreground"
                                                    >
                                                        n={{ row.ats_sample }}
                                                    </div>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <div
                                                        class="font-medium tabular-nums"
                                                    >
                                                        {{
                                                            formatPercent(
                                                                row.total_hit_rate,
                                                            )
                                                        }}
                                                    </div>
                                                    <div
                                                        class="text-xs text-muted-foreground"
                                                    >
                                                        n={{ row.total_sample }}
                                                    </div>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <div
                                                        class="font-medium tabular-nums"
                                                        :class="
                                                            improvementTone(
                                                                row.roi,
                                                            )
                                                        "
                                                    >
                                                        {{
                                                            formatPercent(
                                                                row.roi,
                                                            )
                                                        }}
                                                    </div>
                                                    <div
                                                        class="text-xs text-muted-foreground"
                                                    >
                                                        Shadow
                                                        {{
                                                            formatPercent(
                                                                row.shadow_roi,
                                                            )
                                                        }}
                                                    </div>
                                                </td>
                                                <td
                                                    class="px-4 py-3 font-medium tabular-nums"
                                                    :class="
                                                        improvementTone(
                                                            row.avg_clv,
                                                        )
                                                    "
                                                >
                                                    {{
                                                        formatSigned(
                                                            row.avg_clv,
                                                            4,
                                                        )
                                                    }}
                                                </td>
                                                <td
                                                    class="px-4 py-3 font-medium tabular-nums"
                                                    :class="
                                                        improvementTone(
                                                            row.avg_calibration_lift,
                                                        )
                                                    "
                                                >
                                                    {{
                                                        formatSigned(
                                                            row.avg_calibration_lift,
                                                            4,
                                                        )
                                                    }}
                                                </td>
                                                <td class="px-4 py-3">
                                                    <div
                                                        class="tabular-nums"
                                                        :class="
                                                            improvementTone(
                                                                row.avg_spread_error_lift,
                                                            )
                                                        "
                                                    >
                                                        Spread
                                                        {{
                                                            formatSigned(
                                                                row.avg_spread_error_lift,
                                                                2,
                                                            )
                                                        }}
                                                    </div>
                                                    <div
                                                        class="mt-1 tabular-nums"
                                                        :class="
                                                            improvementTone(
                                                                row.avg_total_error_lift,
                                                            )
                                                        "
                                                    >
                                                        Total
                                                        {{
                                                            formatSigned(
                                                                row.avg_total_error_lift,
                                                                2,
                                                            )
                                                        }}
                                                    </div>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <div
                                                        class="font-medium tabular-nums"
                                                    >
                                                        {{
                                                            row.positive_roi_windows ??
                                                            0
                                                        }}/{{
                                                            row.roi_window_count ??
                                                            0
                                                        }}
                                                        positive ROI
                                                    </div>
                                                    <div
                                                        class="mt-1 text-xs text-muted-foreground tabular-nums"
                                                    >
                                                        {{
                                                            row.window_count ??
                                                            0
                                                        }}
                                                        seasons · range
                                                        {{
                                                            formatPercent(
                                                                row.winner_accuracy_range ??
                                                                    null,
                                                            )
                                                        }}
                                                    </div>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </section>

                            <section class="overflow-hidden rounded-md border">
                                <div
                                    class="flex items-center justify-between gap-3 border-b bg-muted/40 px-4 py-3"
                                >
                                    <h2 class="text-sm font-semibold">
                                        Chronological Windows
                                    </h2>
                                    <span
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{
                                            labelize(
                                                selectedSignalKeys[
                                                    activeSignalType
                                                ],
                                            )
                                        }}
                                    </span>
                                </div>
                                <div
                                    v-if="activeSignalWindows.length === 0"
                                    class="px-6 py-10 text-center text-sm text-muted-foreground"
                                >
                                    No completed season windows.
                                </div>
                                <div v-else class="overflow-x-auto">
                                    <table
                                        class="w-full min-w-[1040px] text-left text-sm"
                                    >
                                        <thead
                                            class="border-b bg-muted/20 text-xs text-muted-foreground"
                                        >
                                            <tr>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Season
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Sample
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Winner / ATS / Total
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    ROI / CLV
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Calibration Lift
                                                </th>
                                                <th
                                                    class="px-4 py-3 font-medium"
                                                >
                                                    Spread / Total Error Lift
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y">
                                            <tr
                                                v-for="window in activeSignalWindows"
                                                :key="`${window.signal_type}:${window.signal_key}:${window.season}`"
                                            >
                                                <td
                                                    class="px-4 py-3 font-semibold"
                                                >
                                                    {{ window.season }}
                                                </td>
                                                <td
                                                    class="px-4 py-3 tabular-nums"
                                                >
                                                    {{
                                                        window.observation_count
                                                    }}
                                                </td>
                                                <td
                                                    class="px-4 py-3 tabular-nums"
                                                >
                                                    {{
                                                        formatPercent(
                                                            window.winner_accuracy,
                                                        )
                                                    }}
                                                    /
                                                    {{
                                                        formatPercent(
                                                            window.ats_hit_rate,
                                                        )
                                                    }}
                                                    /
                                                    {{
                                                        formatPercent(
                                                            window.total_hit_rate,
                                                        )
                                                    }}
                                                </td>
                                                <td class="px-4 py-3">
                                                    <span
                                                        class="font-medium tabular-nums"
                                                        :class="
                                                            improvementTone(
                                                                window.roi,
                                                            )
                                                        "
                                                    >
                                                        {{
                                                            formatPercent(
                                                                window.roi,
                                                            )
                                                        }}
                                                    </span>
                                                    <span
                                                        class="text-muted-foreground"
                                                    >
                                                        /
                                                    </span>
                                                    <span
                                                        class="font-medium tabular-nums"
                                                        :class="
                                                            improvementTone(
                                                                window.avg_clv,
                                                            )
                                                        "
                                                    >
                                                        {{
                                                            formatSigned(
                                                                window.avg_clv,
                                                                4,
                                                            )
                                                        }}
                                                    </span>
                                                </td>
                                                <td
                                                    class="px-4 py-3 font-medium tabular-nums"
                                                    :class="
                                                        improvementTone(
                                                            window.avg_calibration_lift,
                                                        )
                                                    "
                                                >
                                                    {{
                                                        formatSigned(
                                                            window.avg_calibration_lift,
                                                            4,
                                                        )
                                                    }}
                                                </td>
                                                <td class="px-4 py-3">
                                                    <span
                                                        class="tabular-nums"
                                                        :class="
                                                            improvementTone(
                                                                window.avg_spread_error_lift,
                                                            )
                                                        "
                                                    >
                                                        {{
                                                            formatSigned(
                                                                window.avg_spread_error_lift,
                                                                2,
                                                            )
                                                        }}
                                                    </span>
                                                    <span
                                                        class="text-muted-foreground"
                                                    >
                                                        /
                                                    </span>
                                                    <span
                                                        class="tabular-nums"
                                                        :class="
                                                            improvementTone(
                                                                window.avg_total_error_lift,
                                                            )
                                                        "
                                                    >
                                                        {{
                                                            formatSigned(
                                                                window.avg_total_error_lift,
                                                                2,
                                                            )
                                                        }}
                                                    </span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        </TabsContent>

                        <TabsContent value="lineage" class="space-y-5">
                            <section class="rounded-md border bg-card">
                                <div
                                    class="flex items-center gap-2 border-b px-4 py-3"
                                >
                                    <Database class="h-4 w-4 text-sky-600" />
                                    <h2 class="text-sm font-semibold">
                                        Reproducibility
                                    </h2>
                                </div>
                                <dl
                                    class="grid gap-x-8 gap-y-0 px-4 sm:grid-cols-2"
                                >
                                    <div class="border-b py-3">
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Artifact ID
                                        </dt>
                                        <dd
                                            class="mt-1 font-mono text-xs break-all"
                                        >
                                            {{ artifact.id }}
                                        </dd>
                                    </div>
                                    <div class="border-b py-3">
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Training Run
                                        </dt>
                                        <dd
                                            class="mt-1 font-mono text-xs break-all"
                                        >
                                            {{ artifact.training_run_id }}
                                        </dd>
                                    </div>
                                    <div class="border-b py-3">
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Config Hash
                                        </dt>
                                        <dd
                                            class="mt-1 font-mono text-xs break-all"
                                        >
                                            {{ artifact.config_hash ?? 'N/A' }}
                                        </dd>
                                    </div>
                                    <div class="border-b py-3">
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Code Version
                                        </dt>
                                        <dd
                                            class="mt-1 font-mono text-xs break-all"
                                        >
                                            {{ artifact.code_version ?? 'N/A' }}
                                        </dd>
                                    </div>
                                    <div class="border-b py-3">
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Artifact Hash
                                        </dt>
                                        <dd
                                            class="mt-1 font-mono text-xs break-all"
                                        >
                                            {{ artifact.artifact_hash }}
                                        </dd>
                                    </div>
                                    <div class="border-b py-3">
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Dataset Hash
                                        </dt>
                                        <dd
                                            class="mt-1 font-mono text-xs break-all"
                                        >
                                            {{ artifact.dataset_hash }}
                                        </dd>
                                    </div>
                                    <div class="border-b py-3">
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Evaluation Report Hash
                                        </dt>
                                        <dd
                                            class="mt-1 font-mono text-xs break-all"
                                        >
                                            {{
                                                artifact.evaluation_report_hash ??
                                                'N/A'
                                            }}
                                        </dd>
                                    </div>
                                    <div class="border-b py-3">
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Promoted At
                                        </dt>
                                        <dd class="mt-1 text-sm">
                                            {{
                                                formatDate(artifact.promoted_at)
                                            }}
                                        </dd>
                                    </div>
                                </dl>
                            </section>

                            <section class="rounded-md border bg-card">
                                <div class="border-b px-4 py-3">
                                    <h2 class="text-sm font-semibold">
                                        Promotion Gates
                                    </h2>
                                </div>
                                <div
                                    class="grid gap-2 p-4 sm:grid-cols-2 lg:grid-cols-4"
                                >
                                    <div
                                        v-for="(
                                            passed, gate
                                        ) in artifact.promotion_checks"
                                        :key="gate"
                                        class="flex items-center gap-2 rounded-md border px-3 py-2"
                                    >
                                        <CheckCircle2
                                            v-if="passed"
                                            class="h-4 w-4 text-emerald-600"
                                        />
                                        <Ban
                                            v-else
                                            class="h-4 w-4 text-rose-600"
                                        />
                                        <span class="text-xs font-medium">
                                            {{ labelize(String(gate)) }}
                                        </span>
                                    </div>
                                </div>
                            </section>
                        </TabsContent>
                    </Tabs>
                </template>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
