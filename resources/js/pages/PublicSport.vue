<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard, home, login, register } from '@/routes';

type InjuryItem = {
    id: number;
    player_name: string;
    team_abbreviation?: string | null;
    status?: string | null;
    detail?: string | null;
    type?: string | null;
    injury_date?: string | null;
    return_date?: string | null;
    source_updated_at?: string | null;
    updated_at?: string | null;
    impact_score?: number | null;
    impact_label?: string | null;
};

type TopTeam = {
    rank: number;
    team_id?: number | null;
    name: string;
    abbreviation?: string | null;
    logo?: string | null;
    record?: string | null;
    primary_metric_label: string;
    primary_metric_value?: number | string | null;
    net_rating?: number | string | null;
};

type TopPlayer = {
    player_id?: number | null;
    name: string;
    team_abbreviation?: string | null;
    position?: string | null;
    headshot?: string | null;
    games_played?: number | null;
    headline_stat_label: string;
    headline_stat_value?: number | string | null;
};

type FeaturedPrediction = {
    id: number;
    game_id?: number | null;
    matchup: string;
    game_date?: string | null;
    status?: string | null;
    pick: string;
    home_team_abbreviation?: string | null;
    away_team_abbreviation?: string | null;
    predicted_spread?: number | string | null;
    predicted_total?: number | string | null;
    confidence_score?: number | string | null;
    home_win_probability?: number | string | null;
};

type FeaturedProp = {
    id: number;
    player: {
        id: number;
        name: string;
        position?: string | null;
        team?: string | null;
        headshot?: string | null;
        url?: string | null;
    };
    market: string;
    line: number;
    recommendation: string;
    odds: number;
    confidence: number;
    edge: number;
    stats?: {
        times_covered_last5?: { hits: number; games: number } | null;
        times_covered_season?: { hits: number; games: number } | null;
    } | null;
    game: {
        id: number;
        home_team?: string | null;
        away_team?: string | null;
        date?: string | null;
        time?: string | null;
    };
};

type ConferencePlayoffTeam = {
    team_id: number;
    seed?: number | null;
    projected_seed?: number | null;
    name: string;
    abbreviation?: string | null;
    logo?: string | null;
    conference: string;
    playoff_make_probability?: number | null;
    direct_playoff_probability?: number | null;
};

const props = defineProps<{
    sport: string;
    sportLabel: string;
    latestSeason?: number | null;
    hasPlayerProps: boolean;
    conferencePlayoffTeams?: {
        east: ConferencePlayoffTeam[];
        west: ConferencePlayoffTeam[];
    } | null;
    injuries: {
        top: InjuryItem[];
        recent: InjuryItem[];
    };
    topTeams: TopTeam[];
    topPlayers: TopPlayer[];
    featuredPredictions: FeaturedPrediction[];
    featuredProps: FeaturedProp[];
    links: {
        predictions: string;
        injuries: string;
        teamMetrics: string;
        playerStats: string;
        playerProps: string;
    };
    summary: {
        topInjuriesCount: number;
        recentInjuriesCount: number;
        topTeamsCount: number;
        topPlayersCount: number;
        predictionsCount: number;
        propsCount: number;
    };
}>();

const navLinks = computed(() => [
    { label: 'Predictions', href: props.links.predictions },
    { label: 'Injuries', href: props.links.injuries },
    { label: 'Teams', href: props.links.teamMetrics },
    { label: 'Players', href: props.links.playerStats },
    ...(props.hasPlayerProps
        ? [{ label: 'Props', href: props.links.playerProps }]
        : []),
]);

const formatMetric = (value: number | string | null | undefined) => {
    if (value === null || value === undefined || value === '') return 'N/A';
    const numeric = Number(value);
    if (Number.isNaN(numeric)) return String(value);
    if (Math.abs(numeric) >= 100) return numeric.toFixed(0);
    if (Math.abs(numeric) >= 10) return numeric.toFixed(1);
    return numeric.toFixed(2);
};

const formatGameDate = (value: string | null | undefined) => {
    if (!value) return 'TBD';

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
};

const impactTone = (label?: string | null) => {
    if (label === 'High') return 'destructive';
    if (label === 'Medium') return 'secondary';
    return 'outline';
};

const formatProbability = (value: number | null | undefined) => {
    if (value === null || value === undefined) return 'N/A';
    return `${(value * 100).toFixed(1)}%`;
};

const coverageBreakdown = (prop: FeaturedProp) => {
    const season = prop.stats?.times_covered_season;
    const last5 = prop.stats?.times_covered_last5;
    const source = season && season.games > 0 ? season : last5;

    if (!source || source.games <= 0) {
        return null;
    }

    return {
        hits: source.hits,
        misses: Math.max(0, source.games - source.hits),
        games: source.games,
        label: source === season ? 'Season cover rate' : 'Last 5 cover rate',
        hitPct: (source.hits / source.games) * 100,
    };
};

const predictionBarWidth = (prediction: FeaturedPrediction) =>
    `${Math.max(12, Math.min(100, Number(prediction.home_win_probability ?? 0)))}%`;

const propRecommendationTone = (recommendation: string) =>
    recommendation.toLowerCase() === 'over'
        ? 'border-emerald-400/25 bg-emerald-400/10 text-emerald-200'
        : 'border-rose-400/25 bg-rose-400/10 text-rose-200';

const propCardTone = (recommendation: string) =>
    recommendation.toLowerCase() === 'over'
        ? 'from-emerald-500/12 via-white/[0.04] to-transparent'
        : 'from-rose-500/12 via-white/[0.04] to-transparent';

const topPrediction = computed(() => props.featuredPredictions[0] ?? null);
const topTeam = computed(() => props.topTeams[0] ?? null);
const topPlayer = computed(() => props.topPlayers[0] ?? null);
</script>

