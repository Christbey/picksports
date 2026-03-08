<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Sparkles, Target } from 'lucide-vue-next';
import { ref } from 'vue';
import BettingAnalysisCard from '@/components/BettingAnalysisCard.vue';
import type { DashboardPrediction, PredictionListItem } from '@/types';

const props = defineProps<{
    prediction: DashboardPrediction | PredictionListItem;
    href: string;
    sport?: string;
}>();

const LIVE_STATUSES = new Set([
    'STATUS_IN_PROGRESS',
    'STATUS_HALFTIME',
    'STATUS_END_PERIOD',
]);

function isPredictionListItem(
    prediction: DashboardPrediction | PredictionListItem,
): prediction is PredictionListItem {
    return typeof (prediction as PredictionListItem).game === 'object';
}

const predictionType = isPredictionListItem(props.prediction)
    ? props.prediction
    : null;
const dashboardType = isPredictionListItem(props.prediction)
    ? null
    : props.prediction;
const awayLogoErrored = ref(false);
const homeLogoErrored = ref(false);

function awayTeamLabel(): string {
    if (dashboardType) return dashboardType.away_team;

    const team = predictionType?.game.away_team;
    return team?.abbreviation ?? team?.school ?? team?.name ?? 'Away';
}

function homeTeamLabel(): string {
    if (dashboardType) return dashboardType.home_team;

    const team = predictionType?.game.home_team;
    return team?.abbreviation ?? team?.school ?? team?.name ?? 'Home';
}

function awayLogo(): string | null {
    if (dashboardType) return dashboardType.away_logo;
    return predictionType?.game.away_team.logo ?? null;
}

function homeLogo(): string | null {
    if (dashboardType) return dashboardType.home_logo;
    return predictionType?.game.home_team.logo ?? null;
}

function gameStatus(): string | null {
    if (dashboardType) return dashboardType.status ?? null;
    return predictionType?.game.status ?? null;
}

function isLive(): boolean {
    if (dashboardType) return !!dashboardType.is_live;

    const status = predictionType?.game.status;
    const apiLive = predictionType?.game.live_win_probability?.is_live ?? false;

    return apiLive || (status ? LIVE_STATUSES.has(status) : false);
}

function isFinal(): boolean {
    if (dashboardType) return !!dashboardType.is_final;
    return predictionType?.game.status === 'STATUS_FINAL';
}

function awayScore(): number | null {
    if (dashboardType) return dashboardType.away_score ?? null;
    return predictionType?.game.away_score ?? null;
}

function homeScore(): number | null {
    if (dashboardType) return dashboardType.home_score ?? null;
    return predictionType?.game.home_score ?? null;
}

function period(): number | null {
    if (dashboardType) return dashboardType.period ?? null;
    return predictionType?.game.period ?? null;
}

function gameClock(): string | null {
    if (dashboardType) return dashboardType.game_clock ?? null;
    return predictionType?.game.clock ?? null;
}

function inning(): number | null {
    return dashboardType?.inning ?? null;
}

function inningState(): string | null {
    return dashboardType?.inning_state ?? null;
}

function showAwayLogo(): boolean {
    return !!awayLogo() && !awayLogoErrored.value;
}

function showHomeLogo(): boolean {
    return !!homeLogo() && !homeLogoErrored.value;
}

function handleAwayLogoError(): void {
    awayLogoErrored.value = true;
}

function handleHomeLogoError(): void {
    homeLogoErrored.value = true;
}

function isFavorite(): boolean {
    return preGameWinProbability() > 0.65;
}

function weekLabel(): string | null {
    const normalizedSport = (props.sport ?? '').toLowerCase();
    if (!['nfl', 'cfb'].includes(normalizedSport)) {
        return null;
    }

    const week = predictionType?.game.week;
    const seasonType = predictionType?.game.season_type;

    if (!week || !seasonType) {
        return null;
    }

    if (seasonType === 'Regular Season') {
        return `Week ${week}`;
    }

    if (normalizedSport === 'nfl') {
        const playoffRounds: Record<number, string> = {
            1: 'Wild Card',
            2: 'Divisional',
            3: 'Conference Championship',
            5: 'Super Bowl',
        };

        return playoffRounds[week] ?? `Postseason Week ${week}`;
    }

    if (normalizedSport === 'cfb') {
        const cfbRounds: Record<number, string> = {
            1: 'Bowl Games',
            2: 'Playoffs',
            3: 'Championship',
        };

        return cfbRounds[week] ?? `Postseason Week ${week}`;
    }

    return `Postseason Week ${week}`;
}

