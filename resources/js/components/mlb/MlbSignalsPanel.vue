<script setup lang="ts">
import {
    AlertTriangle,
    BadgeCheck,
    BarChart3,
    CircleDollarSign,
    Gauge,
    Medal,
    ShieldCheck,
    Target,
    TrendingUp,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useApiV2Client } from '@/composables/useApiV2Client';

interface MarketEdge {
    edge_percent_points?: number | null;
    fair_odds?: number | null;
    market_implied_probability?: number | null;
}

interface SignalRow {
    type: string;
    game_id?: number;
    team_id?: number;
    team_name?: string;
    matchup?: string;
    signal?: string;
    pick_side?: string;
    win_probability?: number;
    model_probability?: number;
    confidence_score?: number;
    predicted_spread?: number;
    vegas_spread?: number;
    predicted_total?: number;
    market_total?: number;
    market_line?: number;
    edge_runs?: number;
    champion_probability?: number;
    playoff_make_probability?: number;
    market_edge?: MarketEdge | null;
    classification?: string;
    score?: number;
    market_price?: number | null;
    market_implied_probability?: number | null;
    probability_edge?: number | null;
    venue_name?: string;
    run_environment?: string;
    runs_signal?: string;
    home_run_signal?: string;
    win_signal?: string;
    weather_signal?: string;
    total_adjustment?: number;
    streak?: string;
    length?: number;
    label?: string;
    reason_codes?: string[];
    risk_flags?: string[];
}

interface BackendReview {
    model: string;
    strengths: string[];
    watch_items: string[];
}

interface BetFilter {
    model: string;
    mode?: string;
    primary_market?: string;
    philosophy: string;
    risk_controls: string[];
}

interface OddsHealth {
    status: string;
    primary_market?: string;
    moneyline_ready?: boolean;
    slate_games: number;
    moneyline_coverage: number;
    run_line_coverage: number;
    total_coverage: number;
    stale_games: number;
    missing_markets: Array<{
        game_id: number;
        matchup: string;
        missing: string[];
    }>;
}

interface PassSummary {
    candidates: number;
    passes: number;
    pass_rate: number;
    top_reasons: Array<{
        reason: string;
        count: number;
    }>;
}

interface MoneylineReadiness {
    mode: string;
    slate_games: number;
    candidate_count: number;
    priced_count: number;
    priced_rate: number;
    bet_count: number;
    lean_count: number;
    pass_count: number;
    positive_market_edge_count: number;
    usable_count: number;
    top_pass_reasons: Array<{
        reason: string;
        count: number;
    }>;
}

interface SignalsPayload {
    season: number;
    as_of_date: string;
    slate_date?: string | null;
    backend_review: BackendReview;
    odds_health?: OddsHealth;
    bet_filter?: BetFilter;
    moneyline_readiness?: MoneylineReadiness;
    pass_summary?: PassSummary;
    recommended_bets: SignalRow[];
    world_series: SignalRow[];
    moneyline: SignalRow[];
    run_line: SignalRow[];
    totals: SignalRow[];
    ballpark: SignalRow[];
    streaks: SignalRow[];
}

const props = defineProps<{
    season?: string | number | null;
}>();

const loading = ref(true);
const error = ref<string | null>(null);
const payload = ref<SignalsPayload | null>(null);
const api = useApiV2Client();

const bestBets = computed(() => payload.value?.recommended_bets ?? []);
const moneylineReadiness = computed(
    () => payload.value?.moneyline_readiness ?? null,
);

const marketModeLabel = computed(() => {
    const mode = payload.value?.bet_filter?.mode;
    if (mode === 'moneyline_first') return 'Moneyline-first';
    return 'Multi-market';
});

const moneylineReadyLabel = computed(() => {
    if (!payload.value?.odds_health) return 'No slate';
    return payload.value.odds_health.moneyline_ready ? 'Ready' : 'Needs prices';
});