<template>
    <Head :title="`${sportLabel} Picks, Injuries, Teams, and Props`">
        <meta
            head-key="description"
            name="description"
            :content="`Public ${sportLabel} hub with top injuries, team rankings, player leaders, predictions, and player props.`"
        />
    </Head>

    <div
        class="min-h-screen overflow-x-hidden bg-[radial-gradient(circle_at_top,_rgba(251,191,36,0.16),_transparent_24%),radial-gradient(circle_at_85%_8%,_rgba(56,189,248,0.18),_transparent_18%),linear-gradient(180deg,_rgba(15,23,42,0.96),_rgba(2,6,23,1))] text-slate-50"
    >
        <nav
            class="sticky top-0 z-40 border-b border-white/10 bg-slate-950/70 backdrop-blur"
        >
            <div
                class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8"
            >
                <Link :href="home()" class="flex items-center gap-3">
                    <div
                        class="flex size-9 items-center justify-center rounded-xl bg-amber-400 text-sm font-bold text-slate-950"
                    >
                        PS
                    </div>
                    <div>
                        <div
                            class="text-sm font-semibold tracking-[0.22em] text-amber-300"
                        >
                            PICKSPORTS
                        </div>
                        <div class="text-xs text-slate-400">
                            {{ sportLabel }} public board
                        </div>
                    </div>
                </Link>

                <div class="flex items-center gap-2">
                    <template v-if="$page.props.auth.user">
                        <Link :href="dashboard()">
                            <Button size="sm">Dashboard</Button>
                        </Link>
                    </template>
                    <template v-else>
                        <Link :href="login()">
                            <Button
                                size="sm"
                                variant="ghost"
                                class="text-slate-100 hover:bg-white/10"
                                >Log in</Button
                            >
                        </Link>
                        <Link :href="register()">
                            <Button
                                size="sm"
                                class="bg-amber-400 text-slate-950 hover:bg-amber-300"
                                >Get Started</Button
                            >
                        </Link>
                    </template>
                </div>
            </div>
        </nav>

        <section class="border-b border-white/10">
            <div
                class="mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:px-6 lg:grid-cols-[1.35fr_0.95fr] lg:px-8 lg:py-20"
            >
                <div>
                    <Badge
                        variant="outline"
                        class="border-amber-300/40 bg-amber-300/10 text-amber-200 shadow-[0_0_0_1px_rgba(251,191,36,0.06)]"
                    >
                        Public {{ sportLabel }} intelligence
                    </Badge>
                    <h1
                        class="mt-6 max-w-4xl text-4xl font-semibold tracking-tight text-white sm:text-6xl"
                    >
                        Turn live {{ sportLabel }} signal into sharper picks
                        before the market catches up.
                    </h1>
                    <p class="mt-5 max-w-2xl text-lg leading-8 text-slate-300">
                        This page packages the strongest public hooks from
                        PickSports into a fast read: injuries that move lines,
                        teams rising into the playoff field, top scorers,
                        featured predictions, and player props worth a second
                        look.
                    </p>

                    <div class="mt-8 flex flex-wrap gap-3">
                        <a
                            v-for="link in navLinks"
                            :key="link.label"
                            :href="link.href"
                            class="rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm font-medium text-slate-200 transition hover:border-amber-300/50 hover:bg-white/10"
                        >
                            {{ link.label }}
                        </a>
                    </div>

                    <div class="mt-10 flex flex-col gap-3 sm:flex-row">
                        <Link v-if="!$page.props.auth.user" :href="register()">
                            <Button
                                size="lg"
                                class="h-12 bg-amber-400 px-7 text-base font-semibold text-slate-950 hover:bg-amber-300"
                            >
                                Unlock Full Board
                            </Button>
                        </Link>
                        <Link v-if="!$page.props.auth.user" :href="login()">
                            <Button
                                size="lg"
                                variant="ghost"
                                class="h-12 border border-white/15 bg-white/5 px-7 text-base text-slate-100 hover:bg-white/10"
                            >
                                Already a member
                            </Button>
                        </Link>
                    </div>

                    <div class="mt-10 grid gap-4 sm:grid-cols-3">
                        <div
                            class="rounded-3xl border border-white/10 bg-white/[0.06] p-5 shadow-[0_20px_60px_-35px_rgba(0,0,0,0.9)]"
                        >
                            <div
                                class="text-xs tracking-[0.2em] text-amber-300 uppercase"
                            >
                                Lead Story
                            </div>
                            <div class="mt-3 text-lg font-semibold text-white">
                                {{
                                    topPrediction?.pick ??
                                    topTeam?.name ??
                                    `${sportLabel} board`
                                }}
                            </div>
                            <div class="mt-2 text-sm text-slate-300">
                                {{
                                    topPrediction?.matchup ??
                                    'See the strongest model lean, team momentum, and injury-driven spots.'
                                }}
                            </div>
                        </div>
                        <div
                            class="rounded-3xl border border-white/10 bg-white/[0.05] p-5"
                        >
                            <div
                                class="text-xs tracking-[0.2em] text-sky-300 uppercase"
                            >
                                Top Team
                            </div>
                            <div class="mt-3 text-lg font-semibold text-white">
                                {{ topTeam?.name ?? 'No team data yet' }}
                            </div>
                            <div class="mt-2 text-sm text-slate-300">
                                {{ topTeam?.primary_metric_label ?? 'Metric' }}
                                {{
                                    formatMetric(topTeam?.primary_metric_value)
                                }}
                                <span v-if="topTeam?.record"
                                    >· {{ topTeam.record }}</span
                                >
                            </div>
                        </div>
                        <div
                            class="rounded-3xl border border-white/10 bg-white/[0.05] p-5"
                        >
                            <div
                                class="text-xs tracking-[0.2em] text-emerald-300 uppercase"
                            >
                                Top Scorer
                            </div>
                            <div class="mt-3 text-lg font-semibold text-white">
                                {{ topPlayer?.name ?? 'No player data yet' }}
                            </div>
                            <div class="mt-2 text-sm text-slate-300">
                                {{ topPlayer?.headline_stat_label ?? 'PPG' }}
                                {{
                                    formatMetric(topPlayer?.headline_stat_value)
                                }}
                                <span v-if="topPlayer?.team_abbreviation"
                                    >· {{ topPlayer.team_abbreviation }}</span
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
                    <div
                        class="overflow-hidden rounded-[2rem] border border-white/10 bg-gradient-to-br from-amber-300/20 via-white/8 to-sky-400/10 p-6 shadow-[0_30px_80px_-40px_rgba(251,191,36,0.45)]"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div
                                    class="text-xs tracking-[0.24em] text-slate-300 uppercase"
                                >
                                    Coverage
                                </div>
                                <div
                                    class="mt-3 text-4xl font-semibold text-white"
                                >
                                    {{ latestSeason ?? 'Live' }}
                                </div>
                                <div
                                    class="mt-2 max-w-xs text-sm text-slate-200/85"
                                >
                                    Current season snapshots, market-facing
                                    signals, and fast public reads built to
                                    convert interest into action.
                                </div>
                            </div>
                            <div
                                class="rounded-full border border-white/15 bg-slate-950/50 px-3 py-1 text-xs font-semibold tracking-[0.18em] text-amber-300 uppercase"
                            >
                                {{ sportLabel }}
                            </div>
                        </div>

                        <div
                            class="mt-6 h-32 rounded-[1.5rem] border border-white/10 bg-[linear-gradient(135deg,rgba(255,255,255,0.12),rgba(255,255,255,0.02))] p-4"
                        >
                            <div class="flex h-full items-end gap-2">
                                <div
                                    class="h-[40%] flex-1 rounded-t-2xl bg-amber-300/75"
                                />
                                <div
                                    class="h-[68%] flex-1 rounded-t-2xl bg-sky-400/75"
                                />
                                <div
                                    class="h-[52%] flex-1 rounded-t-2xl bg-emerald-400/75"
                                />
                                <div
                                    class="h-[84%] flex-1 rounded-t-2xl bg-white/80"
                                />
                                <div
                                    class="h-[61%] flex-1 rounded-t-2xl bg-rose-300/75"
                                />
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div
                            class="rounded-3xl border border-white/10 bg-white/[0.06] p-5"
                        >
                            <div
                                class="text-xs tracking-[0.2em] text-slate-400 uppercase"
                            >
                                Predictions
                            </div>
                            <div class="mt-2 text-3xl font-semibold text-white">
                                {{ summary.predictionsCount }}
                            </div>
                            <div class="mt-1 text-xs text-slate-400">
                                Featured model spots
                            </div>
                        </div>
                        <div
                            class="rounded-3xl border border-white/10 bg-white/[0.06] p-5"
                        >
                            <div
                                class="text-xs tracking-[0.2em] text-slate-400 uppercase"
                            >
                                Props
                            </div>
                            <div class="mt-2 text-3xl font-semibold text-white">
                                {{ summary.propsCount }}
                            </div>
                            <div class="mt-1 text-xs text-slate-400">
                                High-confidence edges
                            </div>
                        </div>
                        <div
                            class="rounded-3xl border border-white/10 bg-white/[0.06] p-5"
                        >
                            <div
                                class="text-xs tracking-[0.2em] text-slate-400 uppercase"
                            >
                                Teams
                            </div>
                            <div class="mt-2 text-3xl font-semibold text-white">
                                {{ summary.topTeamsCount }}
                            </div>
                            <div class="mt-1 text-xs text-slate-400">
                                Power rankings snapshot
                            </div>
                        </div>
                        <div
                            class="rounded-3xl border border-white/10 bg-white/[0.06] p-5"
                        >
                            <div
                                class="text-xs tracking-[0.2em] text-slate-400 uppercase"
                            >
                                Players
                            </div>
                            <div class="mt-2 text-3xl font-semibold text-white">
                                {{ summary.topPlayersCount }}
                            </div>
                            <div class="mt-1 text-xs text-slate-400">
                                Public scoring leaders
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <main class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <section
                class="mb-6 overflow-hidden rounded-[2rem] border border-white/10 bg-[linear-gradient(135deg,rgba(251,191,36,0.12),rgba(255,255,255,0.04),rgba(56,189,248,0.1))] p-6 shadow-[0_24px_80px_-45px_rgba(255,255,255,0.3)]"
            >
                <div class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
                    <div>
                        <div
                            class="text-xs font-semibold tracking-[0.24em] text-amber-300 uppercase"
                        >
                            Today On The Board
                        </div>
                        <h2
                            class="mt-3 text-2xl font-semibold text-white sm:text-3xl"
                        >
                            The fastest way to understand what matters in
                            {{ sportLabel }} right now.
                        </h2>
                        <p
                            class="mt-3 max-w-2xl text-sm leading-7 text-slate-300"
                        >
                            Start with the market-facing pieces first: featured
                            model picks, prop spots, playoff movement, and
                            injury context. The rest of the platform goes deeper
                            once a visitor is ready to register.
                        </p>
                        <div class="mt-5 flex flex-wrap gap-3">
                            <a
                                :href="links.predictions"
                                class="rounded-full border border-sky-400/25 bg-sky-400/10 px-4 py-2 text-sm font-medium text-sky-100 transition hover:bg-sky-400/15"
                            >
                                Explore predictions
                            </a>
                            <a
                                v-if="hasPlayerProps"
                                :href="links.playerProps"
                                class="rounded-full border border-emerald-400/25 bg-emerald-400/10 px-4 py-2 text-sm font-medium text-emerald-100 transition hover:bg-emerald-400/15"
                            >
                                Check player props
                            </a>
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div
                            class="rounded-2xl border border-white/10 bg-slate-950/45 p-4"
                        >
                            <div
                                class="text-xs tracking-[0.18em] text-slate-500 uppercase"
                            >
                                Featured Plays
                            </div>
                            <div class="mt-2 text-2xl font-semibold text-white">
                                {{
                                    summary.predictionsCount +
                                    summary.propsCount
                                }}
                            </div>
                            <div class="mt-1 text-sm text-slate-300">
                                Public picks and prop hooks above the fold.
                            </div>
                        </div>
                        <div
                            class="rounded-2xl border border-white/10 bg-slate-950/45 p-4"
                        >
                            <div
                                class="text-xs tracking-[0.18em] text-slate-500 uppercase"
                            >
                                Injury Feed
                            </div>
                            <div class="mt-2 text-2xl font-semibold text-white">
                                {{
                                    summary.topInjuriesCount +
                                    summary.recentInjuriesCount
                                }}
                            </div>
                            <div class="mt-1 text-sm text-slate-300">
                                Impactful absences and recent updates.
                            </div>
                        </div>
                        <div
                            class="rounded-2xl border border-white/10 bg-slate-950/45 p-4"
                        >
                            <div
                                class="text-xs tracking-[0.18em] text-slate-500 uppercase"
                            >
                                Top Teams
                            </div>
                            <div class="mt-2 text-2xl font-semibold text-white">
                                {{ summary.topTeamsCount }}
                            </div>
                            <div class="mt-1 text-sm text-slate-300">
                                Power rankings snapshot for quick scanning.
                            </div>
                        </div>
                        <div
                            class="rounded-2xl border border-white/10 bg-slate-950/45 p-4"
                        >
                            <div
                                class="text-xs tracking-[0.18em] text-slate-500 uppercase"
                            >
                                Top Players
                            </div>
                            <div class="mt-2 text-2xl font-semibold text-white">
                                {{ summary.topPlayersCount }}
                            </div>
                            <div class="mt-1 text-sm text-slate-300">
                                Scoring leaders pulled from player stats.
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mt-6">
                <div class="mb-4 flex items-end justify-between gap-4">
                    <div>
                        <div
                            class="text-xs font-semibold tracking-[0.24em] text-sky-300 uppercase"
                        >
                            Best Bets First
                        </div>
                        <h2 class="mt-2 text-2xl font-semibold text-white">
                            Lead with the strongest public reasons to sign up.
                        </h2>
                    </div>
                    <div
                        class="hidden rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs tracking-[0.18em] text-slate-400 uppercase lg:block"
                    >
                        Predictions, props, then context
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <Card
                        class="overflow-hidden border-white/10 bg-white/5 text-slate-50"
                    >
                        <CardHeader>
                            <CardTitle
                                class="flex items-center justify-between gap-3"
                            >
                                <span>5 Predictions</span>
                                <span
                                    class="rounded-full border border-sky-400/20 bg-sky-400/10 px-3 py-1 text-xs font-semibold tracking-[0.18em] text-sky-200 uppercase"
                                    >Model Board</span
                                >
                            </CardTitle>
                            <CardDescription class="text-slate-400"
                                >Best current prediction snapshots on the
                                board.</CardDescription
                            >
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <div
                                v-if="featuredPredictions.length === 0"
                                class="text-sm text-slate-400"
                            >
                                No predictions available.
                            </div>
                            <div
                                v-for="(
                                    prediction, index
                                ) in featuredPredictions"
                                :key="prediction.id"
                                class="rounded-2xl border border-white/10 p-4 transition hover:border-sky-300/30 hover:bg-white/[0.06]"
                                :class="
                                    index === 0
                                        ? 'bg-gradient-to-br from-sky-400/18 via-white/[0.06] to-transparent shadow-[0_18px_50px_-30px_rgba(56,189,248,0.45)]'
                                        : 'bg-gradient-to-br from-sky-500/10 via-white/[0.04] to-transparent'
                                "
                            >
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span
                                                v-if="index === 0"
                                                class="rounded-full border border-sky-300/20 bg-sky-300/10 px-2 py-1 text-[10px] font-semibold tracking-[0.18em] text-sky-200 uppercase"
                                                >Featured</span
                                            >
                                            <span
                                                class="font-medium text-white"
                                                >{{ prediction.matchup }}</span
                                            >
                                        </div>
                                        <div
                                            class="mt-1 text-sm text-slate-400"
                                        >
                                            {{
                                                formatGameDate(
                                                    prediction.game_date,
                                                )
                                            }}
                                        </div>
                                    </div>
                                    <Badge
                                        variant="secondary"
                                        class="border border-white/10 bg-white/10 text-white"
                                        >{{ prediction.pick }}</Badge
                                    >
                                </div>
                                <div
                                    class="mt-3 grid grid-cols-3 gap-3 text-sm"
                                >
                                    <div>
                                        <div
                                            class="text-xs tracking-[0.16em] text-slate-500 uppercase"
                                        >
                                            Home Win%
                                        </div>
                                        <div
                                            class="mt-1 font-medium text-slate-100"
                                        >
                                            {{
                                                formatMetric(
                                                    prediction.home_win_probability,
                                                )
                                            }}%
                                        </div>
                                    </div>
                                    <div>
                                        <div
                                            class="text-xs tracking-[0.16em] text-slate-500 uppercase"
                                        >
                                            Spread
                                        </div>
                                        <div
                                            class="mt-1 font-medium text-slate-100"
                                        >
                                            {{
                                                formatMetric(
                                                    prediction.predicted_spread,
                                                )
                                            }}
                                        </div>
                                    </div>
                                    <div>
                                        <div
                                            class="text-xs tracking-[0.16em] text-slate-500 uppercase"
                                        >
                                            Confidence
                                        </div>
                                        <div
                                            class="mt-1 font-medium text-slate-100"
                                        >
                                            {{
                                                formatMetric(
                                                    prediction.confidence_score,
                                                )
                                            }}
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <div
                                        class="flex items-center justify-between text-[11px] tracking-[0.18em] text-slate-500 uppercase"
                                    >
                                        <span>Model edge</span>
                                        <span
                                            >{{
                                                formatMetric(
                                                    prediction.home_win_probability,
                                                )
                                            }}% home win</span
                                        >
                                    </div>
                                    <div
                                        class="mt-2 h-2 overflow-hidden rounded-full bg-white/10"
                                    >
                                        <div
                                            class="h-full rounded-full bg-gradient-to-r from-sky-300 to-cyan-400"
                                            :style="{
                                                width: predictionBarWidth(
                                                    prediction,
                                                ),
                                            }"
                                        />
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card
                        class="overflow-hidden border-white/10 bg-white/5 text-slate-50"
                    >
                        <CardHeader>
                            <CardTitle
                                class="flex items-center justify-between gap-3"
                            >
                                <span>5 Player Props</span>
                                <span
                                    class="rounded-full border border-violet-400/20 bg-violet-400/10 px-3 py-1 text-xs font-semibold tracking-[0.18em] text-violet-200 uppercase"
                                    >Edge Signals</span
                                >
                            </CardTitle>
                            <CardDescription class="text-slate-400">
                                Highest-confidence player prop recommendations
                                currently available.
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <div
                                v-if="featuredProps.length === 0"
                                class="text-sm text-slate-400"
                            >
                                {{
                                    hasPlayerProps
                                        ? 'No public prop recommendations available right now.'
                                        : 'Player props are not enabled for this sport.'
                                }}
                            </div>
                            <div
                                v-for="(prop, index) in featuredProps"
                                :key="prop.id"
                                class="rounded-2xl border border-white/10 p-4 transition hover:border-white/20 hover:bg-white/[0.06]"
                                :class="[
                                    propCardTone(prop.recommendation),
                                    index === 0
                                        ? 'shadow-[0_18px_50px_-30px_rgba(16,185,129,0.35)]'
                                        : '',
                                ]"
                            >
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span
                                                v-if="index === 0"
                                                class="rounded-full border border-amber-300/20 bg-amber-300/10 px-2 py-1 text-[10px] font-semibold tracking-[0.18em] text-amber-200 uppercase"
                                                >Most clickable</span
                                            >
                                            <span
                                                class="font-medium text-white"
                                                >{{ prop.player.name }}</span
                                            >
                                        </div>
                                        <div
                                            class="mt-1 text-sm text-slate-400"
                                        >
                                            {{ prop.player.team }} ·
                                            {{ prop.market }}
                                        </div>
                                    </div>
                                    <span
                                        class="rounded-full border px-3 py-1 text-xs font-semibold tracking-[0.16em] uppercase"
                                        :class="
                                            propRecommendationTone(
                                                prop.recommendation,
                                            )
                                        "
                                    >
                                        {{ prop.recommendation }}
                                        {{ formatMetric(prop.line) }}
                                    </span>
                                </div>
                                <div
                                    class="mt-3 grid grid-cols-3 gap-3 text-sm"
                                >
                                    <div>
                                        <div
                                            class="text-xs tracking-[0.16em] text-slate-500 uppercase"
                                        >
                                            Confidence
                                        </div>
                                        <div
                                            class="mt-1 font-medium text-slate-100"
                                        >
                                            {{ formatMetric(prop.confidence) }}
                                        </div>
                                    </div>
                                    <div>
                                        <div
                                            class="text-xs tracking-[0.16em] text-slate-500 uppercase"
                                        >
                                            Edge
                                        </div>
                                        <div
                                            class="mt-1 font-medium text-slate-100"
                                        >
                                            {{ formatMetric(prop.edge) }}
                                        </div>
                                    </div>
                                    <div>
                                        <div
                                            class="text-xs tracking-[0.16em] text-slate-500 uppercase"
                                        >
                                            Odds
                                        </div>
                                        <div
                                            class="mt-1 font-medium text-slate-100"
                                        >
                                            {{
                                                prop.odds > 0
                                                    ? `+${prop.odds}`
                                                    : prop.odds
                                            }}
                                        </div>
                                    </div>
                                </div>
                                <div
                                    v-if="coverageBreakdown(prop)"
                                    class="mt-4 rounded-2xl border border-white/10 bg-slate-950/45 p-3"
                                >
                                    <div
                                        class="flex items-center justify-between gap-3 text-xs tracking-[0.16em] text-slate-400 uppercase"
                                    >
                                        <span>{{
                                            coverageBreakdown(prop)?.label
                                        }}</span>
                                        <span
                                            >{{
                                                coverageBreakdown(prop)?.hits
                                            }}/{{
                                                coverageBreakdown(prop)?.games
                                            }}</span
                                        >
                                    </div>
                                    <div
                                        class="mt-3 flex h-3 overflow-hidden rounded-full bg-white/10"
                                    >
                                        <div
                                            class="bg-emerald-400"
                                            :style="{
                                                width: `${coverageBreakdown(prop)?.hitPct ?? 0}%`,
                                            }"
                                        />
                                        <div
                                            class="bg-rose-400"
                                            :style="{
                                                width: `${100 - (coverageBreakdown(prop)?.hitPct ?? 0)}%`,
                                            }"
                                        />
                                    </div>
                                    <div
                                        class="mt-2 flex items-center justify-between text-xs text-slate-400"
                                    >
                                        <span class="text-emerald-300"
                                            >Covered
                                            {{
                                                coverageBreakdown(prop)?.hits
                                            }}</span
                                        >
                                        <span class="text-rose-300"
                                            >Missed
                                            {{
                                                coverageBreakdown(prop)?.misses
                                            }}</span
                                        >
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </section>

            <section
                class="mt-6 overflow-hidden rounded-[2rem] border border-white/10 bg-gradient-to-r from-slate-950/80 via-slate-900/60 to-slate-950/80 p-6"
            >
                <div
                    class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr] lg:items-center"
                >
                    <div>
                        <div
                            class="text-xs font-semibold tracking-[0.22em] text-amber-300 uppercase"
                        >
                            Unlock More Than The Teaser
                        </div>
                        <h2
                            class="mt-3 text-2xl font-semibold text-white sm:text-3xl"
                        >
                            Visitors should understand the edge before they hit
                            register.
                        </h2>
                        <p
                            class="mt-3 max-w-2xl text-sm leading-7 text-slate-300"
                        >
                            This page shows enough to build trust. Registration
                            opens the full prediction archive, player pages,
                            team pages, prop workflows, and deeper filtering.
                        </p>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div
                            class="rounded-2xl border border-white/10 bg-white/[0.04] p-4 text-sm text-slate-200"
                        >
                            Featured plays and live market context above the
                            fold
                        </div>
                        <div
                            class="rounded-2xl border border-white/10 bg-white/[0.04] p-4 text-sm text-slate-200"
                        >
                            Full-game, team, and player workflows behind the
                            account wall
                        </div>
                        <Link
                            v-if="!$page.props.auth.user"
                            :href="register()"
                            class="sm:col-span-2"
                        >
                            <Button
                                size="lg"
                                class="h-12 w-full bg-amber-400 text-base font-semibold text-slate-950 hover:bg-amber-300"
                            >
                                Create Free Account
                            </Button>
                        </Link>
                    </div>
                </div>
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <Card
                    class="overflow-hidden border-white/10 bg-white/5 text-slate-50 shadow-[0_20px_60px_-35px_rgba(239,68,68,0.35)]"
                >
                    <CardHeader>
                        <CardTitle
                            class="flex items-center justify-between gap-3"
                        >
                            <span>Top Injuries</span>
                            <span
                                class="rounded-full border border-rose-400/20 bg-rose-400/10 px-3 py-1 text-xs font-semibold tracking-[0.18em] text-rose-200 uppercase"
                                >Line Movers</span
                            >
                        </CardTitle>
                        <CardDescription class="text-slate-400"
                            >Most impactful active injuries right
                            now.</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div
                            v-if="injuries.top.length === 0"
                            class="text-sm text-slate-400"
                        >
                            No active injuries available.
                        </div>
                        <div
                            v-for="injury in injuries.top"
                            :key="injury.id"
                            class="rounded-2xl border border-white/10 bg-gradient-to-r from-rose-500/10 to-transparent p-4"
                        >
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <div>
                                    <div class="font-medium text-white">
                                        {{ injury.player_name }}
                                    </div>
                                    <div class="mt-1 text-sm text-slate-400">
                                        {{ injury.team_abbreviation }}
                                        <span v-if="injury.detail"
                                            >· {{ injury.detail }}</span
                                        >
                                    </div>
                                </div>
                                <Badge
                                    :variant="impactTone(injury.impact_label)"
                                    >{{ injury.impact_label ?? 'Low' }}</Badge
                                >
                            </div>
                            <div
                                class="mt-3 flex flex-wrap gap-2 text-xs text-slate-400"
                            >
                                <span>{{ injury.status || 'Status TBD' }}</span>
                                <span v-if="injury.type"
                                    >· {{ injury.type }}</span
                                >
                                <span
                                    v-if="
                                        injury.impact_score !== null &&
                                        injury.impact_score !== undefined
                                    "
                                    >· Impact
                                    {{
                                        formatMetric(injury.impact_score)
                                    }}</span
                                >
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card
                    class="overflow-hidden border-white/10 bg-white/5 text-slate-50 shadow-[0_20px_60px_-35px_rgba(56,189,248,0.35)]"
                >
                    <CardHeader>
                        <CardTitle
                            class="flex items-center justify-between gap-3"
                        >
                            <span>Recent Injury Updates</span>
                            <span
                                class="rounded-full border border-sky-400/20 bg-sky-400/10 px-3 py-1 text-xs font-semibold tracking-[0.18em] text-sky-200 uppercase"
                                >Fresh Feed</span
                            >
                        </CardTitle>
                        <CardDescription class="text-slate-400"
                            >Newest active injury reports and
                            refreshes.</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div
                            v-if="injuries.recent.length === 0"
                            class="text-sm text-slate-400"
                        >
                            No recent injury updates available.
                        </div>
                        <div
                            v-for="injury in injuries.recent"
                            :key="injury.id"
                            class="rounded-2xl border border-white/10 bg-gradient-to-r from-sky-500/10 to-transparent p-4"
                        >
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <div>
                                    <div class="font-medium text-white">
                                        {{ injury.player_name }}
                                    </div>
                                    <div class="mt-1 text-sm text-slate-400">
                                        {{ injury.team_abbreviation }}
                                        <span v-if="injury.status"
                                            >· {{ injury.status }}</span
                                        >
                                    </div>
                                </div>
                                <div class="text-xs text-slate-400">
                                    {{
                                        formatGameDate(
                                            injury.source_updated_at ||
                                                injury.updated_at,
                                        )
                                    }}
                                </div>
                            </div>
                            <div
                                v-if="injury.detail"
                                class="mt-3 text-sm text-slate-300"
                            >
                                {{ injury.detail }}
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div class="mt-6 grid gap-6 xl:grid-cols-[1.15fr_0.85fr]">
                <Card
                    v-if="sport === 'nba' && conferencePlayoffTeams"
                    class="border-white/10 bg-white/5 text-slate-50 xl:col-span-2"
                >
                    <CardHeader>
                        <CardTitle
                            >Current Playoff Teams By Conference</CardTitle
                        >
                        <CardDescription class="text-slate-400"
                            >Latest projected East and West playoff field from
                            the NBA forecast table.</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="grid gap-6 lg:grid-cols-2">
                        <div>
                            <div
                                class="mb-3 text-sm font-semibold tracking-[0.2em] text-amber-300 uppercase"
                            >
                                Eastern Conference
                            </div>
                            <div class="space-y-3">
                                <div
                                    v-if="
                                        conferencePlayoffTeams.east.length === 0
                                    "
                                    class="text-sm text-slate-400"
                                >
                                    No Eastern Conference playoff data
                                    available.
                                </div>
                                <div
                                    v-for="team in conferencePlayoffTeams.east"
                                    :key="`east-${team.team_id}`"
                                    class="flex items-center gap-3 rounded-2xl border border-white/10 bg-slate-950/40 p-3"
                                >
                                    <div
                                        class="flex size-10 items-center justify-center rounded-full bg-amber-400/15 text-sm font-semibold text-amber-200"
                                    >
                                        {{ team.seed }}
                                    </div>
                                    <img
                                        v-if="team.logo"
                                        :src="team.logo"
                                        :alt="team.name"
                                        class="size-10 rounded-full bg-white/5 object-contain p-1"
                                    />
                                    <div
                                        v-else
                                        class="flex size-10 items-center justify-center rounded-full bg-white/10 text-xs font-semibold text-slate-300"
                                    >
                                        {{
                                            team.abbreviation ||
                                            team.name.slice(0, 3).toUpperCase()
                                        }}
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="truncate font-medium text-white"
                                        >
                                            {{ team.name }}
                                        </div>
                                        <div
                                            class="mt-1 text-xs text-slate-400"
                                        >
                                            Direct
                                            {{
                                                formatProbability(
                                                    team.direct_playoff_probability,
                                                )
                                            }}
                                            · Make
                                            {{
                                                formatProbability(
                                                    team.playoff_make_probability,
                                                )
                                            }}
                                        </div>
                                        <div
                                            class="mt-3 h-2 overflow-hidden rounded-full bg-white/10"
                                        >
                                            <div
                                                class="h-full rounded-full bg-gradient-to-r from-amber-300 to-emerald-400"
                                                :style="{
                                                    width:
                                                        formatProbability(
                                                            team.direct_playoff_probability,
                                                        ) !== 'N/A'
                                                            ? `${(team.direct_playoff_probability ?? 0) * 100}%`
                                                            : '0%',
                                                }"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <div
                                class="mb-3 text-sm font-semibold tracking-[0.2em] text-amber-300 uppercase"
                            >
                                Western Conference
                            </div>
                            <div class="space-y-3">
                                <div
                                    v-if="
                                        conferencePlayoffTeams.west.length === 0
                                    "
                                    class="text-sm text-slate-400"
                                >
                                    No Western Conference playoff data
                                    available.
                                </div>
                                <div
                                    v-for="team in conferencePlayoffTeams.west"
                                    :key="`west-${team.team_id}`"
                                    class="flex items-center gap-3 rounded-2xl border border-white/10 bg-slate-950/40 p-3"
                                >
                                    <div
                                        class="flex size-10 items-center justify-center rounded-full bg-amber-400/15 text-sm font-semibold text-amber-200"
                                    >
                                        {{ team.seed }}
                                    </div>
                                    <img
                                        v-if="team.logo"
                                        :src="team.logo"
                                        :alt="team.name"
                                        class="size-10 rounded-full bg-white/5 object-contain p-1"
                                    />
                                    <div
                                        v-else
                                        class="flex size-10 items-center justify-center rounded-full bg-white/10 text-xs font-semibold text-slate-300"
                                    >
                                        {{
                                            team.abbreviation ||
                                            team.name.slice(0, 3).toUpperCase()
                                        }}
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="truncate font-medium text-white"
                                        >
                                            {{ team.name }}
                                        </div>
                                        <div
                                            class="mt-1 text-xs text-slate-400"
                                        >
                                            Direct
                                            {{
                                                formatProbability(
                                                    team.direct_playoff_probability,
                                                )
                                            }}
                                            · Make
                                            {{
                                                formatProbability(
                                                    team.playoff_make_probability,
                                                )
                                            }}
                                        </div>
                                        <div
                                            class="mt-3 h-2 overflow-hidden rounded-full bg-white/10"
                                        >
                                            <div
                                                class="h-full rounded-full bg-gradient-to-r from-sky-300 to-cyan-400"
                                                :style="{
                                                    width:
                                                        formatProbability(
                                                            team.direct_playoff_probability,
                                                        ) !== 'N/A'
                                                            ? `${(team.direct_playoff_probability ?? 0) * 100}%`
                                                            : '0%',
                                                }"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card class="border-white/10 bg-white/5 text-slate-50">
                    <CardHeader>
                        <CardTitle
                            class="flex items-center justify-between gap-3"
                        >
                            <span>Top 25 Teams</span>
                            <span
                                class="rounded-full border border-amber-400/20 bg-amber-400/10 px-3 py-1 text-xs font-semibold tracking-[0.18em] text-amber-200 uppercase"
                                >Power Map</span
                            >
                        </CardTitle>
                        <CardDescription class="text-slate-400"
                            >Best current team metric snapshot for
                            {{ sportLabel }}.</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <div
                            v-if="topTeams.length === 0"
                            class="text-sm text-slate-400"
                        >
                            No team metrics available.
                        </div>
                        <div v-else class="grid gap-3 md:grid-cols-2">
                            <div
                                v-for="team in topTeams"
                                :key="`${team.rank}-${team.name}`"
                                class="flex items-center gap-4 rounded-2xl border border-white/10 bg-gradient-to-br from-white/[0.08] to-slate-950/50 p-4 transition hover:border-amber-300/30 hover:bg-white/[0.09]"
                            >
                                <div
                                    class="flex size-10 shrink-0 items-center justify-center rounded-full bg-amber-400/15 text-sm font-semibold text-amber-200"
                                >
                                    {{ team.rank }}
                                </div>
                                <img
                                    v-if="team.logo"
                                    :src="team.logo"
                                    :alt="team.name"
                                    class="size-10 rounded-full bg-white/5 object-contain p-1"
                                />
                                <div
                                    v-else
                                    class="flex size-10 items-center justify-center rounded-full bg-white/10 text-xs font-semibold text-slate-300"
                                >
                                    {{
                                        team.abbreviation ||
                                        team.name.slice(0, 3).toUpperCase()
                                    }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="truncate font-medium text-white"
                                    >
                                        {{ team.name }}
                                    </div>
                                    <div class="mt-1 text-xs text-slate-400">
                                        {{ team.primary_metric_label }}
                                        {{
                                            formatMetric(
                                                team.primary_metric_value,
                                            )
                                        }}
                                        <span v-if="team.record"
                                            >· {{ team.record }}</span
                                        >
                                    </div>
                                    <div
                                        class="mt-3 h-1.5 overflow-hidden rounded-full bg-white/10"
                                    >
                                        <div
                                            class="h-full rounded-full bg-gradient-to-r from-amber-300 via-orange-300 to-rose-300"
                                            :style="{
                                                width: `${Math.min(100, Math.max(12, 100 - (team.rank - 1) * 3.5))}%`,
                                            }"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card class="border-white/10 bg-white/5 text-slate-50">
                    <CardHeader>
                        <CardTitle
                            class="flex items-center justify-between gap-3"
                        >
                            <span>Top Players</span>
                            <span
                                class="rounded-full border border-emerald-400/20 bg-emerald-400/10 px-3 py-1 text-xs font-semibold tracking-[0.18em] text-emerald-200 uppercase"
                                >Scoring Leaders</span
                            >
                        </CardTitle>
                        <CardDescription class="text-slate-400"
                            >Public player leaders ranked by points per
                            game.</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div
                            v-if="topPlayers.length === 0"
                            class="text-sm text-slate-400"
                        >
                            No player leaderboard data available.
                        </div>
                        <div
                            v-for="player in topPlayers"
                            :key="`${player.player_id}-${player.name}`"
                            class="flex items-center gap-3 rounded-2xl border border-white/10 bg-gradient-to-r from-emerald-400/10 to-transparent p-3"
                        >
                            <img
                                v-if="player.headshot"
                                :src="player.headshot"
                                :alt="player.name"
                                class="size-12 rounded-full object-cover"
                            />
                            <div
                                v-else
                                class="flex size-12 items-center justify-center rounded-full bg-white/10 text-xs font-semibold text-slate-300"
                            >
                                {{ player.name.slice(0, 2).toUpperCase() }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium text-white">
                                    {{ player.name }}
                                </div>
                                <div class="mt-1 text-xs text-slate-400">
                                    {{ player.team_abbreviation || 'FA' }}
                                    <span v-if="player.position"
                                        >· {{ player.position }}</span
                                    >
                                    <span v-if="player.games_played"
                                        >· {{ player.games_played }} GP</span
                                    >
                                </div>
                            </div>
                            <div class="text-right">
                                <div
                                    class="text-xs tracking-[0.18em] text-slate-500 uppercase"
                                >
                                    {{ player.headline_stat_label }}
                                </div>
                                <div
                                    class="text-lg font-semibold text-amber-200"
                                >
                                    {{
                                        formatMetric(player.headline_stat_value)
                                    }}
                                </div>
                                <div class="mt-1 text-xs text-slate-500">
                                    per game
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <section
                class="mt-8 overflow-hidden rounded-[2rem] border border-amber-300/20 bg-gradient-to-r from-amber-300/12 via-white/[0.05] to-sky-400/10 p-6 sm:p-8"
            >
                <div
                    class="grid gap-6 lg:grid-cols-[1.2fr_0.8fr] lg:items-center"
                >
                    <div>
                        <div
                            class="text-xs font-semibold tracking-[0.22em] text-amber-300 uppercase"
                        >
                            Unlock The Full Experience
                        </div>
                        <h2
                            class="mt-3 text-2xl font-semibold text-white sm:text-4xl"
                        >
                            See the complete board, not just the teaser.
                        </h2>
                        <p
                            class="mt-3 max-w-2xl text-sm leading-7 text-slate-300"
                        >
                            Register to unlock full prediction pages, deeper
                            team and player views, more filtering, and the
                            workflows built for actual daily decision-making.
                        </p>
                    </div>
                    <div class="grid gap-3">
                        <div
                            class="rounded-2xl border border-white/10 bg-slate-950/45 p-4 text-sm text-slate-200"
                        >
                            Full predictions archive, team pages, game pages,
                            and prop workflows
                        </div>
                        <div
                            class="rounded-2xl border border-white/10 bg-slate-950/45 p-4 text-sm text-slate-200"
                        >
                            Better filtering, deeper context, and a faster path
                            from signal to pick
                        </div>
                        <div class="flex flex-col gap-3 sm:flex-row">
                            <Link
                                v-if="!$page.props.auth.user"
                                :href="register()"
                                class="flex-1"
                            >
                                <Button
                                    size="lg"
                                    class="h-12 w-full bg-amber-400 text-base font-semibold text-slate-950 hover:bg-amber-300"
                                >
                                    Create Free Account
                                </Button>
                            </Link>
                            <Link
                                v-if="!$page.props.auth.user"
                                :href="login()"
                                class="flex-1"
                            >
                                <Button
                                    size="lg"
                                    variant="ghost"
                                    class="h-12 w-full border border-white/15 bg-white/5 text-base text-slate-100 hover:bg-white/10"
                                >
                                    Sign In
                                </Button>
                            </Link>
                            <Link v-else :href="dashboard()" class="flex-1">
                                <Button
                                    size="lg"
                                    class="h-12 w-full bg-amber-400 text-base font-semibold text-slate-950 hover:bg-amber-300"
                                >
                                    Open Dashboard
                                </Button>
                            </Link>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>
