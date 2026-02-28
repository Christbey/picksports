<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted } from 'vue';
import BettingAnalysisCard, { type LivePredictionData, type BettingRecommendation } from '@/components/BettingAnalysisCard.vue';
import RenderErrorBoundary from '@/components/RenderErrorBoundary.vue';
import SubscriptionBanner from '@/components/SubscriptionBanner.vue';
import UpgradeCard from '@/components/UpgradeCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';

interface Prediction {
    sport: string;
    game_id: number;
    game: string;
    game_time: string;
    home_team: string;
    away_team: string;
    win_probability: number;
    predicted_spread: number;
    predicted_total: number;
    home_logo: string;
    away_logo: string;
    betting_value?: BettingRecommendation[];
    // Live game data
    is_live?: boolean;
    is_final?: boolean;
    home_score?: number;
    away_score?: number;
    period?: number;
    inning?: number;
    game_clock?: string;
    inning_state?: string;
    status?: string;
    // Live prediction data
    live_win_probability?: number;
    live_predicted_spread?: number;
    live_predicted_total?: number;
    live_seconds_remaining?: number;
    live_outs_remaining?: number;
}

interface Sport {
    name: string;
    fullName: string;
    color: string;
    predictions: Prediction[];
}

interface Stats {
    total_predictions_today: number;
    total_games_today: number;
    healthcheck_status: string;
}

const props = defineProps<{
    sports: Sport[];
    stats: Stats;
}>();

// Adaptive polling: 30s when live games are active, 5min when idle
const LIVE_POLL_INTERVAL = 30_000;
const IDLE_POLL_INTERVAL = 300_000;

const hasLiveGames = computed(() =>
    props.sports.some(sport => sport.predictions.some(p => p.is_live)),
);

let pollTimer: ReturnType<typeof setTimeout> | null = null;
let isMounted = false;
let isReloading = false;

function reloadDashboardData(onFinish?: () => void): void {
    if (isReloading || !isMounted) return;

    isReloading = true;
    router.reload({
        only: ['sports', 'stats'],
        onFinish: () => {
            isReloading = false;
            if (!isMounted) return;
            onFinish?.();
        },
    });
}

function schedulePoll(): void {
    if (!isMounted) return;

    stopPolling();
    const interval = hasLiveGames.value ? LIVE_POLL_INTERVAL : IDLE_POLL_INTERVAL;
    pollTimer = setTimeout(() => {
        if (!isMounted) return;

        if (document.hidden) {
            schedulePoll();
            return;
        }

        reloadDashboardData(() => schedulePoll());
    }, interval);
}

function stopPolling(): void {
    if (pollTimer) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
}

function handleVisibilityChange(): void {
    if (!document.hidden) {
        stopPolling();
        reloadDashboardData(() => schedulePoll());
    }
}

onMounted(() => {
    isMounted = true;
    schedulePoll();
    document.addEventListener('visibilitychange', handleVisibilityChange);
});

onUnmounted(() => {
    isMounted = false;
    stopPolling();
    document.removeEventListener('visibilitychange', handleVisibilityChange);
});

