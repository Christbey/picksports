<script setup lang="ts">
import {
    AlertTriangle,
    BadgeCheck,
    CircleDollarSign,
    Gauge,
    Medal,
    ShieldCheck,
    TrendingUp,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { fetchJson } from '@/composables/useApiClient';

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
    confidence_score?: number;
    predicted_spread?: number;
    vegas_spread?: number;
    predicted_total?: number;
    market_total?: number;
    edge_runs?: number;
    champion_probability?: number;
    playoff_make_probability?: number;
    market_edge?: MarketEdge | null;
    classification?: string;
    score?: number;
    market_price?: number | null;
    market_implied_probability?: number | null;
    probability_edge?: number | null;
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
    streaks: SignalRow[];
}

const props = defineProps<{
    season?: string | number | null;
}>();

const loading = ref(true);
const error = ref<string | null>(null);
const payload = ref<SignalsPayload | null>(null);

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
        key: 'streaks',
        title: 'Streaks',
        icon: AlertTriangle,
        rows: payload.value?.streaks ?? [],
        metric: (row: SignalRow) =>
            row.length != null ? `${row.length}x` : null,
        detail: (row: SignalRow) => row.label ?? labelize(row.streak),
    },
]);

function formatPercent(value?: number | null): string | null {
    if (value == null) return null;
    return `${Math.round(value * 100)}%`;
}

function formatRuns(value?: number | null): string | null {
    if (value == null) return null;
    return `${value.toFixed(1)} runs`;
}

