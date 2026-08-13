<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    BarChart3,
    CheckCircle2,
    CircleDollarSign,
    LayoutDashboard,
    LogIn,
    Scale,
    ShieldCheck,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    dashboard,
    login,
    register,
    performance as performanceRoute,
    terms,
    privacy,
    responsibleGambling,
} from '@/routes';

interface OverallStats {
    total_predictions: number;
    winner_accuracy: number;
    avg_spread_error: number | null;
    avg_total_error: number | null;
    win_record: string;
}

interface ROIStats {
    total_bets: number;
    total_wins: number;
    total_losses: number;
    total_wagered: number;
    total_profit: number;
    roi_percentage: number | null;
    win_percentage: number | null;
}

interface PerformanceData {
    overall: OverallStats;
    recent: {
        overall: OverallStats;
        roi: ROIStats;
    };
    roi: ROIStats;
}

const props = defineProps<{
    canRegister: boolean;
    performance: PerformanceData;
}>();

const sports = [
    {
        slug: 'mlb',
        label: 'MLB',
        detail: 'Games, F3/F5, markets',
        accent: 'bg-emerald-500',
    },
    {
        slug: 'nfl',
        label: 'NFL',
        detail: 'Matchups, injuries, futures',
        accent: 'bg-amber-400',
    },
    {
        slug: 'nba',
        label: 'NBA',
        detail: 'Games, props, futures',
        accent: 'bg-sky-500',
    },
    {
        slug: 'wnba',
        label: 'WNBA',
        detail: 'Games, injuries, props',
        accent: 'bg-rose-500',
    },
    {
        slug: 'cfb',
        label: 'CFB',
        detail: 'Games, teams, futures',
        accent: 'bg-orange-500',
    },
    {
        slug: 'cbb',
        label: 'CBB',
        detail: 'Games, teams, tournament',
        accent: 'bg-indigo-500',
    },
    {
        slug: 'wcbb',
        label: 'WCBB',
        detail: 'Games, teams, tournament',
        accent: 'bg-teal-500',
    },
] as const;

const decisionSteps = [
    {
        label: 'Forecast',
        detail: 'A pregame probability with model lineage.',
        icon: BarChart3,
    },
    {
        label: 'Price',
        detail: 'The model is compared with the available market.',
        icon: CircleDollarSign,
    },
    {
        label: 'Risk',
        detail: 'Availability, uncertainty, and data quality are checked.',
        icon: ShieldCheck,
    },
    {
        label: 'Decision',
        detail: 'Bet or no bet, with the reason recorded.',
        icon: Scale,
    },
] as const;

const overall = computed(() => props.performance.overall);
const recent = computed(() => props.performance.recent.overall);
const roi = computed(() => props.performance.roi);
const hasOverallSample = computed(() => overall.value.total_predictions > 0);
const hasRecentSample = computed(() => recent.value.total_predictions > 0);
const hasRoiSample = computed(() => roi.value.total_bets > 0);

const formatPercent = (value: number, hasSample: boolean) =>
    hasSample ? `${value.toFixed(1)}%` : 'Pending';

const formatSignedPercent = (value: number | null, hasSample: boolean) => {
    if (!hasSample || value === null) return 'Pending';

    return `${value > 0 ? '+' : ''}${value.toFixed(2)}%`;
};
</script>

