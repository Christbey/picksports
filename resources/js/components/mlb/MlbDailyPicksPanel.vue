<script setup lang="ts">
import {
    Activity,
    AlertTriangle,
    BarChart3,
    CalendarDays,
    CheckCircle2,
    CircleDot,
    Clock,
    Gauge,
    RefreshCw,
    ShieldCheck,
    Sparkles,
    Target,
    Trophy,
    Zap,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import MlbPickCard from '@/components/mlb/MlbPickCard.vue';
import MlbPickDetailDrawer from '@/components/mlb/MlbPickDetailDrawer.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { safeMlbBoardMode } from '@/lib/mlbRecommendationLabels';
import type {
    MlbDailyPerformanceRecord,
    MlbDailyPick,
    MlbDailyPicksPayload,
} from '@/types/mlb-daily-picks';

const props = defineProps<{
    season?: string | number | null;
}>();

const api = useApiV2Client();
const loading = ref(true);
const error = ref<string | null>(null);
const payload = ref<MlbDailyPicksPayload['data'] | null>(null);
const selectedDate = ref('');
const selectedTab = ref('all');
const selectedPick = ref<MlbDailyPick | null>(null);
const detailOpen = ref(false);

const allCandidates = computed(() => payload.value?.candidates ?? []);
const summary = computed(() => payload.value?.summary ?? null);
const boardHealth = computed(() => payload.value?.board_health ?? null);
const marketCounts = computed(() => payload.value?.market_counts ?? {});

const marketTabs = computed(() =>
    [
        { key: 'all', label: 'All', count: marketCounts.value.all ?? 0 },
        {
            key: 'moneyline',
            label: 'Moneyline',
            count: marketCounts.value.moneyline ?? 0,
        },
        {
            key: 'run_line',
            label: 'Run Line',
            count: marketCounts.value.run_line ?? 0,
        },
        { key: 'total', label: 'Totals', count: marketCounts.value.total ?? 0 },
        { key: 'first_5', label: 'F5', count: marketCounts.value.first_5 ?? 0 },
        { key: 'first_3', label: 'F3', count: marketCounts.value.first_3 ?? 0 },
        {
            key: 'player_prop',
            label: 'Props',
            count: marketCounts.value.player_prop ?? 0,
        },
        {
            key: 'tracking',
            label: 'Watchlist',
            count: marketCounts.value.tracking ?? 0,
        },
    ].filter((tab) => tab.key === 'all' || tab.count > 0),
);

const filteredCandidates = computed(() => {
    const tab = selectedTab.value;
    if (tab === 'all') return allCandidates.value;
    if (tab === 'tracking') {
        return allCandidates.value.filter((pick) => pick.is_tracking_only);
    }
    if (tab === 'first_5') {
        return allCandidates.value.filter((pick) =>
            pick.market_type.startsWith('first_5'),
        );
    }
    if (tab === 'first_3') {
        return allCandidates.value.filter((pick) =>
            pick.market_type.startsWith('first_3'),
        );
    }

    return allCandidates.value.filter((pick) => pick.market_type === tab);
});

const visibleCandidates = computed(() =>
    bestCandidatePerGame(filteredCandidates.value).slice(0, 12),
);

const hiddenCandidateCount = computed(() =>
    Math.max(
        filteredCandidates.value.length - visibleCandidates.value.length,
        0,
    ),
);

const statusStrip = computed(() => [
    {
        label: 'Games',
        value: summary.value?.slate_games ?? 0,
        tone: 'text-sky-400',
    },
    {
        label: 'Priced',
        value: summary.value?.priced_games ?? 0,
        tone:
            (summary.value?.priced_games ?? 0) > 0
                ? 'text-emerald-400'
                : 'text-amber-400',
    },
    {
        label: 'Candidates',
        value: summary.value?.candidate_count ?? 0,
        tone:
            (summary.value?.candidate_count ?? 0) > 0
                ? 'text-emerald-400'
                : 'text-muted-foreground',
    },
    {
        label: 'Top Picks',
        value: summary.value?.top_candidate_count ?? 0,
        tone:
            (summary.value?.top_candidate_count ?? 0) > 0
                ? 'text-emerald-400'
                : 'text-muted-foreground',
    },
]);

const healthRows = computed(() => [
    {
        icon: BarChart3,
        label: 'Slate Coverage',
        value: coverageDisplay.value,
        detail: coverageDetail.value,
        percent: boardHealth.value?.slate_coverage ?? null,
        tone:
            (boardHealth.value?.slate_coverage ?? 0) > 0 ? 'emerald' : 'amber',
    },
    {
        icon: ShieldCheck,
        label: 'Pregame Safety',
        value: formatPercent(boardHealth.value?.pregame_safe_rate),
        detail:
            boardHealth.value?.pregame_safe_rate == null
                ? 'Waiting for scan'
                : 'Safety checks',
        percent: boardHealth.value?.pregame_safe_rate ?? null,
        tone: 'emerald',
    },
    {
        icon: Target,
        label: 'Market Agreement',
        value: formatPercent(boardHealth.value?.market_agreement_rate),
        detail:
            boardHealth.value?.market_agreement_rate == null
                ? 'No candidates yet'
                : 'Model-market context',
        percent: boardHealth.value?.market_agreement_rate ?? null,
        tone: 'sky',
    },
    {
        icon: Gauge,
        label: 'Board Signal',
        value:
            boardHealth.value?.score != null
                ? String(boardHealth.value.score)
                : 'Pending',
        detail:
            boardHealth.value?.score == null
                ? 'Run pick engine'
                : 'Average top score',
        percent:
            boardHealth.value?.score != null
                ? boardHealth.value.score / 100
                : null,
        tone: 'emerald',
    },
]);

const coverageDisplay = computed(() => {
    const slateGames = summary.value?.slate_games ?? 0;
    const pricedGames = summary.value?.priced_games ?? 0;
    if (slateGames === 0) return 'No slate';

    return `${pricedGames}/${slateGames} priced`;
});

const coverageDetail = computed(() => {
    const pricedGames = summary.value?.priced_games ?? 0;
    if (pricedGames === 0) return 'Needs odds';

    return 'Pricing available';
});

const emptyState = computed(() => {
    const slateGames = summary.value?.slate_games ?? 0;
    const pricedGames = summary.value?.priced_games ?? 0;
    const candidateCount = summary.value?.candidate_count ?? 0;

    if (slateGames === 0) {
        return {
            title: 'No MLB slate found.',
            body: 'Select another date to review the daily board.',
            icon: CalendarDays,
        };
    }

    if (pricedGames === 0) {
        return {
            title: 'Market odds are not available yet.',
            body: 'Candidates will populate once pricing is synced for moneyline, totals, run line, F5, F3, and props.',
            icon: AlertTriangle,
        };
    }

    if (candidateCount === 0) {
        return {
            title: 'Daily board has not been scanned yet.',
            body: "Run today's pick engine to generate tracking candidates across sides, totals, F5/F3, and props.",
            icon: Zap,
        };
    }

    return {
        title: "No candidates cleared today's threshold.",
        body: 'The system did not force weak picks into the board.',
        icon: ShieldCheck,
    };
});

function formatDate(value?: string | null): string {
    if (!value) return 'Today';

    return new Intl.DateTimeFormat(undefined, {
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(`${value}T12:00:00`));
}

function formatPercent(value?: number | null): string {
    if (value == null) return 'Pending';

    return `${Math.round(value * 100)}%`;
}

function formatRecord(record?: MlbDailyPerformanceRecord | null): string {
    if (!record) return 'Collecting';

    return `${record.wins}-${record.losses}-${record.pushes}`;
}

function formatHitRate(record?: MlbDailyPerformanceRecord | null): string {
    if (!record?.hit_rate) return 'Unlocking';

    return `${(record.hit_rate * 100).toFixed(1)}%`;
}

function achievementIcon(key: string) {
    if (key === 'clean_slate') return ShieldCheck;
    if (key === 'consensus_board') return CheckCircle2;
    if (key === 'high_signal_day') return Trophy;
    if (key === 'no_force_picks') return Gauge;

    return Sparkles;
}

function safeTrackingCopy(value?: string | null): string {
    if (!value) return '';

    return value
        .replace(/\bpublic betting validation\b/gi, 'public validation')
        .replace(/\bbetting validation\b/gi, 'validation')
        .replace(/\bbetting\b/gi, 'pick tracking')
        .replace(/\bbet\b/gi, 'pick');
}

function healthBarClass(tone: string): string {
    if (tone === 'sky') return 'bg-sky-500';
    if (tone === 'amber') return 'bg-amber-500';

    return 'bg-emerald-500';
}

function bestCandidatePerGame(candidates: MlbDailyPick[]): MlbDailyPick[] {
    const bestByGame = new Map<number, MlbDailyPick>();

    for (const candidate of candidates) {
        const current = bestByGame.get(candidate.game_id);

        if (!current || candidate.score > current.score) {
            bestByGame.set(candidate.game_id, candidate);
        }
    }

    return Array.from(bestByGame.values()).sort((a, b) => {
        if (b.score !== a.score) return b.score - a.score;

        return String(a.game?.game_date ?? '').localeCompare(
            String(b.game?.game_date ?? ''),
        );
    });
}

function selectPick(candidate: MlbDailyPick): void {
    selectedPick.value = candidate;
    detailOpen.value = true;
}

async function loadPicks(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const query: Record<string, string | number> = {};
        if (props.season) query.season = props.season;
        if (selectedDate.value) query.date = selectedDate.value;

        const response = await api.dailyPicks.index<MlbDailyPicksPayload>(
            'mlb',
            { query },
        );

        if (!response?.data) {
            throw new Error('Failed to load MLB daily board');
        }

        payload.value = response.data;
        if (!selectedDate.value) {
            selectedDate.value = response.data.date;
        }
    } catch (e) {
        error.value =
            e instanceof Error ? e.message : 'Unable to load MLB daily board';
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    void loadPicks();
});
</script>

<template>
    <section class="space-y-5">
        <div v-if="loading" class="space-y-4">
            <Skeleton class="h-28 w-full rounded-lg" />
            <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_320px]">
                <Skeleton class="h-80 w-full rounded-lg" />
                <Skeleton class="h-80 w-full rounded-lg" />
            </div>
        </div>

        <div
            v-else-if="error"
            class="rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive"
        >
            {{ error }}
        </div>

        <template v-else>
            <header
                class="relative overflow-hidden rounded-lg border bg-card/95 p-4 shadow-sm md:p-5"
            >
                <div
                    class="pointer-events-none absolute inset-y-0 right-0 hidden w-64 opacity-20 md:block"
                >
                    <div
                        class="absolute top-1/2 right-10 h-52 w-52 -translate-y-1/2 rotate-45 rounded-[2rem] border border-emerald-500/50"
                    />
                    <div
                        class="absolute top-1/2 right-24 h-px w-56 -translate-y-1/2 bg-emerald-500/40"
                    />
                </div>

                <div
                    class="relative flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="inline-flex items-center gap-2 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300"
                            >
                                <ShieldCheck class="h-3.5 w-3.5" />
                                {{ safeMlbBoardMode(payload?.mode) }}
                            </span>
                            <span
                                class="rounded-full border border-sky-500/20 bg-sky-500/10 px-2.5 py-1 text-xs font-medium text-sky-700 dark:text-sky-300"
                            >
                                Market-Aware
                            </span>
                            <span
                                class="rounded-full border border-amber-500/20 bg-amber-500/10 px-2.5 py-1 text-xs font-medium text-amber-700 dark:text-amber-300"
                            >
                                Needs Validation
                            </span>
                        </div>

                        <div
                            class="mt-3 flex flex-wrap items-end gap-x-4 gap-y-1"
                        >
                            <h1
                                class="text-3xl font-bold tracking-normal md:text-4xl"
                            >
                                MLB Daily Board
                            </h1>
                            <div
                                class="pb-1 text-sm font-medium text-muted-foreground"
                            >
                                {{ formatDate(payload?.date) }}
                            </div>
                        </div>
                        <p
                            class="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground"
                        >
                            Market-aware MLB candidates, model context, and
                            tracking performance.
                        </p>
                    </div>

                    <div
                        class="flex w-full flex-col gap-2 sm:flex-row lg:w-auto"
                    >
                        <div
                            class="relative min-w-0 flex-1 lg:w-48 lg:flex-none"
                        >
                            <CalendarDays
                                class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <input
                                v-model="selectedDate"
                                type="date"
                                class="h-10 w-full rounded-lg border bg-background pr-3 pl-9 text-sm"
                            />
                        </div>
                        <button
                            type="button"
                            class="inline-flex h-10 items-center justify-center gap-2 rounded-lg border bg-background px-4 text-sm font-semibold transition hover:bg-muted"
                            @click="loadPicks"
                        >
                            <RefreshCw class="h-4 w-4" />
                            Load
                        </button>
                    </div>
                </div>

                <div class="relative mt-4 grid gap-2 sm:grid-cols-4">
                    <div
                        v-for="item in statusStrip"
                        :key="item.label"
                        class="rounded-lg border bg-background/70 px-3 py-2"
                    >
                        <div
                            class="text-[11px] font-semibold text-muted-foreground uppercase"
                        >
                            {{ item.label }}
                        </div>
                        <div
                            class="mt-0.5 text-2xl font-bold"
                            :class="item.tone"
                        >
                            {{ item.value }}
                        </div>
                    </div>
                </div>
            </header>

            <section
                class="grid gap-5 md:grid-cols-[minmax(0,1fr)_300px] 2xl:grid-cols-[minmax(0,1fr)_340px]"
            >
                <div class="space-y-5">
                    <section
                        class="rounded-lg border bg-card p-4 shadow-sm md:p-5"
                    >
                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div>
                                <div
                                    class="flex items-center gap-2 text-sm font-semibold text-emerald-600 uppercase dark:text-emerald-400"
                                >
                                    <Target class="h-4 w-4" />
                                    Daily Candidates
                                </div>
                                <h2
                                    class="mt-1 text-2xl font-bold tracking-normal"
                                >
                                    Best Available Per Game
                                </h2>
                            </div>
                            <div
                                class="rounded-full border border-emerald-500/25 bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300"
                            >
                                {{ visibleCandidates.length }} shown
                            </div>
                        </div>

                        <div
                            v-if="filteredCandidates.length === 0"
                            class="mt-4 rounded-lg border border-dashed bg-background/70 p-5"
                        >
                            <div class="flex min-w-0 gap-4">
                                <div
                                    class="grid h-12 w-12 shrink-0 place-items-center rounded-lg border bg-card text-amber-500"
                                >
                                    <component
                                        :is="emptyState.icon"
                                        class="h-5 w-5"
                                    />
                                </div>
                                <div class="min-w-0">
                                    <h3 class="text-lg font-semibold">
                                        {{ emptyState.title }}
                                    </h3>
                                    <p
                                        class="mt-1 max-w-2xl text-sm leading-6 text-muted-foreground"
                                    >
                                        {{ emptyState.body }}
                                    </p>
                                    <div
                                        class="mt-3 inline-flex items-center gap-2 rounded-full border border-amber-500/25 bg-amber-500/10 px-3 py-1 text-xs font-semibold text-amber-700 dark:text-amber-300"
                                    >
                                        <Clock class="h-3.5 w-3.5" />
                                        Tracking mode is active
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-else class="mt-4 space-y-4">
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="tab in marketTabs"
                                    :key="tab.key"
                                    type="button"
                                    class="rounded-full border px-3.5 py-2 text-sm font-semibold whitespace-nowrap transition"
                                    :class="
                                        selectedTab === tab.key
                                            ? 'border-emerald-500 bg-emerald-500 text-white shadow-sm'
                                            : 'bg-card text-muted-foreground hover:border-emerald-500/30 hover:bg-muted'
                                    "
                                    @click="selectedTab = tab.key"
                                >
                                    {{ tab.label }}
                                    <span class="ml-1 opacity-75">{{
                                        tab.count
                                    }}</span>
                                </button>
                            </div>

                            <div
                                class="rounded-lg border bg-background/70 p-3 text-xs leading-5 text-muted-foreground"
                            >
                                Showing the highest-scored candidate per game
                                for this filter. Open details for full model,
                                market, reasons, and risks.
                            </div>

                            <div class="grid gap-3">
                                <MlbPickCard
                                    v-for="candidate in visibleCandidates"
                                    :key="candidate.id"
                                    :candidate="candidate"
                                    variant="compact"
                                    @select="selectPick"
                                />
                            </div>

                            <div
                                v-if="hiddenCandidateCount > 0"
                                class="rounded-lg border border-dashed bg-background/70 p-3 text-xs text-muted-foreground"
                            >
                                {{ hiddenCandidateCount }} lower-ranked
                                candidate(s) are hidden to keep this board
                                readable.
                            </div>
                        </div>
                    </section>
                </div>

                <aside class="space-y-4">
                    <section class="rounded-lg border bg-card p-4 shadow-sm">
                        <div
                            class="mb-3 flex items-center justify-between gap-3"
                        >
                            <div class="flex items-center gap-2 font-semibold">
                                <Gauge class="h-4 w-4 text-emerald-500" />
                                Board Health
                            </div>
                            <span
                                class="rounded-full border px-2.5 py-1 text-xs font-semibold text-muted-foreground"
                            >
                                {{
                                    boardHealth?.status?.replaceAll('_', ' ') ??
                                    'pending'
                                }}
                            </span>
                        </div>

                        <div class="space-y-3">
                            <div
                                v-for="item in healthRows"
                                :key="item.label"
                                class="rounded-lg border bg-background/70 p-3"
                            >
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <div class="flex gap-2">
                                        <component
                                            :is="item.icon"
                                            class="mt-0.5 h-4 w-4 text-muted-foreground"
                                        />
                                        <div>
                                            <div class="text-sm font-semibold">
                                                {{ item.label }}
                                            </div>
                                            <div
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{ item.detail }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right text-sm font-bold">
                                        {{ item.value }}
                                    </div>
                                </div>
                                <div
                                    v-if="item.percent && item.percent > 0"
                                    class="mt-3 h-1.5 rounded-full bg-muted"
                                >
                                    <div
                                        class="h-full rounded-full"
                                        :class="healthBarClass(item.tone)"
                                        :style="{
                                            width: `${Math.min(item.percent * 100, 100)}%`,
                                        }"
                                    />
                                </div>
                            </div>
                        </div>

                        <p class="mt-3 text-xs leading-5 text-muted-foreground">
                            {{
                                boardHealth?.message ??
                                'Board health will populate after the daily pick scan runs.'
                            }}
                        </p>
                    </section>

                    <section class="rounded-lg border bg-card p-4 shadow-sm">
                        <div class="mb-3 flex items-center gap-2 font-semibold">
                            <Activity class="h-4 w-4 text-sky-500" />
                            Tracking Performance
                        </div>
                        <div class="space-y-3 text-sm">
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <span class="text-muted-foreground"
                                    >Top Picks</span
                                >
                                <span
                                    class="rounded-full border px-2 py-0.5 text-xs font-semibold text-muted-foreground"
                                >
                                    Tracking
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-muted-foreground"
                                    >Last 7 days</span
                                >
                                <span class="font-semibold">{{
                                    formatRecord(
                                        payload?.performance_summary
                                            .last_7_days,
                                    )
                                }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-muted-foreground"
                                    >Last 30 days</span
                                >
                                <span class="font-semibold">{{
                                    formatRecord(
                                        payload?.performance_summary
                                            .last_30_days,
                                    )
                                }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-muted-foreground"
                                    >Hit rate</span
                                >
                                <span class="font-semibold">{{
                                    formatHitRate(
                                        payload?.performance_summary
                                            .last_30_days,
                                    )
                                }}</span>
                            </div>
                        </div>
                        <div
                            v-if="payload?.performance_summary.sample_warning"
                            class="mt-4 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs leading-5 text-amber-700 dark:text-amber-300"
                        >
                            Small sample.
                            {{
                                safeTrackingCopy(
                                    payload.performance_summary.sample_warning,
                                )
                            }}
                        </div>
                        <p class="mt-3 text-xs leading-5 text-muted-foreground">
                            {{
                                safeTrackingCopy(
                                    payload?.performance_summary.mode_note,
                                )
                            }}
                        </p>
                    </section>

                    <section class="rounded-lg border bg-card p-4 shadow-sm">
                        <div class="mb-3 flex items-center gap-2 font-semibold">
                            <Sparkles class="h-4 w-4 text-emerald-500" />
                            Board Signals
                        </div>
                        <div
                            v-if="(payload?.achievements ?? []).length > 0"
                            class="space-y-2"
                        >
                            <div
                                v-for="achievement in payload?.achievements ??
                                []"
                                :key="achievement.key"
                                class="flex gap-3 rounded-lg border bg-background/70 p-3"
                            >
                                <component
                                    :is="achievementIcon(achievement.key)"
                                    class="mt-0.5 h-4 w-4 shrink-0 text-emerald-500"
                                />
                                <div>
                                    <div class="text-sm font-semibold">
                                        {{ achievement.label }}
                                    </div>
                                    <div
                                        class="text-xs leading-5 text-muted-foreground"
                                    >
                                        {{ achievement.description }}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div
                            v-else
                            class="rounded-lg border border-dashed p-3 text-sm text-muted-foreground"
                        >
                            Achievements unlock as the board scans and
                            candidates qualify.
                        </div>
                    </section>

                    <section class="rounded-lg border bg-card p-4 shadow-sm">
                        <div class="mb-2 flex items-center gap-2 font-semibold">
                            <CircleDot class="h-4 w-4 text-amber-500" />
                            Mode
                        </div>
                        <p class="text-sm leading-6 text-muted-foreground">
                            Tracking mode is active. Candidates are evaluated
                            before public pick labels are enabled.
                        </p>
                    </section>
                </aside>
            </section>

            <MlbPickDetailDrawer
                v-model:open="detailOpen"
                :candidate="selectedPick"
            />
        </template>
    </section>
</template>