function preGameWinProbability(): number {
    return props.prediction.win_probability ?? 0;
}

function liveWinProbability(): number | null {
    if (dashboardType) return dashboardType.live_win_probability ?? null;
    return predictionType?.game.live_win_probability?.away_win_probability ?? null;
}

function hasLiveData(): boolean {
    return isLive() && liveWinProbability() !== null;
}

function winProbPercent(): number {
    const probability = hasLiveData()
        ? liveWinProbability() ?? preGameWinProbability()
        : preGameWinProbability();

    return Math.max(0, Math.min(100, probability * 100));
}

function edgePercent(): number {
    return winProbPercent() - 50;
}

function edgeSignalLabel(): string {
    const absEdge = Math.abs(edgePercent());
    if (absEdge < 2) return 'Toss-Up';
    if (absEdge < 7) return 'Lean Edge';
    if (absEdge < 14) return 'Moderate Edge';
    return 'Strong Edge';
}

function moneylineTeamLabel(): string {
    const home = homeTeamLabel();
    const away = awayTeamLabel();

    return winProbPercent() >= 50 ? home : away;
}

function edgeBarWidth(): number {
    return Math.min(100, Math.abs(edgePercent()) * 2);
}

function bettingValueDebug(): string | null {
    return dashboardType?.betting_value_debug ?? null;
}

function bettingValueDebugLabel(): string | null {
    const reason = bettingValueDebug();
    if (!reason) return null;

    if (reason.toLowerCase() === 'no odds') {
        return 'Odds not available';
    }

    if (reason.toLowerCase() === 'below threshold') {
        return 'Below signal threshold';
    }

    return reason;
}

function livePredictionData() {
    if (!isLive()) return undefined;

    const livePredictedSpread = dashboardType
        ? dashboardType.live_predicted_spread ?? null
        : null;
    const livePredictedTotal = dashboardType
        ? dashboardType.live_predicted_total ?? null
        : null;
    const liveSecondsRemaining = dashboardType
        ? dashboardType.live_seconds_remaining ?? null
        : null;
    const liveOutsRemaining = dashboardType
        ? dashboardType.live_outs_remaining ?? null
        : null;

    return {
        isLive: true,
        homeScore: homeScore(),
        awayScore: awayScore(),
        period: period(),
        inning: inning(),
        gameClock: gameClock(),
        inningState: inningState(),
        status: gameStatus(),
        liveWinProbability: liveWinProbability(),
        livePredictedSpread,
        livePredictedTotal,
        liveSecondsRemaining,
        liveOutsRemaining,
        preGameWinProbability: preGameWinProbability(),
        preGamePredictedSpread: props.prediction.predicted_spread ?? 0,
        preGamePredictedTotal: props.prediction.predicted_total ?? 0,
    };
}

function winnerCorrect(): boolean | null {
    const value = props.prediction.winner_correct;
    return typeof value === 'boolean' ? value : null;
}

function finalResultClass(): string {
    if (!isFinal()) {
        return '';
    }

    const correct = winnerCorrect();
    if (correct === true) {
        return 'text-green-600 dark:text-green-400';
    }

    if (correct === false) {
        return 'text-red-600 dark:text-red-400';
    }

    return '';
}

function finalResultBadgeClass(): string {
    const correct = winnerCorrect();
    if (correct === true) {
        return 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300';
    }

    if (correct === false) {
        return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300';
    }

    return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400';
}

function finalResultLabel(): string {
    const correct = winnerCorrect();
    if (correct === true) return 'WIN';
    if (correct === false) return 'LOSS';
    return 'FINAL';
}

function finalCardClass(): string {
    if (!isFinal()) return '';

    const correct = winnerCorrect();
    if (correct === true) return 'border-green-300/80 dark:border-green-700/60';
    if (correct === false) return 'border-red-300/80 dark:border-red-700/60';

    return '';
}
</script>

