<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    Ban,
    ChartNoAxesCombined,
    CircleDollarSign,
    Clock3,
    Database,
    GitCompare,
    ShieldCheck,
    Target,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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

type ArtifactLineage = ArtifactOption & {
    training_run_id: string;
    feature_version: string;
    artifact_hash: string;
    dataset_hash: string;
    config_hash: string | null;
    code_version: string | null;
};

type Artifact = ArtifactLineage & {
    sport: string;
    evaluation_report_hash: string | null;
    run_type: string | null;
    metrics: Record<string, unknown> | null;
    evaluation_summary: Record<string, unknown>;
    promotion_checks: Record<string, boolean>;
    promotion_markets: Record<string, unknown>;
    promoted_markets: string[];
    promotion_summary: Record<string, unknown>;
    delta_convention: Record<string, string>;
    public_output_changed: boolean;
};

type DataHealth = {
    season: number;
    pregame_safe_snapshots: number;
    latest_pregame_snapshot_at: string | null;
    snapshot_age_hours: number | null;
    pregame_market_quotes: number;
    latest_market_quote_at: string | null;
    quote_age_hours: number | null;
    upcoming_games: number;
    probable_pitchers_ready: number;
    probable_pitcher_coverage: number | null;
    games_with_market_quotes: number;
    market_quote_coverage: number | null;
    market_coverage: Array<{
        market: string;
        quote_count: number;
        game_count: number;
    }>;
};

type Summary = {
    shadow_observations: number;
    decisions: number;
    tracking_bets: number;
    no_bets: number;
    settled_decisions: number;
    pending_decisions: number;
    profit_units: number;
    roi: number | null;
    average_clv: number | null;
    calibration_games: number;
    baseline_brier: number | null;
    challenger_brier: number | null;
    brier_delta: number | null;
};

type WeeklyPerformance = {
    week_start: string;
    games: number;
    baseline_brier: number | null;
    challenger_brier: number | null;
    baseline_log_loss: number | null;
    challenger_log_loss: number | null;
    baseline_accuracy: number | null;
    challenger_accuracy: number | null;
    baseline_margin_mae: number | null;
    challenger_margin_mae: number | null;
    baseline_total_mae: number | null;
    challenger_total_mae: number | null;
};

type MarketPerformance = {
    market: string;
    decisions: number;
    bets: number;
    no_bets: number;
    settled: number;
    pending: number;
    profit_units: number;
    roi: number | null;
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
    market_type: string;
    baseline_output: number;
    challenger_output: number;
    output_delta: number;
    status: string;
    generated_at: string | null;
    pregame_safe: boolean;
    feature_hash: string | null;
    inference_run_id: string;
    decision: {
        status: string;
        side: string;
        price: number | null;
        edge: number | null;
        is_bet: boolean;
        is_public: boolean;
        is_tracking_only: boolean;
        reasons: string[];
    } | null;
    settlement: {
        status: string;
        profit_units: number | null;
        clv: number | null;
        settled_at: string | null;
    } | null;
};

type InferenceFailure = {
    id: string;
    run_type: string;
    model_version: string;
    feature_version: string;
    status: string;
    started_at: string | null;
    completed_at: string | null;
    error: string;
};