function formatNumber(value?: number | null): string {
    if (value == null) return '-';
    return value.toFixed(1);
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

function valueDetail(row: SignalRow): string {
    const parts = [];
    if (row.market_price != null) {
        parts.push(
            `odds ${row.market_price > 0 ? '+' : ''}${row.market_price}`,
        );
    }
    if (row.probability_edge != null) {
        parts.push(`${(row.probability_edge * 100).toFixed(1)}% edge`);
    }
    if (row.edge_runs != null) {
        parts.push(`${row.edge_runs.toFixed(1)} runs`);
    }

    return parts.join(' | ');
}

function coverageLabel(value?: number): string {
    return value == null ? '0%' : `${value.toFixed(1)}%`;
}

async function loadSignals(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const params = new URLSearchParams();
        if (props.season) {
            params.set('season', String(props.season));
        }

        const response = await fetchJson<{ data: SignalsPayload }>(
            `/api/v1/mlb/signals${params.toString() ? `?${params}` : ''}`,
        );
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
        <div
            class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <h2 class="text-lg font-semibold">MLB Signals</h2>
                <p class="text-sm text-muted-foreground">
                    Moneyline-first slate edges, futures, and streak context
                    from the live model.
                </p>
            </div>
            <div class="text-sm text-muted-foreground">
                <span v-if="payload?.slate_date"
                    >Slate {{ payload.slate_date }}</span
                >
                <span v-if="payload" class="ml-2 rounded-md border px-2 py-1">
                    {{ marketModeLabel }}: {{ moneylineReadyLabel }}
                </span>
            </div>
        </div>

        <div v-if="loading" class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <Card v-for="i in 5" :key="i">
                <CardHeader class="space-y-2">
                    <Skeleton class="h-4 w-28" />
                    <Skeleton class="h-3 w-20" />
                </CardHeader>
                <CardContent class="space-y-3">
                    <Skeleton class="h-8 w-full" />
                    <Skeleton class="h-8 w-full" />
                    <Skeleton class="h-8 w-full" />
                </CardContent>
            </Card>
        </div>

        <Card v-else-if="error" class="border-destructive/40">
            <CardContent class="py-4 text-sm text-destructive">
                {{ error }}
            </CardContent>
        </Card>

        <template v-else>
            <Card>
                <CardHeader class="pb-3">
                    <CardTitle class="flex items-center gap-2 text-sm">
                        <ShieldCheck class="h-4 w-4" />
                        Moneyline-First Bet Filter
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div
                        v-if="moneylineReadiness"
                        class="grid gap-2 rounded-md border border-primary/30 bg-primary/5 p-3 text-xs text-muted-foreground sm:grid-cols-5"
                    >
                        <div>
                            <div class="font-medium text-foreground">
                                Moneyline Mode
                            </div>
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
                        v-if="bestBets.length > 0"
                        class="grid gap-3 md:grid-cols-2 xl:grid-cols-4"
                    >
                        <div
                            v-for="row in bestBets.slice(0, 8)"
                            :key="`best-bet-${row.type}-${row.game_id}-${row.pick_side}`"
                            class="min-h-28 rounded-md border p-3"
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
                                    <div class="text-sm font-semibold">
                                        {{ row.score ?? 0 }}
                                    </div>
                                    <div
                                        class="text-xs text-muted-foreground uppercase"
                                    >
                                        {{ row.classification }}
                                    </div>
                                </div>
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ primaryReason(row) }}
                            </div>
                            <div
                                v-if="valueDetail(row)"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{ valueDetail(row) }}
                            </div>
                            <div class="mt-1 text-xs text-muted-foreground">
                                Risk: {{ riskSummary(row) }}
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-sm text-muted-foreground">
                        No games passed the selective bet filter for this slate.
                    </div>
                    <div
                        v-if="payload?.odds_health"
                        class="grid gap-2 rounded-md border p-3 text-xs text-muted-foreground sm:grid-cols-4"
                    >
                        <div>
                            <div class="font-medium text-foreground">
                                Odds Health
                            </div>
                            <div>
                                {{ labelize(payload.odds_health.status) }}
                            </div>
                        </div>
                        <div>
                            <div class="font-medium text-foreground">
                                Moneyline
                            </div>
                            <div>
                                {{
                                    coverageLabel(
                                        payload.odds_health.moneyline_coverage,
                                    )
                                }}
                            </div>
                        </div>
                        <div>
                            <div class="font-medium text-foreground">
                                Run Line
                            </div>
                            <div>
                                {{
                                    coverageLabel(
                                        payload.odds_health.run_line_coverage,
                                    )
                                }}
                            </div>
                        </div>
                        <div>
                            <div class="font-medium text-foreground">
                                Totals
                            </div>
                            <div>
                                {{
                                    coverageLabel(
                                        payload.odds_health.total_coverage,
                                    )
                                }}
                            </div>
                        </div>
                    </div>
                    <div
                        v-if="payload?.pass_summary"
                        class="flex flex-wrap gap-2 text-xs text-muted-foreground"
                    >
                        <span class="rounded-md border px-2 py-1">
                            Pass rate
                            {{ payload.pass_summary.pass_rate.toFixed(1) }}%
                        </span>
                        <span
                            v-for="reason in payload.pass_summary.top_reasons.slice(
                                0,
                                4,
                            )"
                            :key="reason.reason"
                            class="rounded-md border px-2 py-1"
                        >
                            {{ labelize(reason.reason) }}: {{ reason.count }}
                        </span>
                    </div>
                    <div
                        v-if="payload?.bet_filter"
                        class="flex flex-wrap gap-2 text-xs text-muted-foreground"
                    >
                        <span
                            v-for="item in payload.bet_filter.risk_controls.slice(
                                0,
                                5,
                            )"
                            :key="item"
                            class="rounded-md border px-2 py-1"
                        >
                            {{ labelize(item) }}
                        </span>
                    </div>
                </CardContent>
            </Card>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                <Card v-for="group in signalGroups" :key="group.key">
                    <CardHeader class="pb-3">
                        <CardTitle class="flex items-center gap-2 text-sm">
                            <component :is="group.icon" class="h-4 w-4" />
                            {{ group.title }}
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div
                            v-for="row in group.rows.slice(0, 4)"
                            :key="`${group.key}-${row.team_id}-${row.game_id}-${row.label}`"
                            class="flex min-h-14 items-start justify-between gap-3 border-b pb-2 last:border-0 last:pb-0"
                        >
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium">
                                    {{ row.team_name || row.matchup }}
                                </div>
                                <div
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ group.detail(row) }}
                                </div>
                                <div
                                    v-if="primaryReason(row)"
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ primaryReason(row) }}
                                </div>
                            </div>
                            <div class="shrink-0 text-sm font-semibold">
                                {{ group.metric(row) ?? '-' }}
                            </div>
                        </div>
                        <p
                            v-if="group.rows.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            No current signals.
                        </p>
                    </CardContent>
                </Card>
            </div>

            <Card v-if="payload?.backend_review">
                <CardHeader class="pb-3">
                    <CardTitle class="flex items-center gap-2 text-sm">
                        <Gauge class="h-4 w-4" />
                        Backend Logic Review
                    </CardTitle>
                </CardHeader>
                <CardContent class="grid gap-4 text-sm md:grid-cols-2">
                    <div>
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
                    <div>
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
                </CardContent>
            </Card>
        </template>
    </section>
</template>
