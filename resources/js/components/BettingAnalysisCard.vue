<script setup lang="ts">
export interface BettingRecommendation {
    type: 'spread' | 'total' | 'moneyline';
    recommendation: string;
    bet_team?: string;
    model_line?: number;
    market_line?: number;
    model_probability?: number;
    implied_probability?: number;
    edge: number;
    odds: number;
    kelly_bet_size_percent?: number;
    confidence: number;
    reasoning: string;
}

export interface LivePredictionData {
    isLive: boolean;
    homeScore?: number | null;
    awayScore?: number | null;
    period?: number | null;
    inning?: number | null;
    gameClock?: string | null;
    inningState?: string | null;
    status?: string | null;
    liveWinProbability?: number | null;
    livePredictedSpread?: number | null;
    livePredictedTotal?: number | null;
    liveSecondsRemaining?: number | null;
    liveOutsRemaining?: number | null;
    preGameWinProbability: number;
    preGamePredictedSpread: number;
    preGamePredictedTotal: number;
}

import { computed } from 'vue';

const props = defineProps<{
    bettingValue?: BettingRecommendation[];
    livePrediction?: LivePredictionData;
    showDraftKingsLabel?: boolean;
}>();

const hasLivePredictionData = computed(() => {
    if (!props.livePrediction?.isLive) return false;
    return props.livePrediction.liveWinProbability !== null
        && props.livePrediction.liveWinProbability !== undefined;
});

function formatSpread(spread: number | string | null | undefined): string {
    if (spread === null || spread === undefined) return '-';
    const numSpread = typeof spread === 'string' ? parseFloat(spread) : spread;
    if (isNaN(numSpread)) return '-';
    return numSpread > 0 ? `+${numSpread.toFixed(1)}` : numSpread.toFixed(1);
}

function formatNumber(value: number | string | null | undefined, decimals = 1): string {
    if (value === null || value === undefined) return '-';
    const numValue = typeof value === 'string' ? parseFloat(value) : value;
    if (isNaN(numValue)) return '-';
    return numValue.toFixed(decimals);
}

function formatOdds(odds: number): string {
    return odds > 0 ? `+${odds}` : odds.toString();
}

function formatPeriod(period: number | null | undefined, status: string | null | undefined): string {
    if (!period) return '';
    if (status === 'STATUS_HALFTIME') return 'Half';
    if (status === 'STATUS_END_PERIOD') return `End Q${period}`;
    const ordinals = ['', '1st', '2nd', '3rd', '4th', 'OT'];
    return ordinals[period] || `OT${period - 4}`;
}

function formatInning(inning: number | null | undefined, inningState: string | null | undefined): string {
    if (!inning) return '';
    const state = inningState === 'top' ? 'Top' : inningState === 'bottom' ? 'Bot' : '';
    return `${state} ${inning}`;
}

function formatOutsRemaining(outs: number | null | undefined): string {
    if (outs === null || outs === undefined) return '-';
    const innings = Math.floor(outs / 6);
    const remainingOuts = outs % 6;
    if (innings === 0) return `${remainingOuts} outs remaining`;
    return `${innings}.${remainingOuts} innings remaining`;
}