const signalGroups = computed(() => [
    {
        key: 'world_series',
        title: 'World Series',
        icon: Medal,
        rows: payload.value?.world_series ?? [],
        metric: (row: SignalRow) => formatPercent(row.champion_probability),
        detail: (row: SignalRow) => {
            const edge = row.market_edge?.edge_percent_points;
            const edgeText =
                edge != null ? ` | ${edge.toFixed(1)} market edge` : '';

            return `${formatPercent(row.playoff_make_probability)} playoff${edgeText}`;
        },
    },
    {
        key: 'moneyline',
        title: 'Moneyline',
        icon: BadgeCheck,
        rows: payload.value?.moneyline ?? [],
        metric: (row: SignalRow) => formatPercent(row.win_probability),
        detail: (row: SignalRow) =>
            `${sideLabel(row.pick_side)} | ${row.matchup ?? ''}`,
    },
    {
        key: 'run_line',
        title: 'Run Line',
        icon: TrendingUp,
        rows: payload.value?.run_line ?? [],
        metric: (row: SignalRow) => formatRuns(row.edge_runs),
        detail: (row: SignalRow) =>
            `${sideLabel(row.pick_side)} | ${row.matchup ?? ''}`,
    },
    {
        key: 'totals',
        title: 'Totals',
        icon: CircleDollarSign,
        rows: payload.value?.totals ?? [],
        metric: (row: SignalRow) => formatRuns(row.edge_runs),
        detail: (row: SignalRow) =>
            `${sideLabel(row.pick_side)} | model ${formatNumber(row.predicted_total)} vs market ${formatNumber(row.market_total)}`,
    },
    {
        key: 'ballpark',
        title: 'Ballpark',
        icon: Gauge,
        rows: payload.value?.ballpark ?? [],
        metric: (row: SignalRow) => formatSignedRuns(row.total_adjustment),
        detail: (row: SignalRow) =>
            `${row.venue_name ?? row.matchup ?? ''} | ${labelize(row.home_run_signal)} | ${labelize(row.runs_signal)}`,
    },
    {
        key: 'streaks',
        title: 'Streaks',
        icon: AlertTriangle,
        rows: payload.value?.streaks ?? [],
        metric: (row: SignalRow) =>
            row.length != null ? `${row.length}x` : null,
        detail: (row: SignalRow) => row.label ?? labelize(row.streak),
    },
]);

const totalSignalCount = computed(() =>
    signalGroups.value.reduce((total, group) => total + group.rows.length, 0),
);

const visibleSignalGroups = computed(() =>
    signalGroups.value.filter((group) => group.rows.length > 0),
);

const bettingSignalMix = computed(() => [
    {
        key: 'recommended',
        label: 'Best Bets',
        count: bestBets.value.length,
        detail: 'passed filter',
    },
    {
        key: 'moneyline',
        label: 'Moneyline',
        count: payload.value?.moneyline?.length ?? 0,
        detail: 'win edge',
    },
    {
        key: 'run_line',
        label: 'Run Line',
        count: payload.value?.run_line?.length ?? 0,
        detail: 'spread edge',
    },
    {
        key: 'totals',
        label: 'Totals',
        count: payload.value?.totals?.length ?? 0,
        detail: 'run total',
    },
    {
        key: 'context',
        label: 'Park/Streak',
        count:
            (payload.value?.ballpark?.length ?? 0) +
            (payload.value?.streaks?.length ?? 0),
        detail: 'context',
    },
]);

const maxSignalMixCount = computed(() =>
    Math.max(...bettingSignalMix.value.map((item) => item.count), 1),
);

const topBet = computed(() => bestBets.value[0] ?? null);

function formatPercent(value?: number | null): string | null {
    if (value == null) return null;
    return `${Math.round(value * 100)}%`;
}

function formatRuns(value?: number | null): string | null {
    if (value == null) return null;
    return `${value.toFixed(1)} runs`;
}

function formatSignedRuns(value?: number | null): string | null {
    if (value == null) return null;
    return `${value >= 0 ? '+' : ''}${value.toFixed(1)} runs`;
}

function formatNumber(value?: number | null): string {
    if (value == null) return '-';
    return value.toFixed(1);
}

function formatAmericanOdds(value?: number | null): string {
    if (value == null) return '-';
    return value > 0 ? `+${value}` : `${value}`;
}

function formatProbabilityEdge(value?: number | null): string | null {
    if (value == null) return null;
    return `${value >= 0 ? '+' : ''}${(value * 100).toFixed(1)}%`;
}

function labelize(value?: string | null): string {
    if (!value) return '';
    return value.replaceAll('_', ' ');
}

function sideLabel(value?: string | null): string {
    if (!value) return '';
    return labelize(value);
}

function primaryReason(row: SignalRow): string {
    return labelize(row.reason_codes?.[0]);
}

function riskSummary(row: SignalRow): string {
    if (!row.risk_flags?.length) return 'no major risk flags';
    return row.risk_flags.slice(0, 2).map(labelize).join(' | ');
}

