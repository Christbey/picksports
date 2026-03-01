import { router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, type ComputedRef } from 'vue';
import type { DashboardPrediction, DashboardSport } from '@/types';

interface DashboardPollingOptions {
    sports: ComputedRef<DashboardSport[]>;
    reloadOnly?: string[];
    livePollIntervalMs?: number;
    idlePollIntervalMs?: number;
}

export function useDashboardPolling(options: DashboardPollingOptions) {
    const livePollIntervalMs = options.livePollIntervalMs ?? 30_000;
    const idlePollIntervalMs = options.idlePollIntervalMs ?? 300_000;
    const reloadOnly = options.reloadOnly ?? ['sports', 'stats'];

    const hasLiveGames = computed(() =>
        options.sports.value.some((sport) =>
            sport.predictions.some((prediction) => prediction.is_live),
        ),
    );

    let pollTimer: ReturnType<typeof setTimeout> | null = null;
    let isMounted = false;
    let isReloading = false;

    function stopPolling(): void {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function reloadDashboardData(onFinish?: () => void): void {
        if (isReloading || !isMounted) return;

        isReloading = true;
        router.reload({
            only: reloadOnly,
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
        const interval = hasLiveGames.value
            ? livePollIntervalMs
            : idlePollIntervalMs;

        pollTimer = setTimeout(() => {
            if (!isMounted) return;

            if (document.hidden) {
                schedulePoll();
                return;
            }

            reloadDashboardData(() => schedulePoll());
        }, interval);
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

    return {
        hasLiveGames,
        reloadDashboardData,
    };
}

const SPORT_HEADER_GRADIENTS: Record<string, string> = {
    orange: 'from-orange-500 to-orange-700',
    blue: 'from-blue-500 to-blue-700',
    purple: 'from-purple-500 to-purple-700',
    green: 'from-green-500 to-green-700',
};

export function useDashboardPresentation() {
    function getSportHeaderColor(color: string): string {
        return SPORT_HEADER_GRADIENTS[color] ?? 'from-gray-500 to-gray-700';
    }

    function formatSpread(spread: number): string {
        return spread > 0 ? `+${spread.toFixed(1)}` : spread.toFixed(1);
    }

    function formatTotal(total: number | null | undefined): string {
        if (total === null || total === undefined) return '-';
        return total.toFixed(1);
    }

    function getGameUrl(sport: string, gameId: number): string {
        return `/${sport.toLowerCase()}/games/${gameId}`;
    }

    function hasLiveData(prediction: DashboardPrediction): boolean {
        return !!prediction.is_live && prediction.live_win_probability != null;
    }

    function buildLivePredictionData(prediction: DashboardPrediction) {
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

    return {
        getSportHeaderColor,
        formatSpread,
        formatTotal,
        getGameUrl,
        hasLiveData,
        buildLivePredictionData,
    };
}