function getSportHeaderColor(color: string) {
    const colors: Record<string, string> = {
        orange: 'from-orange-500 to-orange-700',
        blue: 'from-blue-500 to-blue-700',
        purple: 'from-purple-500 to-purple-700',
        green: 'from-green-500 to-green-700',
    };
    return colors[color] || 'from-gray-500 to-gray-700';
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

function formatSpread(spread: number) {
    return spread > 0 ? `+${spread.toFixed(1)}` : spread.toFixed(1);
}

function formatTotal(total: number | null | undefined): string {
    if (total === null || total === undefined) return '-';
    return total.toFixed(1);
}

function getGameUrl(sport: string, gameId: number) {
    const sportLower = sport.toLowerCase();
    return `/${sportLower}/games/${gameId}`;
}

function hasLiveData(prediction: Prediction): boolean {
    return !!prediction.is_live && prediction.live_win_probability != null;
}

function buildLivePredictionData(prediction: Prediction): LivePredictionData | undefined {
    if (!prediction.is_live) return undefined;
    return {
        isLive: true,
        homeScore: prediction.home_score,
        awayScore: prediction.away_score,
        period: prediction.period,
        inning: prediction.inning,
        gameClock: prediction.game_clock,
        inningState: prediction.inning_state,
        status: prediction.status,
        liveWinProbability: prediction.live_win_probability,
        livePredictedSpread: prediction.live_predicted_spread,
        livePredictedTotal: prediction.live_predicted_total,
        liveSecondsRemaining: prediction.live_seconds_remaining,
        liveOutsRemaining: prediction.live_outs_remaining,
        preGameWinProbability: prediction.win_probability,
        preGamePredictedSpread: prediction.predicted_spread,
        preGamePredictedTotal: prediction.predicted_total,
    };
}
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <RenderErrorBoundary title="Dashboard Render Error">
            <div
                class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
            >
                <!-- Subscription Banner -->
                <SubscriptionBanner variant="gradient" />

            <!-- No Predictions Message -->
            <div
                v-if="sports.length === 0"
                class="relative min-h-svh flex-1 rounded-xl border border-sidebar-border/70 bg-white p-6 md:min-h-min dark:border-sidebar-border dark:bg-sidebar"
            >
                <h2 class="mb-4 text-xl font-semibold">Today's Predictions</h2>
                <div class="py-12 text-center">
                    <p class="text-muted-foreground">
                        No predictions available for today
                    </p>
                </div>
            </div>

            <!-- Sport Sections -->
            <div v-else class="grid gap-6 lg:grid-cols-[1fr_300px]">
                <div class="space-y-6">
                <div
                    v-for="sport in sports"
                    :key="sport.name"
                    class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-sidebar"
                >
                    <!-- Sport Header -->
                    <div
                        class="flex items-center justify-between bg-gradient-to-r p-4"
                        :class="getSportHeaderColor(sport.color)"
                    >
                        <div>
                            <h2 class="text-xl font-bold text-white">
                                {{ sport.name }}
                            </h2>
                            <p class="text-sm text-white/80">
                                {{ sport.fullName }}
                            </p>
                        </div>
                        <div
                            class="rounded-full bg-white/20 px-3 py-1 text-sm font-medium text-white"
                        >
                            {{ sport.predictions.length }}
                            {{ sport.predictions.length === 1 ? 'game' : 'games' }}
                        </div>
                    </div>

                    <!-- Predictions List -->
                    <div class="space-y-3 p-4">
                        <Link
                            v-for="prediction in sport.predictions"
                            :key="`${prediction.sport}-${prediction.game_id}`"
                            :href="getGameUrl(prediction.sport, prediction.game_id)"
                            class="block rounded-lg border border-sidebar-border/70 bg-sidebar-accent/30 p-3 md:p-4 transition-all hover:border-sidebar-border hover:bg-sidebar-accent/50 dark:border-sidebar-border"
                        >
                            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                <!-- Game Info -->
                                <div class="flex flex-col gap-2">
                                    <!-- Live indicator -->
                                    <div
                                        v-if="prediction.is_live"
                                        class="flex items-center gap-1.5 rounded-full bg-red-100 px-2 py-0.5 self-start dark:bg-red-900/50"
                                    >
                                        <span
                                            class="h-2 w-2 animate-pulse rounded-full bg-red-500"
                                        ></span>
                                        <span
                                            class="text-xs font-semibold text-red-600 dark:text-red-400"
                                            >LIVE</span
                                        >
                                    </div>
                                    <!-- Final indicator -->
                                    <div
                                        v-else-if="prediction.is_final"
                                        class="flex items-center gap-1.5 rounded-full bg-gray-100 px-2 py-0.5 self-start dark:bg-gray-800"
                                    >
                                        <span
                                            class="text-xs font-semibold text-gray-600 dark:text-gray-400"
                                            >FINAL</span
                                        >
                                    </div>

                                    <!-- Teams -->
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
                                        <div class="flex items-center gap-2">
                                            <img
                                                :src="prediction.away_logo"
                                                :alt="prediction.away_team"
                                                class="h-8 w-8 md:h-10 md:w-10 object-contain"
                                            />
                                            <span class="text-sm md:text-base font-semibold">
                                                {{ prediction.away_team }}
                                            </span>
                                            <span
                                                v-if="prediction.is_live || prediction.is_final"
                                                class="text-base md:text-lg font-bold ml-auto"
                                            >
                                                {{ prediction.away_score }}
                                            </span>
                                        </div>
                                        <span class="hidden sm:inline text-muted-foreground">@</span>
                                        <div class="flex items-center gap-2">
                                            <img
                                                :src="prediction.home_logo"
                                                :alt="prediction.home_team"
                                                class="h-8 w-8 md:h-10 md:w-10 object-contain"
                                            />
                                            <span class="text-sm md:text-base font-semibold">
                                                {{ prediction.home_team }}
                                            </span>
                                            <span
                                                v-if="prediction.is_live || prediction.is_final"
                                                class="text-base md:text-lg font-bold ml-auto"
                                            >
                                                {{ prediction.home_score }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Prediction Stats -->
                                <div class="grid grid-cols-3 gap-2 md:gap-4 md:flex md:items-center md:gap-6">
                                    <div class="text-center">
                                        <div class="text-xs text-muted-foreground">
                                            {{
                                                hasLiveData(prediction)
                                                    ? 'Live Prob'
                                                    : 'Win Prob'
                                            }}
                                        </div>
                                        <div
                                            class="text-sm md:text-base font-semibold"
                                            :class="{
                                                'text-red-500':
                                                    hasLiveData(prediction),
                                            }"
                                        >
                                            {{
                                                (
                                                    (hasLiveData(prediction)
                                                        ? prediction.live_win_probability!
                                                        : prediction.win_probability) *
                                                    100
                                                ).toFixed(1)
                                            }}%
                                        </div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-xs text-muted-foreground">
                                            {{
                                                hasLiveData(prediction)
                                                    ? 'Live Spread'
                                                    : 'Spread'
                                            }}
                                        </div>
                                        <div
                                            class="text-sm md:text-base font-semibold"
                                            :class="{
                                                'text-red-500':
                                                    hasLiveData(prediction),
                                            }"
                                        >
                                            {{
                                                formatSpread(
                                                    hasLiveData(prediction) &&
                                                        prediction.live_predicted_spread !=
                                                            null
                                                        ? prediction.live_predicted_spread
                                                        : prediction.predicted_spread,
                                                )
                                            }}
                                        </div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-xs text-muted-foreground">
                                            {{
                                                hasLiveData(prediction)
                                                    ? 'Live Total'
                                                    : 'Total'
                                            }}
                                        </div>
                                        <div
                                            class="text-sm md:text-base font-semibold"
                                            :class="{
                                                'text-red-500':
                                                    hasLiveData(prediction),
                                            }"
                                        >
                                            {{
                                                formatTotal(
                                                    hasLiveData(prediction) &&
                                                        prediction.live_predicted_total !=
                                                            null
                                                        ? prediction.live_predicted_total
                                                        : prediction.predicted_total,
                                                )
                                            }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Betting Recommendations & Live Predictions -->
                            <div
                                v-if="
                                    (prediction.betting_value &&
                                        prediction.betting_value.length > 0) ||
                                    hasLiveData(prediction)
                                "
                                class="mt-4 border-t border-sidebar-border/70 pt-4"
                            >
                                <div class="mb-2 flex items-center gap-2">
                                    <div class="text-sm font-medium">
                                        {{
                                            hasLiveData(prediction)
                                                ? 'Live Analysis'
                                                : 'Betting Value Detected'
                                        }}
                                    </div>
                                    <div
                                        v-if="
                                            !hasLiveData(prediction) &&
                                            prediction.betting_value?.length
                                        "
                                        class="text-xs text-muted-foreground"
                                    >
                                        (DraftKings)
                                    </div>
                                </div>
                                <BettingAnalysisCard
                                    :betting-value="prediction.betting_value"
                                    :live-prediction="buildLivePredictionData(prediction)"
                                />
                            </div>
                        </Link>
                    </div>
                </div>
                </div>

                <!-- Sidebar with Upgrade Card -->
                <div class="hidden lg:block">
                    <div class="sticky top-4">
                        <UpgradeCard
                            :features="[
                                'Unlimited daily predictions',
                                'All sports coverage',
                                'Advanced betting analytics',
                                'Priority support',
                            ]"
                        />
                    </div>
                </div>
            </div>
            </div>
        </RenderErrorBoundary>
    </AppLayout>
</template>