function pickLabel(row: SignalRow): string {
    const market = labelize(row.type);
    const side = sideLabel(row.pick_side);

    return `${market}: ${side}`;
}

function coverageLabel(value?: number): string {
    return value == null ? '0%' : `${value.toFixed(1)}%`;
}

function widthFromCount(count: number): string {
    if (count <= 0) return '0%';
    return `${Math.max(4, Math.round((count / maxSignalMixCount.value) * 100))}%`;
}

function classificationClass(value?: string | null): string {
    if (value === 'bet') {
        return 'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    }

    if (value === 'lean') {
        return 'border-sky-500/40 bg-sky-500/10 text-sky-700 dark:text-sky-300';
    }

    return 'border-muted-foreground/30 bg-muted text-muted-foreground';
}

function scoreBarClass(score?: number | null): string {
    if ((score ?? 0) >= 70) return 'bg-emerald-500';
    if ((score ?? 0) >= 55) return 'bg-sky-500';
    return 'bg-amber-500';
}

async function loadSignals(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const response = await api.signals.index<SignalsPayload>('mlb', {
            query: props.season ? { season: props.season } : undefined,
        });
        if (!response?.data) {
            throw new Error('Failed to load MLB signals');
        }

        payload.value = response.data;
    } catch (e) {
        error.value =
            e instanceof Error ? e.message : 'Unable to load MLB signals';
    } finally {
        loading.value = false;
    }
}

onMounted(loadSignals);
</script>