<template>
    <Link
        :href="href"
        class="ui-surface-subtle relative block p-3 transition-all duration-200 hover:-translate-y-0.5 hover:border-sidebar-border hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 md:p-4"
        :class="finalCardClass()"
    >
        <span
            v-if="isFavorite()"
            class="absolute left-3 top-3 rounded-full bg-green-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-green-700 dark:bg-green-900 dark:text-green-200"
        >
            Favorite
        </span>
        <div
            class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
            :class="isFavorite() ? 'pt-5' : ''"
        >
            <div class="flex flex-col gap-2">
                <div
                    v-if="isLive()"
                    class="flex items-center gap-1.5 self-start rounded-full bg-red-100 px-2 py-0.5 dark:bg-red-900/50"
                >
                    <span class="h-2 w-2 animate-pulse rounded-full bg-red-500"></span>
                    <span class="text-xs font-semibold text-red-600 dark:text-red-400">LIVE</span>
                </div>
                <div
                    v-else-if="isFinal()"
                    class="flex items-center gap-1.5 self-start rounded-full bg-gray-100 px-2 py-0.5 dark:bg-gray-800"
                >
                    <span class="text-xs font-semibold text-gray-600 dark:text-gray-400">FINAL</span>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-semibold"
                        :class="finalResultBadgeClass()"
                    >
                        {{ finalResultLabel() }}
                    </span>
                </div>

                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
                    <div class="flex items-center gap-2">
                        <img
                            v-if="showAwayLogo()"
                            :src="awayLogo()!"
                            :alt="awayTeamLabel()"
                            class="h-8 w-8 object-contain md:h-10 md:w-10"
                            @error="handleAwayLogoError"
                        />
                        <span class="text-sm font-semibold md:text-base">{{ awayTeamLabel() }}</span>
                        <span
                            v-if="isLive() || isFinal()"
                            class="ml-auto text-base font-bold md:text-lg"
                        >
                            {{ awayScore() ?? '-' }}
                        </span>
                    </div>
                    <span class="hidden text-muted-foreground sm:inline">@</span>
                    <div class="flex items-center gap-2">
                        <img
                            v-if="showHomeLogo()"
                            :src="homeLogo()!"
                            :alt="homeTeamLabel()"
                            class="h-8 w-8 object-contain md:h-10 md:w-10"
                            @error="handleHomeLogoError"
                        />
                        <span class="text-sm font-semibold md:text-base">{{ homeTeamLabel() }}</span>
                        <span
                            v-if="isLive() || isFinal()"
                            class="ml-auto text-base font-bold md:text-lg"
                        >
                            {{ homeScore() ?? '-' }}
                        </span>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span
                        v-if="weekLabel()"
                        class="ui-chip text-sidebar-foreground"
                    >
                        {{ weekLabel() }}
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-2 sm:grid-cols-1 md:min-w-[180px]">
                <div class="ui-surface-subtle p-2">
                    <div class="mb-1 flex items-center gap-1.5">
                        <Target class="h-3.5 w-3.5" />
                        <span class="ui-chip text-foreground/80">
                            Moneyline: {{ moneylineTeamLabel() }}
                        </span>
                    </div>
                    <div
                        class="text-base font-bold"
                        :class="[
                            edgePercent() >= 0
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : 'text-red-600 dark:text-red-400',
                            finalResultClass(),
                        ]"
                    >
                        {{ edgeSignalLabel() }}
                    </div>
                    <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-sidebar-accent">
                        <div
                            class="h-full rounded-full transition-all"
                            :class="edgePercent() >= 0 ? 'bg-emerald-500' : 'bg-red-500'"
                            :style="{ width: `${edgeBarWidth()}%` }"
                        />
                    </div>
                </div>
            </div>
        </div>

        <div
            v-if="(prediction.betting_value && prediction.betting_value.length > 0) || hasLiveData() || bettingValueDebug()"
            class="mt-4 border-t border-sidebar-border/70 pt-4"
        >
            <div class="mb-2 flex items-center gap-2">
                <div class="inline-flex items-center gap-1.5 rounded-full bg-sidebar-accent/70 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide">
                    <Sparkles class="h-3.5 w-3.5" />
                    {{ hasLiveData() ? 'Live Signals' : 'Value Signals' }}
                </div>
                <div
                    v-if="!hasLiveData() && prediction.betting_value?.length"
                    class="text-xs text-muted-foreground"
                >
                    Vegas
                </div>
            </div>
            <div
                v-if="!hasLiveData() && (!prediction.betting_value || prediction.betting_value.length === 0)"
                class="rounded-md border border-dashed border-sidebar-border/80 bg-sidebar/30 p-3 text-sm text-muted-foreground"
            >
                No qualifying value signal
                <span
                    v-if="bettingValueDebug()"
                    class="ml-2 inline-flex rounded-full bg-sidebar-accent px-2 py-0.5 text-xs font-medium text-foreground/80"
                >
                    {{ bettingValueDebugLabel() }}
                </span>
            </div>
            <BettingAnalysisCard
                v-else
                :betting-value="prediction.betting_value"
                :live-prediction="livePredictionData()"
                :winner-correct="isFinal() ? winnerCorrect() : null"
                :actual-total="isFinal() ? (prediction.actual_total ?? null) : null"
                :compact="true"
            />
        </div>
    </Link>
</template>