<template>
    <Head title="PickSports - Model-Based Sports Decisions">
        <meta
            head-key="description"
            name="description"
            content="PickSports turns pregame models, market prices, and risk checks into transparent sports betting decisions."
        />
        <meta
            head-key="og:title"
            property="og:title"
            content="PickSports - Model-Based Sports Decisions"
        />
        <meta
            head-key="og:description"
            property="og:description"
            content="Pregame probabilities, market-aware decisions, and a fully graded public record across seven sports."
        />
        <meta
            head-key="twitter:title"
            name="twitter:title"
            content="PickSports - Model-Based Sports Decisions"
        />
        <meta
            head-key="twitter:description"
            name="twitter:description"
            content="Pregame probabilities, market-aware decisions, and a fully graded public record across seven sports."
        />
    </Head>

    <div
        class="min-h-screen bg-[#f4f6f8] text-[#151a21] dark:bg-[#0b0e12] dark:text-[#f4f6f8]"
    >
        <nav
            class="sticky top-0 z-50 border-b border-black/10 bg-[#f4f6f8]/95 dark:border-white/10 dark:bg-[#0b0e12]/95"
            aria-label="Primary navigation"
        >
            <div
                class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8"
            >
                <Link
                    href="/"
                    class="flex items-center gap-3"
                    aria-label="PickSports home"
                >
                    <span
                        class="flex size-9 items-center justify-center rounded-md bg-[#151a21] text-sm font-bold text-white dark:bg-white dark:text-[#151a21]"
                    >
                        PS
                    </span>
                    <span
                        class="hidden text-lg font-semibold tracking-normal sm:inline"
                        >PickSports</span
                    >
                </Link>

                <div class="flex items-center gap-1 sm:gap-3">
                    <Link
                        :href="performanceRoute()"
                        class="hidden px-3 py-2 text-sm font-medium text-[#59616d] transition hover:text-[#151a21] sm:block dark:text-[#aab2bf] dark:hover:text-white"
                    >
                        Performance
                    </Link>

                    <template v-if="$page.props.auth.user">
                        <Link :href="dashboard()">
                            <Button size="sm" class="gap-2 rounded-md">
                                <LayoutDashboard class="size-4" />
                                Dashboard
                            </Button>
                        </Link>
                    </template>
                    <template v-else>
                        <Link :href="login()">
                            <Button
                                variant="ghost"
                                size="sm"
                                class="gap-2 rounded-md"
                            >
                                <LogIn class="size-4" />
                                Log in
                            </Button>
                        </Link>
                        <Link v-if="canRegister" :href="register()">
                            <Button size="sm" class="rounded-md"
                                >Create account</Button
                            >
                        </Link>
                    </template>
                </div>
            </div>
        </nav>

        <main>
            <section
                class="relative isolate overflow-hidden border-b border-white/10 bg-[#0c1118] text-white"
            >
                <div
                    class="absolute inset-x-0 top-0 flex h-1"
                    aria-hidden="true"
                >
                    <span class="w-[42%] bg-[#7ee2b8]" />
                    <span class="w-[33%] bg-[#65b9f3]" />
                    <span class="flex-1 bg-[#ffb45f]" />
                </div>

                <div
                    class="relative mx-auto grid min-h-[610px] max-w-7xl lg:min-h-[650px] lg:grid-cols-[1.02fr_0.98fr]"
                >
                    <div
                        class="flex flex-col justify-center px-4 py-12 sm:px-6 sm:py-14 lg:px-8 lg:py-16 lg:pr-14"
                    >
                        <div
                            class="mb-6 flex items-center gap-3 text-xs font-semibold text-[#7ee2b8] uppercase"
                        >
                            <span class="h-px w-8 bg-[#7ee2b8]" />
                            Model + market intelligence
                        </div>
                        <h1
                            class="text-5xl leading-none font-bold tracking-normal sm:text-6xl xl:text-7xl"
                        >
                            PickSports
                        </h1>
                        <p
                            class="mt-5 max-w-xl text-2xl leading-8 font-medium text-white sm:text-3xl sm:leading-10"
                        >
                            Know the probability. Know the price. Know when to
                            pass.
                        </p>
                        <p
                            class="mt-4 max-w-xl text-base leading-7 text-[#aeb8c5] sm:text-lg"
                        >
                            Pregame models become accountable decisions only
                            after market value, uncertainty, and risk are
                            measured.
                        </p>

                        <div class="mt-7 flex flex-wrap gap-3">
                            <Link
                                v-if="$page.props.auth.user"
                                :href="dashboard()"
                            >
                                <Button
                                    size="lg"
                                    class="gap-2 rounded-md bg-[#7ee2b8] text-[#07110d] hover:bg-[#9aebca]"
                                >
                                    Open dashboard
                                    <ArrowRight class="size-4" />
                                </Button>
                            </Link>
                            <Link v-else-if="canRegister" :href="register()">
                                <Button
                                    size="lg"
                                    class="gap-2 rounded-md bg-[#7ee2b8] text-[#07110d] hover:bg-[#9aebca]"
                                >
                                    Create account
                                    <ArrowRight class="size-4" />
                                </Button>
                            </Link>
                            <Link v-else :href="login()">
                                <Button
                                    size="lg"
                                    class="gap-2 rounded-md bg-[#7ee2b8] text-[#07110d] hover:bg-[#9aebca]"
                                >
                                    Log in
                                    <ArrowRight class="size-4" />
                                </Button>
                            </Link>
                            <Link :href="performanceRoute()">
                                <Button
                                    size="lg"
                                    variant="outline"
                                    class="rounded-md border-white/25 bg-transparent text-white hover:bg-white/10 hover:text-white"
                                >
                                    View verified performance
                                </Button>
                            </Link>
                        </div>

                        <div
                            class="mt-9 grid grid-cols-3 border-y border-white/15"
                        >
                            <div class="py-4 pr-3 sm:py-5">
                                <div
                                    class="text-2xl font-semibold text-white sm:text-3xl"
                                >
                                    {{
                                        formatPercent(
                                            overall.winner_accuracy,
                                            hasOverallSample,
                                        )
                                    }}
                                </div>
                                <div
                                    class="mt-1 text-[10px] font-semibold text-[#8993a2] uppercase sm:text-xs"
                                >
                                    Accuracy
                                </div>
                            </div>
                            <div
                                class="border-x border-white/15 px-3 py-4 sm:px-5 sm:py-5"
                            >
                                <div
                                    class="text-2xl font-semibold text-white sm:text-3xl"
                                >
                                    {{
                                        overall.total_predictions.toLocaleString()
                                    }}
                                </div>
                                <div
                                    class="mt-1 text-[10px] font-semibold text-[#8993a2] uppercase sm:text-xs"
                                >
                                    Graded
                                </div>
                            </div>
                            <div class="py-4 pl-3 sm:py-5 sm:pl-5">
                                <div
                                    class="text-2xl font-semibold text-white sm:text-3xl"
                                >
                                    7
                                </div>
                                <div
                                    class="mt-1 text-[10px] font-semibold text-[#8993a2] uppercase sm:text-xs"
                                >
                                    Sports
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        class="relative hidden border-l border-white/10 bg-[#101720] lg:flex lg:flex-col"
                        aria-label="PickSports decision pipeline"
                    >
                        <div
                            class="flex items-start justify-between border-b border-white/10 px-8 py-7"
                        >
                            <div>
                                <div
                                    class="text-xs font-semibold text-[#7ee2b8] uppercase"
                                >
                                    Decision desk
                                </div>
                                <div class="mt-2 text-xl font-semibold">
                                    Every lean earns its way through.
                                </div>
                            </div>
                            <span
                                class="flex items-center gap-2 border border-[#7ee2b8]/30 bg-[#7ee2b8]/10 px-2.5 py-1 text-[10px] font-semibold text-[#7ee2b8] uppercase"
                            >
                                <span class="size-1.5 bg-[#7ee2b8]" />
                                Pregame
                            </span>
                        </div>

                        <div class="grid grid-cols-4 border-b border-white/10">
                            <div
                                v-for="(step, index) in decisionSteps"
                                :key="step.label"
                                class="border-r border-white/10 px-4 py-5 last:border-r-0"
                            >
                                <div class="flex items-center justify-between">
                                    <component
                                        :is="step.icon"
                                        class="size-4 text-[#65b9f3]"
                                    />
                                    <span
                                        class="font-mono text-[10px] text-[#657080]"
                                        >0{{ index + 1 }}</span
                                    >
                                </div>
                                <div class="mt-4 text-sm font-semibold">
                                    {{ step.label }}
                                </div>
                            </div>
                        </div>

                        <div class="flex-1">
                            <div
                                class="grid grid-cols-[0.7fr_1.25fr_1fr_0.65fr] border-b border-white/10 px-8 py-3 text-[10px] font-semibold text-[#657080] uppercase"
                            >
                                <div>Sport</div>
                                <div>Model</div>
                                <div>Market</div>
                                <div class="text-right">Layer</div>
                            </div>
                            <div
                                v-for="(sport, index) in sports"
                                :key="sport.slug"
                                class="grid grid-cols-[0.7fr_1.25fr_1fr_0.65fr] items-center border-b border-white/10 px-8 py-4 text-sm"
                            >
                                <div
                                    class="flex items-center gap-3 font-semibold"
                                >
                                    <span :class="['h-5 w-1', sport.accent]" />
                                    {{ sport.label }}
                                </div>
                                <div class="text-[#aeb8c5]">Probability</div>
                                <div class="text-[#aeb8c5]">Price check</div>
                                <div
                                    class="text-right font-mono text-[10px]"
                                    :class="
                                        index % 3 === 1
                                            ? 'text-[#ffb45f]'
                                            : 'text-[#7ee2b8]'
                                    "
                                >
                                    {{ index % 3 === 1 ? 'GATED' : 'TRACKED' }}
                                </div>
                            </div>
                        </div>

                        <div
                            class="flex items-center justify-between border-t border-white/10 bg-[#0c1118] px-8 py-5"
                        >
                            <div class="text-sm text-[#8993a2]">
                                Prediction, price, risk, settlement.
                            </div>
                            <Link
                                :href="performanceRoute()"
                                class="flex items-center gap-2 text-sm font-semibold text-[#65b9f3]"
                            >
                                Audit the record
                                <ArrowRight class="size-4" />
                            </Link>
                        </div>
                    </div>
                </div>
            </section>

            <section
                class="border-b border-black/10 bg-white dark:border-white/10 dark:bg-[#11151b]"
            >
                <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
                    <div
                        class="flex flex-col gap-3 border-b border-black/10 pb-6 sm:flex-row sm:items-end sm:justify-between dark:border-white/10"
                    >
                        <div>
                            <p
                                class="text-xs font-semibold text-[#697382] uppercase"
                            >
                                Verified record
                            </p>
                            <h2
                                class="mt-2 text-2xl font-semibold tracking-normal sm:text-3xl"
                            >
                                Results without a fallback story
                            </h2>
                        </div>
                        <Link
                            :href="performanceRoute()"
                            class="inline-flex items-center gap-2 text-sm font-semibold text-[#006fbb] hover:text-[#00568f] dark:text-[#62b5f6]"
                        >
                            Full methodology
                            <ArrowRight class="size-4" />
                        </Link>
                    </div>

                    <div
                        class="grid divide-y divide-black/10 sm:grid-cols-2 sm:divide-x sm:divide-y-0 lg:grid-cols-4 dark:divide-white/10"
                    >
                        <div class="py-6 sm:pr-6">
                            <div
                                class="text-sm text-[#697382] dark:text-[#98a2b0]"
                            >
                                All-time accuracy
                            </div>
                            <div
                                class="mt-2 text-3xl font-semibold tracking-normal"
                            >
                                {{
                                    formatPercent(
                                        overall.winner_accuracy,
                                        hasOverallSample,
                                    )
                                }}
                            </div>
                            <div
                                class="mt-2 text-sm text-[#697382] dark:text-[#98a2b0]"
                            >
                                {{
                                    hasOverallSample
                                        ? `${overall.win_record} across ${overall.total_predictions.toLocaleString()} graded predictions`
                                        : 'Awaiting a graded prediction sample'
                                }}
                            </div>
                        </div>
                        <div class="py-6 sm:px-6">
                            <div
                                class="text-sm text-[#697382] dark:text-[#98a2b0]"
                            >
                                Last 30 days
                            </div>
                            <div
                                class="mt-2 text-3xl font-semibold tracking-normal"
                            >
                                {{
                                    formatPercent(
                                        recent.winner_accuracy,
                                        hasRecentSample,
                                    )
                                }}
                            </div>
                            <div
                                class="mt-2 text-sm text-[#697382] dark:text-[#98a2b0]"
                            >
                                {{
                                    hasRecentSample
                                        ? `${recent.win_record} from ${recent.total_predictions} predictions`
                                        : 'No graded predictions in this window'
                                }}
                            </div>
                        </div>
                        <div class="py-6 sm:pr-6 lg:px-6">
                            <div
                                class="text-sm text-[#697382] dark:text-[#98a2b0]"
                            >
                                Settled decision ROI
                            </div>
                            <div
                                class="mt-2 text-3xl font-semibold tracking-normal"
                            >
                                {{
                                    formatSignedPercent(
                                        roi.roi_percentage,
                                        hasRoiSample,
                                    )
                                }}
                            </div>
                            <div
                                class="mt-2 text-sm text-[#697382] dark:text-[#98a2b0]"
                            >
                                {{
                                    hasRoiSample
                                        ? `${roi.total_bets} pregame-safe settled bets`
                                        : 'No settled qualifying bets yet'
                                }}
                            </div>
                        </div>
                        <div class="py-6 sm:px-6 lg:pr-0">
                            <div
                                class="text-sm text-[#697382] dark:text-[#98a2b0]"
                            >
                                Average spread error
                            </div>
                            <div
                                class="mt-2 text-3xl font-semibold tracking-normal"
                            >
                                {{
                                    hasOverallSample &&
                                    overall.avg_spread_error !== null
                                        ? overall.avg_spread_error.toFixed(2)
                                        : 'Pending'
                                }}
                            </div>
                            <div
                                class="mt-2 text-sm text-[#697382] dark:text-[#98a2b0]"
                            >
                                {{
                                    hasOverallSample
                                        ? 'Points from the final margin'
                                        : 'Requires graded spread targets'
                                }}
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="bg-[#f4f6f8] py-16 dark:bg-[#0b0e12]">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="max-w-2xl">
                        <p
                            class="text-xs font-semibold text-[#697382] uppercase"
                        >
                            League coverage
                        </p>
                        <h2
                            class="mt-2 text-3xl font-semibold tracking-normal sm:text-4xl"
                        >
                            Start with the sport you follow
                        </h2>
                        <p
                            class="mt-3 text-base leading-7 text-[#697382] dark:text-[#98a2b0]"
                        >
                            Open the public league board for predictions, team
                            context, injuries, and available markets.
                        </p>
                    </div>

                    <div class="mt-9 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Link
                            v-for="sport in sports"
                            :key="sport.slug"
                            :href="`/${sport.slug}`"
                            class="group relative flex min-h-32 items-center justify-between overflow-hidden rounded-md border border-black/10 bg-white p-5 transition hover:-translate-y-0.5 hover:border-[#006fbb] hover:shadow-md dark:border-white/10 dark:bg-[#11151b] dark:hover:border-[#62b5f6]"
                        >
                            <span
                                :class="[
                                    'absolute inset-y-0 left-0 w-1',
                                    sport.accent,
                                ]"
                            />
                            <div>
                                <span
                                    class="text-xl font-semibold tracking-normal"
                                    >{{ sport.label }}</span
                                >
                                <div
                                    class="mt-2 text-sm text-[#697382] dark:text-[#98a2b0]"
                                >
                                    {{ sport.detail }}
                                </div>
                            </div>
                            <ArrowRight
                                class="size-5 text-[#8b95a3] transition group-hover:translate-x-1 group-hover:text-[#006fbb] dark:group-hover:text-[#62b5f6]"
                            />
                        </Link>
                    </div>
                </div>
            </section>

            <section
                class="border-y border-black/10 bg-[#e8edf2] py-16 dark:border-white/10 dark:bg-[#151a21]"
            >
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div
                        class="grid gap-10 lg:grid-cols-[0.8fr_1.2fr] lg:items-start"
                    >
                        <div>
                            <p
                                class="text-xs font-semibold text-[#697382] uppercase dark:text-[#98a2b0]"
                            >
                                Decision discipline
                            </p>
                            <h2
                                class="mt-2 text-3xl font-semibold tracking-normal sm:text-4xl"
                            >
                                A prediction is not automatically a bet
                            </h2>
                            <p
                                class="mt-4 max-w-xl text-base leading-7 text-[#59616d] dark:text-[#aab2bf]"
                            >
                                The recommendation layer can reject a model lean
                                when the price, evidence, or uncertainty does
                                not support a wager.
                            </p>
                        </div>

                        <ol
                            class="grid gap-px overflow-hidden rounded-md border border-black/10 bg-black/10 sm:grid-cols-2 dark:border-white/10 dark:bg-white/10"
                        >
                            <li
                                v-for="(step, index) in decisionSteps"
                                :key="step.label"
                                class="min-h-44 bg-white p-6 dark:bg-[#0f1319]"
                            >
                                <div class="flex items-center justify-between">
                                    <component
                                        :is="step.icon"
                                        class="size-5 text-[#006fbb] dark:text-[#62b5f6]"
                                    />
                                    <span
                                        class="font-mono text-xs text-[#8b95a3]"
                                        >0{{ index + 1 }}</span
                                    >
                                </div>
                                <h3
                                    class="mt-7 text-lg font-semibold tracking-normal"
                                >
                                    {{ step.label }}
                                </h3>
                                <p
                                    class="mt-2 text-sm leading-6 text-[#697382] dark:text-[#98a2b0]"
                                >
                                    {{ step.detail }}
                                </p>
                            </li>
                        </ol>
                    </div>
                </div>
            </section>

            <section class="bg-white py-14 dark:bg-[#11151b]">
                <div
                    class="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8"
                >
                    <div class="flex items-start gap-4">
                        <CheckCircle2
                            class="mt-1 size-6 shrink-0 text-emerald-600 dark:text-emerald-400"
                        />
                        <div>
                            <h2 class="text-2xl font-semibold tracking-normal">
                                Keep every result accountable
                            </h2>
                            <p
                                class="mt-2 max-w-2xl text-[#697382] dark:text-[#98a2b0]"
                            >
                                Review the current board, follow settled
                                outcomes, and see when the system chooses not to
                                bet.
                            </p>
                        </div>
                    </div>
                    <Link
                        v-if="$page.props.auth.user"
                        :href="dashboard()"
                        class="shrink-0"
                    >
                        <Button size="lg" class="gap-2 rounded-md">
                            Open dashboard
                            <ArrowRight class="size-4" />
                        </Button>
                    </Link>
                    <Link
                        v-else-if="canRegister"
                        :href="register()"
                        class="shrink-0"
                    >
                        <Button size="lg" class="gap-2 rounded-md">
                            Create account
                            <ArrowRight class="size-4" />
                        </Button>
                    </Link>
                    <Link v-else :href="login()" class="shrink-0">
                        <Button size="lg" class="gap-2 rounded-md">
                            Log in
                            <ArrowRight class="size-4" />
                        </Button>
                    </Link>
                </div>
            </section>
        </main>

        <footer class="border-t border-white/10 bg-[#0c1118] text-white">
            <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
                <div
                    class="flex flex-col gap-8 lg:flex-row lg:items-start lg:justify-between"
                >
                    <div class="max-w-xl">
                        <div class="flex items-center gap-3">
                            <span
                                class="flex size-8 items-center justify-center rounded-md bg-white text-xs font-bold text-[#151a21]"
                                >PS</span
                            >
                            <span class="font-semibold tracking-normal"
                                >PickSports</span
                            >
                        </div>
                        <p class="mt-4 text-sm leading-6 text-[#9da7b5]">
                            For entertainment and educational purposes only.
                            Gambling involves risk of loss. Never wager more
                            than you can afford to lose. Call 1-800-GAMBLER for
                            help.
                        </p>
                    </div>

                    <div
                        class="flex flex-wrap gap-x-6 gap-y-3 text-sm text-[#c8d0db]"
                    >
                        <Link
                            :href="performanceRoute()"
                            class="hover:text-white"
                            >Performance</Link
                        >
                        <Link :href="terms()" class="hover:text-white"
                            >Terms</Link
                        >
                        <Link :href="privacy()" class="hover:text-white"
                            >Privacy</Link
                        >
                        <Link
                            :href="responsibleGambling()"
                            class="hover:text-white"
                        >
                            Responsible gambling
                        </Link>
                    </div>
                </div>

                <div
                    class="mt-8 border-t border-white/10 pt-6 text-xs text-[#7f8997]"
                >
                    &copy; 2026 PickSports. Not affiliated with any professional
                    sports league or gambling operator.
                </div>
            </div>
        </footer>
    </div>
</template>