<template>
    <section class="space-y-3">
        <div v-if="loading" class="rounded-lg border bg-card p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="space-y-2">
                    <Skeleton class="h-5 w-36" />
                    <Skeleton class="h-3 w-72 max-w-full" />
                </div>
                <Skeleton class="h-8 w-32" />
            </div>
            <div class="mt-4 grid gap-3 md:grid-cols-3">
                <Skeleton v-for="i in 3" :key="i" class="h-24 w-full" />
            </div>
        </div>

        <Card v-else-if="error" class="border-destructive/40">
            <CardContent class="py-4 text-sm text-destructive">
                {{ error }}
            </CardContent>
        </Card>

        <template v-else>
            <Card class="overflow-hidden">
                <CardContent class="space-y-4 p-4 md:p-5">
                    <div
                        class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between"
                    >
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <CardTitle
                                    class="flex items-center gap-2 text-base"
                                >
                                    <ShieldCheck class="h-4 w-4" />
                                    MLB Slate Decision Board
                                </CardTitle>
                                <span
                                    v-if="payload?.slate_date"
                                    class="rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground"
                                >
                                    {{ payload.slate_date }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Betting signals for moneyline value, market
                                coverage, run environment, and model risk.
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2 text-xs">
                            <span class="rounded-full border px-2.5 py-1">
                                {{ marketModeLabel }}
                            </span>
                            <span class="rounded-full border px-2.5 py-1">
                                ML {{ moneylineReadyLabel }}
                            </span>
                            <span class="rounded-full border px-2.5 py-1">
                                {{ bestBets.length }} bets
                            </span>
                            <span class="rounded-full border px-2.5 py-1">
                                {{ totalSignalCount }} signals
                            </span>
                        </div>
                    </div>

                    <div class="grid gap-3 lg:grid-cols-[1.1fr_1fr]">
                        <div
                            class="rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-3"
                        >
                            <div
                                class="mb-2 flex items-center justify-between gap-3"
                            >
                                <div class="flex items-center gap-2">
                                    <Target class="h-4 w-4 text-emerald-600" />
                                    <div class="text-sm font-semibold">
                                        Top Betting Signal
                                    </div>
                                </div>
                                <span
                                    v-if="topBet"
                                    class="rounded-full border px-2.5 py-1 text-xs font-semibold uppercase"
                                    :class="
                                        classificationClass(
                                            topBet.classification,
                                        )
                                    "
                                >
                                    {{ topBet.classification }}
                                </span>
                            </div>
                            <div v-if="topBet" class="space-y-2">
                                <div
                                    class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                                >
                                    <div class="min-w-0">
                                        <div class="text-base font-semibold">
                                            {{
                                                topBet.team_name ||
                                                topBet.matchup
                                            }}
                                        </div>
                                        <div
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{ pickLabel(topBet) }} |
                                            {{ topBet.matchup }}
                                        </div>
                                    </div>
                                    <div
                                        class="shrink-0 text-left sm:text-right"
                                    >
                                        <div class="text-xl font-bold">
                                            {{ topBet.score ?? 0 }}
                                        </div>
                                        <div
                                            class="text-xs text-muted-foreground"
                                        >
                                            signal score
                                        </div>
                                    </div>
                                </div>
                                <div
                                    class="h-2 overflow-hidden rounded-full bg-background/80"
                                >
                                    <div
                                        class="h-full rounded-full"
                                        :class="scoreBarClass(topBet.score)"
                                        :style="{
                                            width: `${topBet.score ?? 0}%`,
                                        }"
                                    />
                                </div>
                                <div
                                    class="grid gap-2 text-xs text-muted-foreground sm:grid-cols-3"
                                >
                                    <div>
                                        <span
                                            class="font-medium text-foreground"
                                        >
                                            Model
                                        </span>
                                        {{
                                            formatPercent(
                                                topBet.model_probability ??
                                                    topBet.win_probability,
                                            ) ?? '-'
                                        }}
                                    </div>
                                    <div>
                                        <span
                                            class="font-medium text-foreground"
                                        >
                                            Odds
                                        </span>
                                        {{
                                            formatAmericanOdds(
                                                topBet.market_price,
                                            )
                                        }}
                                    </div>
                                    <div>
                                        <span
                                            class="font-medium text-foreground"
                                        >
                                            Edge
                                        </span>
                                        {{
                                            formatProbabilityEdge(
                                                topBet.probability_edge,
                                            ) ??
                                            formatRuns(topBet.edge_runs) ??
                                            '-'
                                        }}
                                    </div>
                                </div>
                                <div
                                    class="flex flex-wrap gap-2 text-xs text-muted-foreground"
                                >
                                    <span
                                        v-for="reason in topBet.reason_codes?.slice(
                                            0,
                                            4,
                                        ) ?? []"
                                        :key="`top-reason-${reason}`"
                                        class="rounded-md border bg-background px-2 py-1"
                                    >
                                        {{ labelize(reason) }}
                                    </span>
                                </div>
                            </div>
                            <div v-else class="text-sm text-muted-foreground">
                                No MLB bets passed the selective filter for the
                                current slate.
                            </div>
                        </div>

                        <div
                            class="rounded-lg border border-border/70 bg-muted/20 p-3"
                        >
                            <div class="mb-3 flex items-center gap-2">
                                <BarChart3 class="h-4 w-4" />
                                <div class="text-sm font-semibold">
                                    Signal Mix
                                </div>
                            </div>
                            <div class="space-y-2">
                                <div
                                    v-for="item in bettingSignalMix"
                                    :key="item.key"
                                >
                                    <div
                                        class="mb-1 flex items-center justify-between gap-3 text-xs"
                                    >
                                        <span class="font-medium">
                                            {{ item.label }}
                                        </span>
                                        <span class="text-muted-foreground">
                                            {{ item.count }}
                                            {{ item.detail }}
                                        </span>
                                    </div>
                                    <div
                                        class="h-2 overflow-hidden rounded-full bg-background"
                                    >
                                        <div
                                            class="h-full rounded-full bg-primary"
                                            :style="{
                                                width: widthFromCount(
                                                    item.count,
                                                ),
                                            }"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="moneylineReadiness"
                        class="grid gap-2 rounded-md border border-primary/30 bg-primary/5 p-3 text-xs text-muted-foreground sm:grid-cols-5"
                    >
                        <div>
                            <div class="font-medium text-foreground">Mode</div>
                            <div>{{ labelize(moneylineReadiness.mode) }}</div>
                        </div>
                        <div>
                            <div class="font-medium text-foreground">
                                Priced
                            </div>
                            <div>
                                {{ moneylineReadiness.priced_count }} /
                                {{ moneylineReadiness.candidate_count }}
                                ({{
                                    moneylineReadiness.priced_rate.toFixed(1)
                                }}%)
                            </div>
                        </div>
                        <div>
                            <div class="font-medium text-foreground">
                                Usable ML
                            </div>
                            <div>
                                {{ moneylineReadiness.usable_count }} bets/leans
                            </div>
                        </div>
                        <div>
                            <div class="font-medium text-foreground">
                                Market Edge
                            </div>
                            <div>
                                {{
                                    moneylineReadiness.positive_market_edge_count
                                }}
                                positive
                            </div>
                        </div>
                        <div>
                            <div class="font-medium text-foreground">
                                Passes
                            </div>
                            <div>{{ moneylineReadiness.pass_count }}</div>
                        </div>
                    </div>

                    <div
                        v-if="payload?.odds_health || payload?.pass_summary"
                        class="flex flex-wrap gap-2 text-xs text-muted-foreground"
                    >
                        <span
                            v-if="payload?.odds_health"
                            class="rounded-md border px-2 py-1"
                        >
                            Odds {{ labelize(payload.odds_health.status) }}
                        </span>
                        <span
                            v-if="payload?.odds_health"
                            class="rounded-md border px-2 py-1"
                        >
                            ML
                            {{
                                coverageLabel(
                                    payload.odds_health.moneyline_coverage,
                                )
                            }}
                        </span>
                        <span
                            v-if="payload?.odds_health"
                            class="rounded-md border px-2 py-1"
                        >
                            Totals
                            {{
                                coverageLabel(
                                    payload.odds_health.total_coverage,
                                )
                            }}
                        </span>
                        <span
                            v-if="payload?.pass_summary"
                            class="rounded-md border px-2 py-1"
                        >
                            Pass rate
                            {{ payload.pass_summary.pass_rate.toFixed(1) }}%
                        </span>
                    </div>

                    <div
                        v-if="bestBets.length > 0"
                        class="grid gap-3 md:grid-cols-2 xl:grid-cols-3"
                    >
                        <div
                            v-for="row in bestBets.slice(0, 8)"
                            :key="`best-bet-${row.type}-${row.game_id}-${row.pick_side}`"
                            class="rounded-lg border border-border/80 p-3"
                        >
                            <div
                                class="mb-2 flex items-start justify-between gap-3"
                            >
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold">
                                        {{ row.team_name || row.matchup }}
                                    </div>
                                    <div
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ pickLabel(row) }} | {{ row.matchup }}
                                    </div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div
                                        class="rounded-full border px-2 py-1 text-xs font-semibold uppercase"
                                        :class="
                                            classificationClass(
                                                row.classification,
                                            )
                                        "
                                    >
                                        {{ row.classification }}
                                    </div>
                                    <div
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{ row.score ?? 0 }} score
                                    </div>
                                </div>
                            </div>
                            <div
                                class="mb-2 h-1.5 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    class="h-full rounded-full"
                                    :class="scoreBarClass(row.score)"
                                    :style="{ width: `${row.score ?? 0}%` }"
                                />
                            </div>
                            <div class="grid gap-2 text-xs sm:grid-cols-3">
                                <div class="rounded-md bg-muted/40 px-2 py-1">
                                    <div class="text-muted-foreground">
                                        Model
                                    </div>
                                    <div class="font-medium">
                                        {{
                                            formatPercent(
                                                row.model_probability ??
                                                    row.win_probability,
                                            ) ??
                                            formatRuns(row.edge_runs) ??
                                            '-'
                                        }}
                                    </div>
                                </div>
                                <div class="rounded-md bg-muted/40 px-2 py-1">
                                    <div class="text-muted-foreground">
                                        Market
                                    </div>
                                    <div class="font-medium">
                                        {{
                                            row.market_price != null
                                                ? formatAmericanOdds(
                                                      row.market_price,
                                                  )
                                                : formatNumber(row.market_line)
                                        }}
                                    </div>
                                </div>
                                <div class="rounded-md bg-muted/40 px-2 py-1">
                                    <div class="text-muted-foreground">
                                        Edge
                                    </div>
                                    <div class="font-medium">
                                        {{
                                            formatProbabilityEdge(
                                                row.probability_edge,
                                            ) ??
                                            formatRuns(row.edge_runs) ??
                                            '-'
                                        }}
                                    </div>
                                </div>
                            </div>
                            <div
                                class="mt-2 flex flex-wrap gap-1.5 text-xs text-muted-foreground"
                            >
                                <span
                                    v-for="reason in row.reason_codes?.slice(
                                        0,
                                        3,
                                    ) ?? [primaryReason(row)]"
                                    :key="`${row.game_id}-${row.type}-${reason}`"
                                    class="rounded-md border px-2 py-1"
                                >
                                    {{ labelize(reason) }}
                                </span>
                            </div>
                            <div class="mt-2 text-xs text-muted-foreground">
                                Risk: {{ riskSummary(row) }}
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-sm text-muted-foreground">
                        No games passed the selective bet filter for this slate.
                    </div>

                    <details
                        v-if="visibleSignalGroups.length > 0"
                        class="group rounded-lg border border-border/70 bg-muted/20"
                    >
                        <summary
                            class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 text-sm font-semibold [&::-webkit-details-marker]:hidden"
                        >
                            <span>Secondary signal detail</span>
                            <span
                                class="text-xs font-medium text-muted-foreground transition-transform group-open:rotate-180"
                            >
                                v
                            </span>
                        </summary>
                        <div
                            class="grid gap-3 border-t border-border/70 p-3 md:grid-cols-2 xl:grid-cols-3"
                        >
                            <section
                                v-for="group in visibleSignalGroups"
                                :key="group.key"
                                class="rounded-lg border border-border/70 bg-card p-3"
                            >
                                <div
                                    class="mb-3 flex items-center justify-between gap-2"
                                >
                                    <h3
                                        class="flex items-center gap-2 text-sm font-semibold"
                                    >
                                        <component
                                            :is="group.icon"
                                            class="h-4 w-4"
                                        />
                                        {{ group.title }}
                                    </h3>
                                    <span class="text-xs text-muted-foreground">
                                        {{ group.rows.length }}
                                    </span>
                                </div>
                                <div class="space-y-2">
                                    <div
                                        v-for="row in group.rows.slice(0, 3)"
                                        :key="`${group.key}-${row.team_id}-${row.game_id}-${row.label}`"
                                        class="flex min-h-12 items-start justify-between gap-3 border-b pb-2 last:border-0 last:pb-0"
                                    >
                                        <div class="min-w-0">
                                            <div
                                                class="truncate text-sm font-medium"
                                            >
                                                {{
                                                    row.team_name || row.matchup
                                                }}
                                            </div>
                                            <div
                                                class="truncate text-xs text-muted-foreground"
                                            >
                                                {{ group.detail(row) }}
                                            </div>
                                        </div>
                                        <div
                                            class="shrink-0 text-sm font-semibold"
                                        >
                                            {{ group.metric(row) ?? '-' }}
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </details>

                    <details
                        v-if="
                            payload?.backend_review ||
                            payload?.bet_filter ||
                            payload?.pass_summary
                        "
                        class="group rounded-lg border border-border/70 bg-muted/20"
                    >
                        <summary
                            class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 text-sm font-semibold [&::-webkit-details-marker]:hidden"
                        >
                            <span>Model controls and diagnostics</span>
                            <span
                                class="text-xs font-medium text-muted-foreground transition-transform group-open:rotate-180"
                            >
                                v
                            </span>
                        </summary>
                        <div
                            class="grid gap-4 border-t border-border/70 p-3 text-sm md:grid-cols-2"
                        >
                            <div v-if="payload?.backend_review">
                                <div class="mb-2 font-medium">Model Inputs</div>
                                <div class="flex flex-wrap gap-2">
                                    <span
                                        v-for="item in payload.backend_review.strengths.slice(
                                            0,
                                            8,
                                        )"
                                        :key="item"
                                        class="rounded-md border px-2 py-1 text-xs text-muted-foreground"
                                    >
                                        {{ labelize(item) }}
                                    </span>
                                </div>
                            </div>
                            <div v-if="payload?.backend_review">
                                <div class="mb-2 font-medium">Watch Items</div>
                                <div class="flex flex-wrap gap-2">
                                    <span
                                        v-for="item in payload.backend_review
                                            .watch_items"
                                        :key="item"
                                        class="rounded-md border border-amber-300/60 px-2 py-1 text-xs text-muted-foreground"
                                    >
                                        {{ labelize(item) }}
                                    </span>
                                </div>
                            </div>
                            <div v-if="payload?.pass_summary">
                                <div class="mb-2 font-medium">Pass Reasons</div>
                                <div class="flex flex-wrap gap-2">
                                    <span
                                        v-for="reason in payload.pass_summary.top_reasons.slice(
                                            0,
                                            6,
                                        )"
                                        :key="reason.reason"
                                        class="rounded-md border px-2 py-1 text-xs text-muted-foreground"
                                    >
                                        {{ labelize(reason.reason) }}:
                                        {{ reason.count }}
                                    </span>
                                </div>
                            </div>
                            <div v-if="payload?.bet_filter">
                                <div class="mb-2 font-medium">
                                    Risk Controls
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <span
                                        v-for="item in payload.bet_filter.risk_controls.slice(
                                            0,
                                            8,
                                        )"
                                        :key="item"
                                        class="rounded-md border px-2 py-1 text-xs text-muted-foreground"
                                    >
                                        {{ labelize(item) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </details>
                </CardContent>
            </Card>
        </template>
    </section>
</template>