function formatTimeRemaining(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined) return '-';
    const minutes = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${minutes}:${secs.toString().padStart(2, '0')}`;
}

function getBetTypeLabel(type: string): string {
    const labels: Record<string, string> = {
        spread: 'Spread',
        total: 'Total',
        moneyline: 'Moneyline',
    };
    return labels[type] || type;
}

function getBetTypeColor(type: string): string {
    const colors: Record<string, string> = {
        spread: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        total: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
        moneyline: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
    };
    return colors[type] || 'bg-gray-100 text-gray-800';
}
</script>

<template>
    <div class="space-y-3">
        <!-- Live Prediction Card (only when actual live data exists) -->
        <div
            v-if="hasLivePredictionData"
            class="rounded-md border border-red-200 bg-red-50/50 p-3 dark:border-red-900 dark:bg-red-950/30"
        >
            <div class="flex items-start justify-between gap-2">
                <div class="flex-1 space-y-1">
                    <div class="flex items-center gap-2">
                        <span
                            class="flex items-center gap-1 rounded bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900 dark:text-red-200"
                        >
                            <span
                                class="h-1.5 w-1.5 animate-pulse rounded-full bg-red-500"
                            ></span>
                            Live Prediction
                        </span>
                        <span class="text-sm font-semibold">
                            {{ livePrediction.homeScore }}-{{ livePrediction.awayScore }}
                            <template v-if="livePrediction.liveSecondsRemaining !== null && livePrediction.liveSecondsRemaining !== undefined">
                                <span class="font-normal text-muted-foreground">
                                    • {{ formatTimeRemaining(livePrediction.liveSecondsRemaining) }} remaining
                                </span>
                            </template>
                            <template v-else-if="livePrediction.liveOutsRemaining !== null && livePrediction.liveOutsRemaining !== undefined">
                                <span class="font-normal text-muted-foreground">
                                    • {{ formatOutsRemaining(livePrediction.liveOutsRemaining) }}
                                </span>
                            </template>
                            <template v-else-if="livePrediction.inning && livePrediction.inningState">
                                •
                                {{ formatInning(livePrediction.inning, livePrediction.inningState) }}
                            </template>
                            <template v-else-if="livePrediction.period || livePrediction.gameClock">
                                •
                                {{ formatPeriod(livePrediction.period, livePrediction.status) }}
                                {{ livePrediction.gameClock }}
                            </template>
                        </span>
                    </div>
                    <div class="text-xs text-muted-foreground">
                        Real-time prediction updates based on current game state
                    </div>
                    <div class="flex flex-wrap gap-4 text-xs">
                        <div>
                            <span class="text-muted-foreground">Win Prob:</span>
                            <span class="ml-1 font-semibold text-red-600">
                                {{
                                    formatNumber(
                                        ((livePrediction.liveWinProbability ?? livePrediction.preGameWinProbability) as number) * 100,
                                        1
                                    )
                                }}%
                            </span>
                            <span class="text-muted-foreground">
                                (was {{ formatNumber(livePrediction.preGameWinProbability * 100, 1) }}%)
                            </span>
                        </div>
                        <div>
                            <span class="text-muted-foreground">Spread:</span>
                            <span class="ml-1 font-semibold text-red-600">
                                {{
                                    formatSpread(
                                        livePrediction.livePredictedSpread ?? livePrediction.preGamePredictedSpread
                                    )
                                }}
                            </span>
                            <span class="text-muted-foreground">
                                (was {{ formatSpread(livePrediction.preGamePredictedSpread) }})
                            </span>
                        </div>
                        <div>
                            <span class="text-muted-foreground">Total:</span>
                            <span class="ml-1 font-semibold text-red-600">
                                {{
                                    formatNumber(
                                        livePrediction.livePredictedTotal ?? livePrediction.preGamePredictedTotal
                                    )
                                }}
                            </span>
                            <span class="text-muted-foreground">
                                (was {{ formatNumber(livePrediction.preGamePredictedTotal) }})
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Betting Value Cards -->
        <div
            v-for="(bet, idx) in bettingValue"
            :key="idx"
            class="rounded-md border border-sidebar-border/70 bg-sidebar/50 p-3"
        >
            <div class="flex items-start justify-between gap-2">
                <div class="flex-1 space-y-1">
                    <div class="flex items-center gap-2">
                        <span
                            class="rounded px-2 py-0.5 text-xs font-medium"
                            :class="getBetTypeColor(bet.type)"
                        >
                            {{ getBetTypeLabel(bet.type) }}
                        </span>
                        <span class="text-sm font-semibold">
                            {{ bet.recommendation }}
                        </span>
                    </div>
                    <div class="text-xs text-muted-foreground">
                        {{ bet.reasoning }}
                    </div>
                    <div class="flex flex-wrap gap-4 text-xs">
                        <div v-if="bet.model_line !== undefined">
                            <span class="text-muted-foreground">Model:</span>
                            <span class="ml-1 font-medium">
                                {{ formatSpread(bet.model_line) }}
                            </span>
                        </div>
                        <div v-if="bet.market_line !== undefined">
                            <span class="text-muted-foreground">Market:</span>
                            <span class="ml-1 font-medium">
                                {{ formatSpread(bet.market_line) }}
                            </span>
                        </div>
                        <div v-if="bet.model_probability !== undefined">
                            <span class="text-muted-foreground">Model:</span>
                            <span class="ml-1 font-medium">
                                {{ bet.model_probability }}%
                            </span>
                        </div>
                        <div v-if="bet.implied_probability !== undefined">
                            <span class="text-muted-foreground">Implied:</span>
                            <span class="ml-1 font-medium">
                                {{ bet.implied_probability }}%
                            </span>
                        </div>
                        <div>
                            <span class="text-muted-foreground">Edge:</span>
                            <span class="ml-1 font-semibold text-green-600">
                                {{
                                    bet.type === 'moneyline'
                                        ? `${bet.edge}%`
                                        : `${bet.edge} pts`
                                }}
                            </span>
                        </div>
                        <div>
                            <span class="text-muted-foreground">Odds:</span>
                            <span class="ml-1 font-medium">
                                {{ formatOdds(bet.odds) }}
                            </span>
                        </div>
                        <div v-if="bet.kelly_bet_size_percent !== undefined">
                            <span class="text-muted-foreground">Kelly:</span>
                            <span class="ml-1 font-medium">
                                {{ bet.kelly_bet_size_percent }}%
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
