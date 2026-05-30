<script setup lang="ts">
import {
    AlertTriangle,
    BadgeCheck,
    CircleDollarSign,
    Medal,
    ShieldCheck,
    TrendingUp,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { fetchJson } from '@/composables/useApiClient';

interface SignalRow {
    type: string;
    game_id?: number;
    team_id?: number | null;
    team_name?: string | null;
    matchup?: string;
    signal?: string;
    pick_side?: string;
    win_probability?: number;
    confidence_score?: number;
    predicted_spread?: number;
    vegas_spread?: number | null;
    predicted_total?: number;
    market_total?: number | null;
    edge_points?: number | null;
    probability_edge?: number | null;
    market_price?: number | null;
    champion_probability?: number;
    finals_probability?: number;
    playoff_make_probability?: number;
    market_edge?: {
        edge_percent_points?: number | null;
    } | null;
    classification?: string;
    score?: number;
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
    philosophy: string;
    risk_controls: string[];
}

interface OddsHealth {
    status: string;
    slate_games: number;
    moneyline_coverage: number;
    spread_coverage: number;
    total_coverage: number;
    stale_games: number;
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

interface SignalsPayload {
    season: number;
    as_of_date: string;
    slate_date?: string | null;
    backend_review: BackendReview;
    odds_health?: OddsHealth;
    bet_filter?: BetFilter;
    pass_summary?: PassSummary;
    recommended_bets: SignalRow[];
    finals: SignalRow[];
    spread: SignalRow[];
    moneyline: SignalRow[];
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

const signalGroups = computed(() => [
    {
        key: 'finals',
        title: 'Finals',
        icon: Medal,
        rows: payload.value?.finals ?? [],
        metric: (row: SignalRow) => formatPercent(row.champion_probability),
    },
    {
        key: 'spread',
        title: 'Spread',
        icon: TrendingUp,
        rows: payload.value?.spread ?? [],
        metric: (row: SignalRow) => formatPoints(row.edge_points),
    },
    {
        key: 'moneyline',
        title: 'Moneyline',
        icon: BadgeCheck,
        rows: payload.value?.moneyline ?? [],
        metric: (row: SignalRow) => formatPercent(row.win_probability),
    },
    {
        key: 'totals',
        title: 'Totals',
        icon: CircleDollarSign,
        rows: payload.value?.totals ?? [],
        metric: (row: SignalRow) => formatPoints(row.edge_points),
    },
    {
        key: 'streaks',
        title: 'Streaks',
        icon: AlertTriangle,
        rows: payload.value?.streaks ?? [],
        metric: (row: SignalRow) =>
            row.length != null ? `${row.length}x` : null,
    },
]);

function formatPercent(value?: number | null): string | null {
    if (value == null) return null;
    return `${Math.round(value * 100)}%`;
}

function formatPoints(value?: number | null): string | null {
    if (value == null) return null;
    return `${Math.abs(value).toFixed(1)} pts`;
}

function labelize(value?: string | null): string {
    if (!value) return '';
    return value.replaceAll('_', ' ');
}

function sideLabel(value?: string | null): string {
    if (!value) return '';
    return labelize(value);
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
    if (row.edge_points != null) {
        parts.push(`${Math.abs(row.edge_points).toFixed(1)} pts`);
    }

    return parts.join(' | ');
}

function coverageLabel(value?: number): string {
    return value == null ? '0%' : `${value.toFixed(1)}%`;
}

function compactGroupDetail(row: SignalRow, groupKey: string): string {
    if (groupKey === 'finals') {
        return formatPercent(row.finals_probability) ?? 'finals';
    }

    if (groupKey === 'totals') {
        return sideLabel(row.pick_side);
    }

    return row.matchup ?? sideLabel(row.pick_side);
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
            `/api/v1/nba/signals${params.toString() ? `?${params}` : ''}`,
        );
        if (!response?.data) {
            throw new Error('Failed to load NBA signals');
        }

        payload.value = response.data;
    } catch (e) {
        error.value =
            e instanceof Error ? e.message : 'Unable to load NBA signals';
    } finally {
        loading.value = false;
    }
}

onMounted(loadSignals);
</script>

<template>
    <section class="space-y-3">
        <div
            class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h2 class="text-lg font-semibold">NBA Signals</h2>
                <p class="text-sm text-muted-foreground">
                    Best bets, live edges, and market checks.
                </p>
            </div>
            <div
                v-if="payload?.slate_date"
                class="text-sm text-muted-foreground"
            >
                Slate {{ payload.slate_date }}
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
                <CardHeader class="pb-2">
                    <CardTitle
                        class="flex items-center justify-between gap-3 text-sm"
                    >
                        <span class="flex items-center gap-2">
                            <ShieldCheck class="h-4 w-4" />
                            Best Bets
                        </span>
                        <span
                            v-if="payload?.pass_summary"
                            class="text-xs font-medium text-muted-foreground"
                        >
                            {{ payload.pass_summary.passes }}/{{
                                payload.pass_summary.candidates
                            }}
                            passed
                        </span>
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div
                        v-if="bestBets.length > 0"
                        class="grid gap-2 md:grid-cols-2 xl:grid-cols-4"
                    >
                        <div
                            v-for="row in bestBets.slice(0, 4)"
                            :key="`best-bet-${row.type}-${row.game_id}-${row.pick_side}`"
                            class="rounded-md border p-3"
                        >
                            <div class="flex items-start justify-between gap-3">
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
                            <div
                                v-if="valueDetail(row)"
                                class="mt-2 text-xs text-muted-foreground"
                            >
                                {{ valueDetail(row) }}
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-sm text-muted-foreground">
                        No games passed the selective bet filter for this slate.
                    </div>
                </CardContent>
            </Card>

            <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-5">
                <Card v-for="group in signalGroups" :key="group.key">
                    <CardHeader class="pb-2">
                        <CardTitle class="flex items-center gap-2 text-sm">
                            <component :is="group.icon" class="h-4 w-4" />
                            {{ group.title }}
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-2">
                        <div
                            v-for="row in group.rows.slice(0, 2)"
                            :key="`${group.key}-${row.team_id}-${row.game_id}-${row.label}`"
                            class="flex items-start justify-between gap-3 border-b pb-2 last:border-0 last:pb-0"
                        >
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium">
                                    {{ row.team_name || row.matchup }}
                                </div>
                                <div
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ compactGroupDetail(row, group.key) }}
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

            <details class="rounded-md border bg-card text-sm">
                <summary
                    class="cursor-pointer list-none px-4 py-3 font-medium [&::-webkit-details-marker]:hidden"
                >
                    Model Details
                </summary>
                <div class="grid gap-4 border-t px-4 py-4 md:grid-cols-2">
                    <div v-if="payload?.odds_health" class="space-y-2">
                        <div class="font-medium">Odds Health</div>
                        <div
                            class="grid grid-cols-2 gap-2 text-xs text-muted-foreground"
                        >
                            <span
                                >Status:
                                {{ labelize(payload.odds_health.status) }}</span
                            >
                            <span
                                >ML:
                                {{
                                    coverageLabel(
                                        payload.odds_health.moneyline_coverage,
                                    )
                                }}</span
                            >
                            <span
                                >Spread:
                                {{
                                    coverageLabel(
                                        payload.odds_health.spread_coverage,
                                    )
                                }}</span
                            >
                            <span
                                >Totals:
                                {{
                                    coverageLabel(
                                        payload.odds_health.total_coverage,
                                    )
                                }}</span
                            >
                        </div>
                    </div>

                    <div v-if="payload?.pass_summary" class="space-y-2">
                        <div class="font-medium">Pass Rules</div>
                        <div class="flex flex-wrap gap-2">
                            <span
                                class="rounded-md border px-2 py-1 text-xs text-muted-foreground"
                            >
                                Pass rate
                                {{ payload.pass_summary.pass_rate.toFixed(1) }}%
                            </span>
                            <span
                                v-for="reason in payload.pass_summary.top_reasons.slice(
                                    0,
                                    4,
                                )"
                                :key="reason.reason"
                                class="rounded-md border px-2 py-1 text-xs text-muted-foreground"
                            >
                                {{ labelize(reason.reason) }}:
                                {{ reason.count }}
                            </span>
                        </div>
                    </div>

                    <div v-if="payload?.bet_filter" class="space-y-2">
                        <div class="font-medium">Risk Controls</div>
                        <div class="flex flex-wrap gap-2">
                            <span
                                v-for="item in payload.bet_filter.risk_controls.slice(
                                    0,
                                    5,
                                )"
                                :key="item"
                                class="rounded-md border px-2 py-1 text-xs text-muted-foreground"
                            >
                                {{ labelize(item) }}
                            </span>
                        </div>
                    </div>

                    <div v-if="payload?.backend_review" class="space-y-2">
                        <div class="font-medium">Watch Items</div>
                        <div class="flex flex-wrap gap-2">
                            <span
                                v-for="item in payload.backend_review.watch_items.slice(
                                    0,
                                    6,
                                )"
                                :key="item"
                                class="rounded-md border border-amber-300/60 px-2 py-1 text-xs text-muted-foreground"
                            >
                                {{ labelize(item) }}
                            </span>
                        </div>
                    </div>
                </div>
            </details>
        </template>
    </section>
</template>