const props = defineProps<{
    artifacts: ArtifactOption[];
    artifact: Artifact | null;
    lineage: {
        active: ArtifactLineage | null;
        challenger: ArtifactLineage | null;
    };
    data_health: DataHealth;
    summary: Summary;
    market_performance: MarketPerformance[];
    weekly_performance: WeeklyPerformance[];
    evaluation_windows: Array<Record<string, unknown>>;
    observations: Observation[];
    no_bet_reasons: Array<{ reason: string; count: number }>;
    inference_failures: InferenceFailure[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/settings/admin' },
    { title: 'MLB Model Monitor', href: '/admin/mlb-model-monitoring' },
];

const maximumReasonCount = computed(() =>
    Math.max(1, ...props.no_bet_reasons.map((row) => row.count)),
);

function selectArtifact(event: Event): void {
    const artifact = (event.target as HTMLSelectElement).value;

    router.get('/admin/mlb-model-monitoring', artifact ? { artifact } : {}, {
        preserveScroll: true,
        preserveState: true,
    });
}

function formatPercent(value: number | null, digits = 1): string {
    return value === null ? 'N/A' : `${(value * 100).toFixed(digits)}%`;
}

function formatNumber(value: number | null, digits = 3): string {
    return value === null ? 'N/A' : value.toFixed(digits);
}

function formatUnits(value: number | null): string {
    if (value === null) return 'N/A';
    return `${value >= 0 ? '+' : ''}${value.toFixed(2)}u`;
}

function formatDate(value: string | null): string {
    if (!value) return 'Never';
    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

function labelize(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function shortHash(value: string | null): string {
    return value ? `${value.slice(0, 10)}...${value.slice(-6)}` : 'N/A';
}

function freshnessLabel(hours: number | null): string {
    if (hours === null) return 'No data';
    if (hours < 1) return `${Math.max(1, Math.round(hours * 60))}m ago`;
    return `${hours.toFixed(1)}h ago`;
}

function statusClass(status: string): string {
    if (status === 'promoted' || status === 'completed') {
        return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300';
    }
    if (['failed', 'failure', 'error'].includes(status)) {
        return 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300';
    }
    return 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300';
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="MLB Model Monitor" />

        <SettingsLayout full-width>
            <div class="min-w-0 space-y-6">
                <header
                    class="flex flex-col gap-4 border-b pb-5 sm:flex-row sm:items-end sm:justify-between"
                >
                    <div class="min-w-0">
                        <div
                            class="flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <ChartNoAxesCombined class="h-4 w-4" />
                            {{ data_health.season }} in-season model operations
                        </div>
                        <h1 class="mt-1 text-2xl font-semibold">
                            MLB Model Monitor
                        </h1>
                        <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                            Artifact promotion, pregame data readiness, live
                            shadow evidence, and betting performance by market.
                        </p>
                    </div>

                    <label class="w-full sm:w-80">
                        <span
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                        >
                            Artifact
                        </span>
                        <select
                            class="h-10 w-full rounded-md border bg-background px-3 text-sm"
                            :value="artifact?.id ?? ''"
                            @change="selectArtifact"
                        >
                            <option v-if="artifacts.length === 0" value="">
                                No MLB artifacts registered
                            </option>
                            <option
                                v-for="option in artifacts"
                                :key="option.id"
                                :value="option.id"
                            >
                                {{ option.model_version }} ·
                                {{ labelize(option.status) }} ·
                                {{ labelize(option.market_type) }}
                            </option>
                        </select>
                    </label>
                </header>

                <section
                    v-if="artifact"
                    class="grid gap-4 border-b pb-6 lg:grid-cols-[minmax(0,1.5fr)_repeat(3,minmax(0,1fr))]"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="rounded px-2 py-1 text-xs font-semibold"
                                :class="statusClass(artifact.status)"
                            >
                                {{ labelize(artifact.status) }}
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ labelize(artifact.market_type) }}
                            </span>
                        </div>
                        <div class="mt-3 truncate text-lg font-semibold">
                            {{ artifact.model_version }}
                        </div>
                        <div class="mt-1 text-sm text-muted-foreground">
                            {{ labelize(artifact.model_type) }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-muted-foreground">
                            Promotion
                        </div>
                        <div class="mt-1 text-sm font-medium">
                            {{
                                artifact.status === 'promoted'
                                    ? 'Active model'
                                    : 'Challenger only'
                            }}
                        </div>
                        <div class="mt-1 text-xs text-muted-foreground">
                            {{ artifact.promoted_markets.length }} approved
                            market(s)
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-muted-foreground">
                            Training run
                        </div>
                        <div class="mt-1 truncate font-mono text-xs">
                            {{ shortHash(artifact.training_run_id) }}
                        </div>
                        <div class="mt-1 text-xs text-muted-foreground">
                            {{ artifact.run_type ?? 'Unknown run type' }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-muted-foreground">
                            Public output
                        </div>
                        <div
                            class="mt-1 text-sm font-medium"
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
                </section>

                <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <div class="rounded-md border bg-card p-4">
                        <Database class="h-4 w-4 text-sky-600" />
                        <div class="mt-3 text-2xl font-bold tabular-nums">
                            {{ data_health.pregame_safe_snapshots }}
                        </div>
                        <div class="text-xs text-muted-foreground">
                            Pregame-safe snapshots
                        </div>
                        <div class="mt-2 text-xs">
                            {{ freshnessLabel(data_health.snapshot_age_hours) }}
                        </div>
                    </div>
                    <div class="rounded-md border bg-card p-4">
                        <Users class="h-4 w-4 text-teal-600" />
                        <div class="mt-3 text-2xl font-bold tabular-nums">
                            {{
                                formatPercent(
                                    data_health.probable_pitcher_coverage,
                                    0,
                                )
                            }}
                        </div>
                        <div class="text-xs text-muted-foreground">
                            Probable-pitcher coverage
                        </div>
                        <div class="mt-2 text-xs">
                            {{ data_health.probable_pitchers_ready }} /
                            {{ data_health.upcoming_games }} upcoming
                        </div>
                    </div>
                    <div class="rounded-md border bg-card p-4">
                        <Target class="h-4 w-4 text-violet-600" />
                        <div class="mt-3 text-2xl font-bold tabular-nums">
                            {{
                                formatPercent(
                                    data_health.market_quote_coverage,
                                    0,
                                )
                            }}
                        </div>
                        <div class="text-xs text-muted-foreground">
                            Pregame quote coverage
                        </div>
                        <div class="mt-2 text-xs">
                            {{ freshnessLabel(data_health.quote_age_hours) }}
                        </div>
                    </div>
                    <div class="rounded-md border bg-card p-4">
                        <Activity class="h-4 w-4 text-emerald-600" />
                        <div class="mt-3 text-2xl font-bold tabular-nums">
                            {{ summary.shadow_observations }}
                        </div>
                        <div class="text-xs text-muted-foreground">
                            Live shadow outputs
                        </div>
                        <div class="mt-2 text-xs">
                            {{ summary.settled_decisions }} settled decisions
                        </div>
                    </div>
                </section>

                <section class="grid grid-cols-2 gap-3 lg:grid-cols-6">
                    <div class="rounded-md border p-3">
                        <GitCompare class="h-4 w-4 text-sky-600" />
                        <div class="mt-2 text-xl font-bold">
                            {{ summary.decisions }}
                        </div>
                        <div class="text-xs text-muted-foreground">
                            Decisions
                        </div>
                    </div>
                    <div class="rounded-md border p-3">
                        <CircleDollarSign class="h-4 w-4 text-emerald-600" />
                        <div class="mt-2 text-xl font-bold">
                            {{ summary.tracking_bets }}
                        </div>
                        <div class="text-xs text-muted-foreground">
                            Shadow bets
                        </div>
                    </div>
                    <div class="rounded-md border p-3">
                        <Ban class="h-4 w-4 text-amber-600" />
                        <div class="mt-2 text-xl font-bold">
                            {{ summary.no_bets }}
                        </div>
                        <div class="text-xs text-muted-foreground">No bets</div>
                    </div>
                    <div class="rounded-md border p-3">
                        <Clock3 class="h-4 w-4 text-slate-600" />
                        <div class="mt-2 text-xl font-bold">
                            {{ summary.pending_decisions }}
                        </div>
                        <div class="text-xs text-muted-foreground">Pending</div>
                    </div>
                    <div class="rounded-md border p-3">
                        <CircleDollarSign class="h-4 w-4 text-teal-600" />
                        <div class="mt-2 text-xl font-bold">
                            {{ formatPercent(summary.roi) }}
                        </div>
                        <div class="text-xs text-muted-foreground">
                            Settled ROI
                        </div>
                    </div>
                    <div class="rounded-md border p-3">
                        <ShieldCheck class="h-4 w-4 text-violet-600" />
                        <div class="mt-2 text-xl font-bold">
                            {{ formatNumber(summary.average_clv, 3) }}
                        </div>
                        <div class="text-xs text-muted-foreground">
                            Average CLV
                        </div>
                    </div>
                </section>

                <Tabs default-value="live" class="space-y-4">
                    <TabsList class="grid h-auto w-full grid-cols-5">
                        <TabsTrigger value="live">Live</TabsTrigger>
                        <TabsTrigger value="weekly">Weekly</TabsTrigger>
                        <TabsTrigger value="markets">Markets</TabsTrigger>
                        <TabsTrigger value="failures">Failures</TabsTrigger>
                        <TabsTrigger value="lineage">Lineage</TabsTrigger>
                    </TabsList>

                    <TabsContent value="live" class="space-y-5">
                        <section
                            v-if="no_bet_reasons.length > 0"
                            class="rounded-md border p-4"
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
                            <div class="mt-4 grid gap-3 md:grid-cols-2">
                                <div
                                    v-for="row in no_bet_reasons"
                                    :key="row.reason"
                                    class="min-w-0"
                                >
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
                                No MLB shadow observations yet.
                            </div>
                            <div v-else class="overflow-x-auto">
                                <table
                                    class="w-full min-w-[920px] text-left text-sm"
                                >
                                    <thead
                                        class="border-b bg-muted/20 text-xs text-muted-foreground"
                                    >
                                        <tr>
                                            <th class="px-4 py-3 font-medium">
                                                Matchup
                                            </th>
                                            <th class="px-4 py-3 font-medium">
                                                Market
                                            </th>
                                            <th class="px-4 py-3 font-medium">
                                                Picksports
                                            </th>
                                            <th class="px-4 py-3 font-medium">
                                                Challenger
                                            </th>
                                            <th class="px-4 py-3 font-medium">
                                                Decision
                                            </th>
                                            <th class="px-4 py-3 font-medium">
                                                Result
                                            </th>
                                            <th class="px-4 py-3 font-medium">
                                                Generated
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y">
                                        <tr
                                            v-for="row in observations"
                                            :key="row.id"
                                        >
                                            <td class="px-4 py-3">
                                                <div class="font-medium">
                                                    {{ row.matchup }}
                                                </div>
                                                <div
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    {{
                                                        row.game_date ??
                                                        'Date pending'
                                                    }}
                                                </div>
                                            </td>
                                            <td class="px-4 py-3">
                                                {{ row.market_type }}
                                            </td>
                                            <td class="px-4 py-3 tabular-nums">
                                                {{
                                                    formatPercent(
                                                        row.baseline_output,
                                                    )
                                                }}
                                            </td>
                                            <td class="px-4 py-3 tabular-nums">
                                                {{
                                                    formatPercent(
                                                        row.challenger_output,
                                                    )
                                                }}
                                                <div
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    {{
                                                        formatPercent(
                                                            row.output_delta,
                                                        )
                                                    }}
                                                </div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span v-if="row.decision">
                                                    {{
                                                        labelize(
                                                            row.decision.status,
                                                        )
                                                    }}
                                                </span>
                                                <span
                                                    v-else
                                                    class="text-muted-foreground"
                                                >
                                                    Not recorded
                                                </span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span v-if="row.settlement">
                                                    {{
                                                        labelize(
                                                            row.settlement
                                                                .status,
                                                        )
                                                    }}
                                                    ·
                                                    {{
                                                        formatUnits(
                                                            row.settlement
                                                                .profit_units,
                                                        )
                                                    }}
                                                </span>
                                                <span
                                                    v-else
                                                    class="text-muted-foreground"
                                                >
                                                    Pending
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-xs">
                                                {{
                                                    formatDate(row.generated_at)
                                                }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </TabsContent>

                    <TabsContent value="weekly">
                        <section class="overflow-hidden rounded-md border">
                            <div class="border-b bg-muted/40 px-4 py-3">
                                <h2 class="text-sm font-semibold">
                                    Rolling Weekly Performance
                                </h2>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Lower Brier, log loss, and MAE are better.
                                </p>
                            </div>
                            <div
                                v-if="weekly_performance.length === 0"
                                class="px-6 py-12 text-center text-sm text-muted-foreground"
                            >
                                Settled shadow games will populate weekly
                                comparisons.
                            </div>
                            <div v-else class="overflow-x-auto">
                                <table
                                    class="w-full min-w-[1180px] text-left text-sm"
                                >
                                    <thead class="border-b bg-muted/20 text-xs">
                                        <tr>
                                            <th class="px-4 py-3">Week</th>
                                            <th class="px-4 py-3">Games</th>
                                            <th class="px-4 py-3">
                                                Brier P / C
                                            </th>
                                            <th class="px-4 py-3">
                                                Log loss P / C
                                            </th>
                                            <th class="px-4 py-3">
                                                Accuracy P / C
                                            </th>
                                            <th class="px-4 py-3">
                                                Margin MAE P / C
                                            </th>
                                            <th class="px-4 py-3">
                                                Total MAE P / C
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y">
                                        <tr
                                            v-for="week in weekly_performance"
                                            :key="week.week_start"
                                        >
                                            <td class="px-4 py-3 font-medium">
                                                {{ week.week_start }}
                                            </td>
                                            <td class="px-4 py-3">
                                                {{ week.games }}
                                            </td>
                                            <td class="px-4 py-3 tabular-nums">
                                                {{
                                                    formatNumber(
                                                        week.baseline_brier,
                                                    )
                                                }}
                                                /
                                                {{
                                                    formatNumber(
                                                        week.challenger_brier,
                                                    )
                                                }}
                                            </td>
                                            <td class="px-4 py-3 tabular-nums">
                                                {{
                                                    formatNumber(
                                                        week.baseline_log_loss,
                                                    )
                                                }}
                                                /
                                                {{
                                                    formatNumber(
                                                        week.challenger_log_loss,
                                                    )
                                                }}
                                            </td>
                                            <td class="px-4 py-3 tabular-nums">
                                                {{
                                                    formatPercent(
                                                        week.baseline_accuracy,
                                                    )
                                                }}
                                                /
                                                {{
                                                    formatPercent(
                                                        week.challenger_accuracy,
                                                    )
                                                }}
                                            </td>
                                            <td class="px-4 py-3 tabular-nums">
                                                {{
                                                    formatNumber(
                                                        week.baseline_margin_mae,
                                                        2,
                                                    )
                                                }}
                                                /
                                                {{
                                                    formatNumber(
                                                        week.challenger_margin_mae,
                                                        2,
                                                    )
                                                }}
                                            </td>
                                            <td class="px-4 py-3 tabular-nums">
                                                {{
                                                    formatNumber(
                                                        week.baseline_total_mae,
                                                        2,
                                                    )
                                                }}
                                                /
                                                {{
                                                    formatNumber(
                                                        week.challenger_total_mae,
                                                        2,
                                                    )
                                                }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </TabsContent>

                    <TabsContent value="markets">
                        <section class="overflow-hidden rounded-md border">
                            <div class="border-b bg-muted/40 px-4 py-3">
                                <h2 class="text-sm font-semibold">
                                    Performance by Market
                                </h2>
                            </div>
                            <div
                                v-if="market_performance.length === 0"
                                class="px-6 py-12 text-center text-sm text-muted-foreground"
                            >
                                No artifact-linked MLB decisions yet.
                            </div>
                            <div v-else class="overflow-x-auto">
                                <table
                                    class="w-full min-w-[920px] text-left text-sm"
                                >
                                    <thead class="border-b bg-muted/20 text-xs">
                                        <tr>
                                            <th class="px-4 py-3">Market</th>
                                            <th class="px-4 py-3">Decisions</th>
                                            <th class="px-4 py-3">Settled</th>
                                            <th class="px-4 py-3">
                                                Bets / No bets
                                            </th>
                                            <th class="px-4 py-3">Profit</th>
                                            <th class="px-4 py-3">ROI</th>
                                            <th class="px-4 py-3">CLV</th>
                                            <th class="px-4 py-3">
                                                Brier P / C
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y">
                                        <tr
                                            v-for="market in market_performance"
                                            :key="market.market"
                                        >
                                            <td class="px-4 py-3 font-medium">
                                                {{ market.market }}
                                            </td>
                                            <td class="px-4 py-3">
                                                {{ market.decisions }}
                                            </td>
                                            <td class="px-4 py-3">
                                                {{ market.settled }}
                                            </td>
                                            <td class="px-4 py-3">
                                                {{ market.bets }} /
                                                {{ market.no_bets }}
                                            </td>
                                            <td class="px-4 py-3">
                                                {{
                                                    formatUnits(
                                                        market.profit_units,
                                                    )
                                                }}
                                            </td>
                                            <td class="px-4 py-3">
                                                {{ formatPercent(market.roi) }}
                                            </td>
                                            <td class="px-4 py-3">
                                                {{
                                                    formatNumber(
                                                        market.average_clv,
                                                        3,
                                                    )
                                                }}
                                            </td>
                                            <td class="px-4 py-3">
                                                {{
                                                    formatNumber(
                                                        market.baseline_brier,
                                                    )
                                                }}
                                                /
                                                {{
                                                    formatNumber(
                                                        market.challenger_brier,
                                                    )
                                                }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </TabsContent>

                    <TabsContent value="failures">
                        <section class="overflow-hidden rounded-md border">
                            <div
                                class="flex items-center gap-2 border-b bg-muted/40 px-4 py-3"
                            >
                                <AlertTriangle class="h-4 w-4 text-amber-600" />
                                <h2 class="text-sm font-semibold">
                                    Recent Inference Failures
                                </h2>
                            </div>
                            <div
                                v-if="inference_failures.length === 0"
                                class="px-6 py-12 text-center text-sm text-muted-foreground"
                            >
                                No failed MLB inference runs recorded.
                            </div>
                            <div v-else class="divide-y">
                                <div
                                    v-for="failure in inference_failures"
                                    :key="failure.id"
                                    class="grid gap-2 px-4 py-4 md:grid-cols-[12rem_12rem_minmax(0,1fr)]"
                                >
                                    <div>
                                        <div class="text-sm font-medium">
                                            {{ failure.model_version }}
                                        </div>
                                        <div
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ formatDate(failure.started_at) }}
                                        </div>
                                    </div>
                                    <div>
                                        <span
                                            class="rounded px-2 py-1 text-xs font-semibold"
                                            :class="statusClass(failure.status)"
                                        >
                                            {{ labelize(failure.status) }}
                                        </span>
                                    </div>
                                    <div
                                        class="min-w-0 font-mono text-xs break-words text-red-700 dark:text-red-300"
                                    >
                                        {{ failure.error }}
                                    </div>
                                </div>
                            </div>
                        </section>
                    </TabsContent>

                    <TabsContent value="lineage" class="space-y-5">
                        <section class="grid gap-4 lg:grid-cols-2">
                            <div class="rounded-md border p-4">
                                <div class="flex items-center gap-2">
                                    <ShieldCheck
                                        class="h-4 w-4 text-emerald-600"
                                    />
                                    <h2 class="text-sm font-semibold">
                                        Active Artifact
                                    </h2>
                                </div>
                                <div
                                    v-if="!lineage.active"
                                    class="mt-6 text-sm text-muted-foreground"
                                >
                                    No promoted MLB artifact.
                                </div>
                                <dl v-else class="mt-4 grid gap-3 text-sm">
                                    <div>
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Model
                                        </dt>
                                        <dd class="font-medium">
                                            {{ lineage.active.model_version }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Run / config
                                        </dt>
                                        <dd class="font-mono text-xs">
                                            {{
                                                shortHash(
                                                    lineage.active
                                                        .training_run_id,
                                                )
                                            }}
                                            /
                                            {{
                                                shortHash(
                                                    lineage.active.config_hash,
                                                )
                                            }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Artifact / dataset
                                        </dt>
                                        <dd class="font-mono text-xs">
                                            {{
                                                shortHash(
                                                    lineage.active
                                                        .artifact_hash,
                                                )
                                            }}
                                            /
                                            {{
                                                shortHash(
                                                    lineage.active.dataset_hash,
                                                )
                                            }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                            <div class="rounded-md border p-4">
                                <div class="flex items-center gap-2">
                                    <GitCompare
                                        class="h-4 w-4 text-amber-600"
                                    />
                                    <h2 class="text-sm font-semibold">
                                        Challenger Artifact
                                    </h2>
                                </div>
                                <div
                                    v-if="!lineage.challenger"
                                    class="mt-6 text-sm text-muted-foreground"
                                >
                                    No MLB challenger registered.
                                </div>
                                <dl v-else class="mt-4 grid gap-3 text-sm">
                                    <div>
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Model
                                        </dt>
                                        <dd class="font-medium">
                                            {{
                                                lineage.challenger.model_version
                                            }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Status / market
                                        </dt>
                                        <dd>
                                            {{
                                                labelize(
                                                    lineage.challenger.status,
                                                )
                                            }}
                                            ·
                                            {{
                                                labelize(
                                                    lineage.challenger
                                                        .market_type,
                                                )
                                            }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Artifact / dataset
                                        </dt>
                                        <dd class="font-mono text-xs">
                                            {{
                                                shortHash(
                                                    lineage.challenger
                                                        .artifact_hash,
                                                )
                                            }}
                                            /
                                            {{
                                                shortHash(
                                                    lineage.challenger
                                                        .dataset_hash,
                                                )
                                            }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </section>

                        <section
                            v-if="artifact"
                            class="overflow-hidden rounded-md border"
                        >
                            <div class="border-b bg-muted/40 px-4 py-3">
                                <h2 class="text-sm font-semibold">
                                    Selected Artifact Provenance
                                </h2>
                            </div>
                            <dl
                                class="grid gap-x-6 gap-y-4 p-4 text-sm md:grid-cols-2"
                            >
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        Artifact ID
                                    </dt>
                                    <dd
                                        class="mt-1 font-mono text-xs break-all"
                                    >
                                        {{ artifact.id }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        Training run ID
                                    </dt>
                                    <dd
                                        class="mt-1 font-mono text-xs break-all"
                                    >
                                        {{ artifact.training_run_id }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        Artifact hash
                                    </dt>
                                    <dd
                                        class="mt-1 font-mono text-xs break-all"
                                    >
                                        {{ artifact.artifact_hash }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        Dataset hash
                                    </dt>
                                    <dd
                                        class="mt-1 font-mono text-xs break-all"
                                    >
                                        {{ artifact.dataset_hash }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        Configuration hash
                                    </dt>
                                    <dd
                                        class="mt-1 font-mono text-xs break-all"
                                    >
                                        {{ artifact.config_hash ?? 'N/A' }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        Code version
                                    </dt>
                                    <dd
                                        class="mt-1 font-mono text-xs break-all"
                                    >
                                        {{ artifact.code_version ?? 'N/A' }}
                                    </dd>
                                </div>
                            </dl>
                        </section>
                    </TabsContent>
                </Tabs>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
