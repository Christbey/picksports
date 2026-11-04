<script setup lang="ts">
import {
    Activity,
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
import { computed, toRef } from 'vue';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import type { SignalRow, SignalsPayload } from '@/types/mlb-signals';
import {
    formatPercent,
    formatRuns,
    formatSignedRuns,
    formatNumber,
    formatLiveScore,
    formatAmericanOdds,
    formatProbabilityEdge,
    labelize,
    sideLabel,
    pickLabel,
    coverageLabel,
    classificationClass,
    scoreBarClass,
} from '@/lib/mlbSignalPresentation';
import MlbSignalLiveGames from './MlbSignalLiveGames.vue';
import MlbSignalRecommendations from './MlbSignalRecommendations.vue';
import MlbSignalDiagnostics from './MlbSignalDiagnostics.vue';
const props = defineProps<{ payload: SignalsPayload | null }>();
const payload = toRef(props, 'payload');
const bestBets = computed(() => payload.value?.recommended_bets ?? []);
const shadowBets = computed(() => payload.value?.shadow_recommended_bets ?? []);
const liveRows = computed(() => payload.value?.live ?? []);
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

const promotionProtected = computed(
    () => payload.value?.bet_filter?.promotion?.status === 'blocked',
);

const topSignalRows = computed(() =>
    promotionProtected.value ? shadowBets.value : bestBets.value,
);

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
        key: 'live',
        title: 'Live Monitor',
        icon: Activity,
        rows: payload.value?.live ?? [],
        metric: (row: SignalRow) => formatPercent(row.live_win_probability),
        detail: (row: SignalRow) =>
            `${formatLiveScore(row)} | ${labelize(row.signal)}${row.is_stale ? ' | stale' : ''}`,
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
        label: promotionProtected.value ? 'Research' : 'Validated',
        count: promotionProtected.value
            ? shadowBets.value.length
            : bestBets.value.length,
        detail: promotionProtected.value ? 'tracking only' : 'passed filter',
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
        key: 'live',
        label: 'Live',
        count: payload.value?.live?.length ?? 0,
        detail: 'monitor',
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

const topBet = computed(() => topSignalRows.value[0] ?? null);

function widthFromCount(count: number): string {
    if (count <= 0) return '0%';
    return `${Math.max(4, Math.round((count / maxSignalMixCount.value) * 100))}%`;
}
</script>

<template>
    <Card class="overflow-hidden">
        <CardContent class="space-y-4 p-4 md:p-5">
            <div
                class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between"
            >
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <CardTitle class="flex items-center gap-2 text-base">
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
                        Betting signals for moneyline value, market coverage,
                        run environment, and model risk.
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
                        {{ bestBets.length }} public picks
                    </span>
                    <span
                        v-if="promotionProtected && shadowBets.length > 0"
                        class="rounded-full border px-2.5 py-1"
                    >
                        {{ shadowBets.length }} research
                    </span>
                    <span
                        v-if="liveRows.length > 0"
                        class="rounded-full border px-2.5 py-1"
                    >
                        {{ liveRows.length }} live
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
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <Target class="h-4 w-4 text-emerald-600" />
                            <div class="text-sm font-semibold">
                                {{
                                    promotionProtected
                                        ? 'Research Signal'
                                        : 'Top Validated Signal'
                                }}
                            </div>
                        </div>
                        <span
                            v-if="topBet"
                            class="rounded-full border px-2.5 py-1 text-xs font-semibold uppercase"
                            :class="classificationClass(topBet.classification)"
                        >
                            {{
                                promotionProtected
                                    ? 'tracking only'
                                    : topBet.classification
                            }}
                        </span>
                    </div>
                    <div v-if="topBet" class="space-y-2">
                        <div
                            class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div class="min-w-0">
                                <div class="text-base font-semibold">
                                    {{ topBet.team_name || topBet.matchup }}
                                </div>
                                <div class="text-sm text-muted-foreground">
                                    {{ pickLabel(topBet) }} |
                                    {{ topBet.matchup }}
                                </div>
                            </div>
                            <div class="shrink-0 text-left sm:text-right">
                                <div class="text-xl font-bold">
                                    {{ topBet.score ?? 0 }}
                                </div>
                                <div class="text-xs text-muted-foreground">
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
                                <span class="font-medium text-foreground">
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
                                <span class="font-medium text-foreground">
                                    Odds
                                </span>
                                {{ formatAmericanOdds(topBet.market_price) }}
                            </div>
                            <div>
                                <span class="font-medium text-foreground">
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
                        No public MLB bets are promotion-ready for the current
                        slate.
                    </div>
                </div>

                <div class="rounded-lg border border-border/70 bg-muted/20 p-3">
                    <div class="mb-3 flex items-center gap-2">
                        <BarChart3 class="h-4 w-4" />
                        <div class="text-sm font-semibold">Signal Mix</div>
                    </div>
                    <div class="space-y-2">
                        <div v-for="item in bettingSignalMix" :key="item.key">
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
                                        width: widthFromCount(item.count),
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
                    <div class="font-medium text-foreground">Priced</div>
                    <div>
                        {{ moneylineReadiness.priced_count }} /
                        {{ moneylineReadiness.candidate_count }}
                        ({{ moneylineReadiness.priced_rate.toFixed(1) }}%)
                    </div>
                </div>
                <div>
                    <div class="font-medium text-foreground">Usable ML</div>
                    <div>
                        {{ moneylineReadiness.usable_count }} bets/leans
                        <span v-if="promotionProtected">
                            tracked,
                            {{ moneylineReadiness.public_usable_count ?? 0 }}
                            public
                        </span>
                    </div>
                </div>
                <div>
                    <div class="font-medium text-foreground">Market Edge</div>
                    <div>
                        {{ moneylineReadiness.positive_market_edge_count }}
                        positive
                    </div>
                </div>
                <div>
                    <div class="font-medium text-foreground">Passes</div>
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
                    {{ coverageLabel(payload.odds_health.moneyline_coverage) }}
                </span>
                <span
                    v-if="payload?.odds_health"
                    class="rounded-md border px-2 py-1"
                >
                    Totals
                    {{ coverageLabel(payload.odds_health.total_coverage) }}
                </span>
                <span
                    v-if="payload?.pass_summary"
                    class="rounded-md border px-2 py-1"
                >
                    Pass rate
                    {{ payload.pass_summary.pass_rate.toFixed(1) }}%
                </span>
            </div>

            <MlbSignalLiveGames :live-rows="liveRows" />

            <MlbSignalRecommendations :best-bets="bestBets" />

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
                                <component :is="group.icon" class="h-4 w-4" />
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
                                    <div class="truncate text-sm font-medium">
                                        {{ row.team_name || row.matchup }}
                                    </div>
                                    <div
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ group.detail(row) }}
                                    </div>
                                </div>
                                <div class="shrink-0 text-sm font-semibold">
                                    {{ group.metric(row) ?? '-' }}
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </details>

            <MlbSignalDiagnostics :payload="payload" />
        </CardContent>
    </Card>
</template>
