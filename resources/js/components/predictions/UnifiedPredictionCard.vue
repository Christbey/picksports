<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown, ShieldCheck, Sparkles, Target } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import BettingAnalysisCard from '@/components/BettingAnalysisCard.vue';
import SavePickDialog from '@/components/predictions/SavePickDialog.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    buildPredictionLiveData,
    hasPredictionLiveData,
    isPredictionListItem,
    normalizePredictionLiveState,
} from '@/composables/usePredictionLiveData';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { getCfbPostseasonLabel } from '@/lib/cfbPostseason';
import {
    getPredictionRecommendation,
    isBetRecommendation,
    isLeanRecommendation,
    isLiveMonitor,
    pregameRecommendation,
} from '@/lib/predictionRecommendation';
import type { DashboardPrediction, PredictionListItem } from '@/types';

interface SavePickOption {
    betType: 'spread' | 'moneyline' | 'total_over' | 'total_under';
    selectionSide: 'home' | 'away' | 'over' | 'under';
    teamLabel: string;
    title: string;
    defaultLine: number | null;
}

interface TrackedBet {
    id: number;
    bet_amount: number;
    odds: string;
    bet_type: SavePickOption['betType'];
    selection_side: SavePickOption['selectionSide'] | null;
    selection_label: string | null;
    line: number | null;
    notes: string | null;
}

interface TrackingMarketSummary {
    total_picks: number;
    leader: {
        side: SavePickOption['selectionSide'];
        count: number;
        percent: number;
    } | null;
    sides: Partial<
        Record<
            SavePickOption['selectionSide'],
            {
                count: number;
                percent: number;
            }
        >
    >;
}

interface TrackingSummary {
    markets: Record<'moneyline' | 'spread' | 'total', TrackingMarketSummary>;
    user_bets: TrackedBet[];
}

interface UserBetsTrackingResponse {
    tracking: TrackingSummary | null;
}

interface PublicConsensus {
    summary: string;
    detail: string;
}

const trackingCache = new Map<string, TrackingSummary>();
const api = useApiV2Client();

const props = defineProps<{
    prediction: DashboardPrediction | PredictionListItem;
    href: string;
    sport?: string;
}>();

const predictionType = isPredictionListItem(props.prediction)
    ? props.prediction
    : null;
const dashboardType = isPredictionListItem(props.prediction)
    ? null
    : props.prediction;
const awayLogoErrored = ref(false);
const homeLogoErrored = ref(false);
const isSaveDialogOpen = ref(false);
const activeSaveOption = ref<SavePickOption | null>(null);
const trackingSummary = ref<TrackingSummary | null>(null);
const isLoadingTracking = ref(false);
const normalizedLiveState = computed(() =>
    normalizePredictionLiveState(props.prediction),
);

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

function isLive(): boolean {
    return normalizedLiveState.value.isLive;
}

function isFinal(): boolean {
    return normalizedLiveState.value.isFinal;
}

function awayScore(): number | null {
    return normalizedLiveState.value.awayScore;
}

function homeScore(): number | null {
    return normalizedLiveState.value.homeScore;
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
    const postseasonRound = predictionType?.game.postseason_round;
    const seasonType = predictionType?.game.season_type;

    if (!week || !seasonType) {
        return null;
    }

    const normalizedSeasonType = String(seasonType);

    if (
        normalizedSeasonType === 'Regular Season' ||
        normalizedSeasonType === '2'
    ) {
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
        return (
            getCfbPostseasonLabel(postseasonRound, week) ??
            `Postseason Week ${week}`
        );
    }

    return `Postseason Week ${week}`;
}

function gameDateTimeLabel(): string | null {
    if (dashboardType?.game_time) {
        return dashboardType.game_time;
    }

    const game = predictionType?.game;
    if (!game?.game_date) {
        return null;
    }

    const rawDateTime = game.game_time
        ? `${game.game_date}T${game.game_time}`
        : `${game.game_date}T00:00:00`;
    const date = new Date(rawDateTime);
    if (Number.isNaN(date.getTime())) {
        return game.game_time || game.game_date;
    }

    return date.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: game.game_time ? 'numeric' : undefined,
        minute: game.game_time ? '2-digit' : undefined,
    });
}

function predictionFreshnessLabel(): string | null {
    const updatedAt = props.prediction.updated_at;
    if (!updatedAt) {
        return null;
    }

    const date = new Date(updatedAt);
    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return `Updated ${date.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    })}`;
}

function preGameWinProbability(): number {
    return props.prediction.win_probability ?? 0;
}

function liveWinProbability(): number | null {
    return normalizedLiveState.value.liveWinProbability;
}

function hasLiveData(): boolean {
    return hasPredictionLiveData(props.prediction);
}

function isMlbPrediction(): boolean {
    return (props.sport ?? '').toLowerCase() === 'mlb';
}

function displayedWinProbability(): number {
    if (isMlbPrediction()) {
        return preGameWinProbability();
    }

    return hasLiveData()
        ? (liveWinProbability() ?? preGameWinProbability())
        : preGameWinProbability();
}

function winProbPercent(): number {
    const probability = displayedWinProbability();

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

function predictionAnalysis() {
    return props.prediction.prediction_analysis ?? null;
}

function hasPredictionAnalysis(): boolean {
    return predictionAnalysis() !== null;
}

function aiAnalysis() {
    return props.prediction.ai_analysis ?? null;
}

function hasAiAnalysis(): boolean {
    return aiAnalysis() !== null;
}

function aiBetClassificationLabel(): string | null {
    const classification = aiAnalysis()?.bet_classification;
    if (!classification) return null;

    const labels: Record<string, string> = {
        bet: 'Bet',
        lean: 'Lean',
        watch: 'Watch',
        pass: 'Pass',
    };

    return labels[classification] ?? formatAnalysisToken(classification);
}

function aiRecommendationLabel(): string | null {
    const recommendation = aiAnalysis()?.recommendation;
    return recommendation ? formatAnalysisToken(recommendation) : null;
}

function aiConfidenceLabel(): string | null {
    const analysis = aiAnalysis();
    if (!analysis) return null;

    return `${analysis.analysis_confidence}% Confidence`;
}

function topAiKeyFactors(): string[] {
    return (aiAnalysis()?.key_factors ?? []).slice(0, 3);
}

function topAiRiskFlags(): string[] {
    return (aiAnalysis()?.risk_flags ?? []).slice(0, 3);
}

function trustScoreLabel(): string | null {
    const trust = predictionAnalysis()?.trust_score;
    return typeof trust === 'number' ? `${Math.round(trust)} Trust` : null;
}

function modelSignalLabel(): string | null {
    const signal = predictionAnalysis()?.model_signal_classification;
    if (!signal) return null;

    const labels: Record<string, string> = {
        strong_model_side: 'Strong Model Side',
        lean_model_side: 'Lean Model Side',
        pass_model_side: 'Pass Model Side',
    };

    return labels[signal] ?? signal.replaceAll('_', ' ');
}

function betClassificationLabel(): string | null {
    const classification = predictionAnalysis()?.bet_classification;
    if (!classification) return null;

    const labels: Record<string, string> = {
        bet: 'Bet',
        bettable_edge: 'Bettable Edge',
        lean: 'Lean Edge',
        lean_edge: 'Lean Edge',
        model_rule_watchlist: 'Rule Watchlist',
        validated_winner_watchlist: 'Winner Watchlist',
        no_bet_no_edge: 'No Bet',
        no_bet_risk: 'No Bet',
        no_bet_rule_pass: 'No Bet',
    };

    return labels[classification] ?? classification.replaceAll('_', ' ');
}

function bestValidatedSignal() {
    return predictionAnalysis()?.best_validated_signal ?? null;
}

function validatedSignalRateLabel(): string | null {
    const signal = bestValidatedSignal();
    const rate = signal?.winner_hit_rate;

    return typeof rate === 'number' ? `${rate.toFixed(1)}% winner` : null;
}

function validatedSignalMetaLabel(): string | null {
    const signal = bestValidatedSignal();
    if (!signal) return null;

    const parts = [];
    if (typeof signal.sample_size === 'number' && signal.sample_size > 0) {
        parts.push(`${signal.sample_size} games`);
    }
    if (typeof signal.spread_mae === 'number') {
        parts.push(`${signal.spread_mae.toFixed(2)} spread MAE`);
    }

    return parts.length ? parts.join(' · ') : null;
}

function topReasonCodes(): string[] {
    return (predictionAnalysis()?.reason_codes ?? []).slice(0, 4);
}

function topRiskFlags(): string[] {
    return (predictionAnalysis()?.risk_flags ?? []).slice(0, 3);
}

function formatAnalysisToken(token: string): string {
    return token
        .split('_')
        .filter(Boolean)
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
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

function canonicalRecommendation() {
    return getPredictionRecommendation(props.prediction);
}

function canonicalPregameRecommendation() {
    return pregameRecommendation(props.prediction);
}

function hasCanonicalRecommendation(): boolean {
    return canonicalRecommendation() !== null;
}

function canonicalRecommendationLabel(): string | null {
    if (isBetRecommendation(props.prediction)) {
        return 'Model Bet';
    }

    if (isLeanRecommendation(props.prediction)) {
        return 'Model Lean';
    }

    if (isLiveMonitor(props.prediction)) {
        return 'Live Monitor';
    }

    const recommendation = canonicalRecommendation();
    if (recommendation?.recommendation_type === 'no_play') {
        return 'No Play';
    }

    return null;
}

function canonicalRecommendationClass(): string {
    if (isBetRecommendation(props.prediction)) {
        return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-100';
    }

    if (isLeanRecommendation(props.prediction)) {
        return 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-100';
    }

    if (isLiveMonitor(props.prediction)) {
        return 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-100';
    }

    return 'bg-sidebar-accent text-muted-foreground';
}

function canonicalPregameEdgeLabel(): string | null {
    const edge = canonicalPregameRecommendation()?.raw_edge;
    return typeof edge === 'number' ? `${(edge * 100).toFixed(1)}% raw edge` : null;
}

function hasValueSignals(): boolean {
    return Boolean(
        (props.prediction.betting_value &&
            props.prediction.betting_value.length > 0) ||
            hasLiveData() ||
            bettingValueDebug(),
    );
}

function hasDecisionSummary(): boolean {
    return !isFinal() && (hasAiAnalysis() || hasPredictionAnalysis() || hasValueSignals() || hasCanonicalRecommendation());
}

function livePredictionData() {
    return buildPredictionLiveData(props.prediction);
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

function finalResultLabel(): string | null {
    const correct = winnerCorrect();
    if (correct === true) return 'WIN';
    if (correct === false) return 'LOSS';
    return null;
}

function finalCardClass(): string {
    if (!isFinal()) return '';

    const correct = winnerCorrect();
    if (correct === true) return 'border-green-300/80 dark:border-green-700/60';
    if (correct === false) return 'border-red-300/80 dark:border-red-700/60';

    return '';
}

function parseTotalPickDirection(
    recommendation: string,
): 'over' | 'under' | null {
    const normalized = recommendation.toLowerCase();
    if (normalized.includes('over')) return 'over';
    if (normalized.includes('under')) return 'under';

    return null;
}

function totalResultLabel(): string | null {
    if (!isFinal()) return null;
    if (
        props.prediction.actual_total === null ||
        props.prediction.actual_total === undefined
    )
        return null;

    const totalBet = props.prediction.betting_value?.find(
        (bet) => bet.type === 'total',
    );
    if (!totalBet) return null;
    if (totalBet.market_line === null || totalBet.market_line === undefined)
        return null;

    const direction = parseTotalPickDirection(totalBet.recommendation);
    if (!direction) return null;

    const actual = Number(props.prediction.actual_total);
    const line = Number(totalBet.market_line);
    if (!Number.isFinite(actual) || !Number.isFinite(line)) return null;

    if (actual === line) return 'Push';
    const correct = direction === 'over' ? actual > line : actual < line;

    return correct ? 'Correct' : 'Incorrect';
}

function totalResultBadgeClass(): string {
    const label = totalResultLabel();
    if (label === 'Correct') {
        return 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300';
    }

    if (label === 'Incorrect') {
        return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300';
    }

    if (label === 'Push') {
        return 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
    }

    return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400';
}

const canSavePick = computed(
    () => predictionId() !== null && predictionModelClass() !== null,
);

watch(
    () =>
        `${predictionModelClass() ?? 'unknown'}:${predictionId() ?? 'unknown'}`,
    () => {
        void loadTrackingSummary();
    },
    { immediate: true },
);

function predictionModelClass(): string | null {
    const sport = (props.sport ?? '').toLowerCase();

    const map: Record<string, string> = {
        nba: 'App\\Models\\NBA\\Prediction',
        nfl: 'App\\Models\\NFL\\Prediction',
        cbb: 'App\\Models\\CBB\\Prediction',
        wcbb: 'App\\Models\\WCBB\\Prediction',
        mlb: 'App\\Models\\MLB\\Prediction',
        cfb: 'App\\Models\\CFB\\Prediction',
        wnba: 'App\\Models\\WNBA\\Prediction',
    };

    return map[sport] ?? null;
}

function predictionId(): number | null {
    if (predictionType) {
        return predictionType.id;
    }

    return typeof dashboardType?.id === 'number' ? dashboardType.id : null;
}

function predictedSpreadValue(): number | null {
    const spread = props.prediction.predicted_spread;

    return typeof spread === 'number' ? Number(spread.toFixed(1)) : null;
}

function predictedTotalValue(): number | null {
    const total = props.prediction.predicted_total;

    return typeof total === 'number' ? Number(total.toFixed(1)) : null;
}

function openSaveDialog(option: SavePickOption): void {
    activeSaveOption.value = option;
    isSaveDialogOpen.value = true;
}

function trackingKey(): string | null {
    const id = predictionId();
    const type = predictionModelClass();

    if (id === null || type === null) {
        return null;
    }

    return `${type}:${id}`;
}

async function loadTrackingSummary(force = false): Promise<void> {
    const key = trackingKey();

    if (!key) {
        trackingSummary.value = null;
        return;
    }

    if (!force && trackingCache.has(key)) {
        trackingSummary.value = trackingCache.get(key) ?? null;
        return;
    }

    isLoadingTracking.value = true;

    try {
        const response = await api.userBets.index<UserBetsTrackingResponse>({
            query: {
                prediction_id: predictionId(),
                prediction_type: predictionModelClass(),
            },
        });

        trackingSummary.value = response?.tracking ?? null;
        if (trackingSummary.value) {
            trackingCache.set(key, trackingSummary.value);
        }
    } catch (error) {
        console.error('Failed to load tracked picks:', error);
        trackingSummary.value = null;
    } finally {
        isLoadingTracking.value = false;
    }
}

function marketKeyForOption(
    option: SavePickOption,
): 'moneyline' | 'spread' | 'total' {
    return option.betType === 'total_over' || option.betType === 'total_under'
        ? 'total'
        : option.betType;
}

function trackedBetForOption(option: SavePickOption): TrackedBet | null {
    return (
        trackingSummary.value?.user_bets.find(
            (bet) =>
                bet.bet_type === option.betType &&
                bet.selection_side === option.selectionSide,
        ) ?? null
    );
}

function consensusForOption(option: SavePickOption): PublicConsensus | null {
    const market = trackingSummary.value?.markets[marketKeyForOption(option)];

    if (!market || market.total_picks === 0) {
        return null;
    }

    const side = market.sides[option.selectionSide];

    if (!side || side.count === 0) {
        return {
            summary: `Public: no ${option.teamLabel} picks yet`,
            detail: `${market.total_picks} tracked ${marketKeyForOption(option)} pick${market.total_picks === 1 ? '' : 's'}`,
        };
    }

    const leader = market.leader;

    return {
        summary: `Public: ${side.percent}% on ${consensusSideLabel(option.selectionSide)}`,
        detail: leader
            ? `${consensusSideLabel(leader.side)} leads ${leader.percent}% of ${market.total_picks} picks`
            : `${market.total_picks} tracked pick${market.total_picks === 1 ? '' : 's'}`,
    };
}

function consensusSideLabel(side: SavePickOption['selectionSide']): string {
    if (side === 'home') {
        return homeTeamLabel();
    }

    if (side === 'away') {
        return awayTeamLabel();
    }

    return side === 'over' ? 'Over' : 'Under';
}

async function handleSaved(): Promise<void> {
    await loadTrackingSummary(true);
}

function saveOptions(): SavePickOption[] {
    const spread = predictedSpreadValue();
    const total = predictedTotalValue();

    return [
        {
            betType: 'moneyline',
            selectionSide: 'away',
            teamLabel: awayTeamLabel(),
            title: `${awayTeamLabel()} moneyline`,
            defaultLine: null,
        },
        {
            betType: 'moneyline',
            selectionSide: 'home',
            teamLabel: homeTeamLabel(),
            title: `${homeTeamLabel()} moneyline`,
            defaultLine: null,
        },
        {
            betType: 'spread',
            selectionSide: 'away',
            teamLabel: awayTeamLabel(),
            title: `${awayTeamLabel()} spread`,
            defaultLine: spread !== null ? Number((-spread).toFixed(1)) : null,
        },
        {
            betType: 'spread',
            selectionSide: 'home',
            teamLabel: homeTeamLabel(),
            title: `${homeTeamLabel()} spread`,
            defaultLine: spread,
        },
        {
            betType: 'total_over',
            selectionSide: 'over',
            teamLabel: 'Over',
            title: 'Total over',
            defaultLine: total,
        },
        {
            betType: 'total_under',
            selectionSide: 'under',
            teamLabel: 'Under',
            title: 'Total under',
            defaultLine: total,
        },
    ];
}
</script>

<template>
    <div
        class="ui-surface-subtle relative block p-3 transition-all duration-200 hover:-translate-y-0.5 hover:border-sidebar-border hover:shadow-md focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:outline-none md:p-4"
        :class="finalCardClass()"
    >
        <Link :href="href" class="block">
            <span
                v-if="isFavorite()"
                class="absolute top-3 left-3 rounded-full bg-green-100 px-2 py-0.5 text-[10px] font-semibold tracking-wide text-green-700 uppercase dark:bg-green-900 dark:text-green-200"
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
                        <span
                            class="h-2 w-2 animate-pulse rounded-full bg-red-500"
                        ></span>
                        <span
                            class="text-xs font-semibold text-red-600 dark:text-red-400"
                            >LIVE</span
                        >
                    </div>
                    <div
                        v-else-if="isFinal()"
                        class="flex items-center gap-1.5 self-start rounded-full bg-gray-100 px-2 py-0.5 dark:bg-gray-800"
                    >
                        <span
                            class="text-xs font-semibold text-gray-600 dark:text-gray-400"
                            >FINAL</span
                        >
                        <span
                            v-if="finalResultLabel()"
                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                            :class="finalResultBadgeClass()"
                        >
                            {{ finalResultLabel() }}
                        </span>
                    </div>

                    <div
                        class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4"
                    >
                        <div class="flex items-center gap-2">
                            <img
                                v-if="showAwayLogo()"
                                :src="awayLogo()!"
                                :alt="awayTeamLabel()"
                                class="h-8 w-8 object-contain md:h-10 md:w-10"
                                @error="handleAwayLogoError"
                            />
                            <span class="text-sm font-semibold md:text-base">{{
                                awayTeamLabel()
                            }}</span>
                            <span
                                v-if="isLive() || isFinal()"
                                class="ml-auto text-base font-bold md:text-lg"
                            >
                                {{ awayScore() ?? '-' }}
                            </span>
                        </div>
                        <span class="hidden text-muted-foreground sm:inline"
                            >@</span
                        >
                        <div class="flex items-center gap-2">
                            <img
                                v-if="showHomeLogo()"
                                :src="homeLogo()!"
                                :alt="homeTeamLabel()"
                                class="h-8 w-8 object-contain md:h-10 md:w-10"
                                @error="handleHomeLogoError"
                            />
                            <span class="text-sm font-semibold md:text-base">{{
                                homeTeamLabel()
                            }}</span>
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
                        <span
                            v-if="gameDateTimeLabel()"
                            class="ui-chip text-muted-foreground"
                        >
                            {{ gameDateTimeLabel() }}
                        </span>
                        <span
                            v-if="predictionFreshnessLabel()"
                            class="ui-chip text-muted-foreground"
                        >
                            {{ predictionFreshnessLabel() }}
                        </span>
                        <span
                            v-if="isFinal() && winnerCorrect() !== null"
                            class="rounded px-2 py-0.5 text-xs font-semibold"
                            :class="finalResultBadgeClass()"
                        >
                            Winner Pick:
                            {{ winnerCorrect() ? 'Correct' : 'Incorrect' }}
                        </span>
                        <span
                            v-if="isFinal() && totalResultLabel()"
                            class="rounded px-2 py-0.5 text-xs font-semibold"
                            :class="totalResultBadgeClass()"
                        >
                            O/U: {{ totalResultLabel() }}
                        </span>
                    </div>
                </div>

                <div
                    v-if="!isFinal()"
                    class="grid grid-cols-1 gap-2 sm:grid-cols-1 md:min-w-[180px]"
                >
                    <div class="ui-surface-subtle p-2">
                        <div class="mb-1 flex items-center gap-1.5">
                            <Target class="h-3.5 w-3.5" />
                            <span class="ui-chip text-foreground/80">
                                {{
                                    isMlbPrediction()
                                        ? 'Pregame pick'
                                        : 'Moneyline'
                                }}:
                                {{ moneylineTeamLabel() }}
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
                        <div
                            class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-sidebar-accent"
                        >
                            <div
                                class="h-full rounded-full transition-all"
                                :class="
                                    edgePercent() >= 0
                                        ? 'bg-emerald-500'
                                        : 'bg-red-500'
                                "
                                :style="{ width: `${edgeBarWidth()}%` }"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-if="hasDecisionSummary()"
                class="mt-4 border-t border-sidebar-border/70 pt-4"
            >
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <div
                        class="inline-flex items-center gap-1.5 rounded-full bg-sidebar-accent/70 px-2.5 py-1 text-xs font-semibold tracking-wide uppercase"
                    >
                        <ShieldCheck class="h-3.5 w-3.5" />
                        Decision Summary
                    </div>
                    <span
                        v-if="aiBetClassificationLabel()"
                        class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-800 dark:bg-sky-900/40 dark:text-sky-100"
                    >
                        AI {{ aiBetClassificationLabel() }}
                    </span>
                    <span
                        v-if="betClassificationLabel()"
                        class="rounded-full bg-sidebar-accent px-2 py-0.5 text-xs font-medium"
                    >
                        Model {{ betClassificationLabel() }}
                    </span>
                    <span
                        v-if="canonicalRecommendationLabel()"
                        class="rounded-full px-2 py-0.5 text-xs font-semibold"
                        :class="canonicalRecommendationClass()"
                    >
                        {{ canonicalRecommendationLabel() }}
                    </span>
                    <span
                        v-if="canonicalPregameEdgeLabel()"
                        class="text-xs text-muted-foreground"
                    >
                        {{ canonicalPregameEdgeLabel() }}
                    </span>
                    <span
                        v-if="trustScoreLabel()"
                        class="rounded-full bg-emerald-100/80 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300"
                    >
                        {{ trustScoreLabel() }}
                    </span>
                    <span
                        v-if="aiConfidenceLabel()"
                        class="text-xs text-muted-foreground"
                    >
                        {{ aiConfidenceLabel() }}
                    </span>
                </div>

                <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                    <div
                        v-if="hasAiAnalysis() || hasPredictionAnalysis()"
                        class="rounded-md border border-sidebar-border/80 bg-sidebar/30 p-3"
                    >
                        <div
                            v-if="hasAiAnalysis()"
                            class="text-sm text-foreground"
                        >
                            <div
                                class="mb-1 flex flex-wrap items-center gap-2 text-xs"
                            >
                                <Sparkles class="h-3.5 w-3.5" />
                                <span class="font-semibold">AI context</span>
                                <span
                                    v-if="aiRecommendationLabel()"
                                    class="rounded-full bg-sky-100 px-2 py-0.5 font-medium text-sky-800 dark:bg-sky-900/40 dark:text-sky-100"
                                >
                                    {{ aiRecommendationLabel() }}
                                </span>
                                <span class="text-muted-foreground">
                                    {{ aiAnalysis()?.as_of_date }}
                                </span>
                            </div>
                            <p class="text-sm text-muted-foreground">
                                {{ aiAnalysis()?.summary }}
                            </p>
                        </div>

                        <div
                            v-if="bestValidatedSignal()"
                            class="mt-3 rounded-md border border-emerald-200/70 bg-emerald-50/70 px-3 py-2 text-xs text-emerald-950 dark:border-emerald-900/50 dark:bg-emerald-950/20 dark:text-emerald-100"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="inline-flex items-center gap-1 font-semibold"
                                >
                                    <Target class="h-3.5 w-3.5" />
                                    {{ bestValidatedSignal()?.label }}
                                </span>
                                <span
                                    v-if="validatedSignalRateLabel()"
                                    class="rounded-full bg-emerald-600 px-2 py-0.5 font-semibold text-white dark:bg-emerald-500 dark:text-emerald-950"
                                >
                                    {{ validatedSignalRateLabel() }}
                                </span>
                                <span
                                    v-if="validatedSignalMetaLabel()"
                                    class="text-emerald-800 dark:text-emerald-200"
                                >
                                    {{ validatedSignalMetaLabel() }}
                                </span>
                            </div>
                            <p
                                v-if="bestValidatedSignal()?.note"
                                class="mt-1 text-emerald-800 dark:text-emerald-200"
                            >
                                {{ bestValidatedSignal()?.note }}
                            </p>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-1.5 text-xs">
                            <span
                                v-if="modelSignalLabel()"
                                class="rounded-full bg-sidebar-accent px-2 py-1 font-medium"
                            >
                                {{ modelSignalLabel() }}
                            </span>
                            <span
                                v-for="code in topReasonCodes()"
                                :key="`reason-${code}`"
                                class="rounded-full border border-sidebar-border/80 px-2 py-1 text-muted-foreground"
                            >
                                {{ formatAnalysisToken(code) }}
                            </span>
                            <span
                                v-for="factor in topAiKeyFactors()"
                                :key="`ai-factor-${factor}`"
                                class="rounded-full border border-sky-200/70 px-2 py-1 text-sky-800 dark:border-sky-900/50 dark:text-sky-100"
                            >
                                {{ factor }}
                            </span>
                            <span
                                v-for="(flag, index) in [
                                    ...topRiskFlags(),
                                    ...topAiRiskFlags(),
                                ]"
                                :key="`risk-${index}-${flag}`"
                                class="rounded-full bg-amber-100/80 px-2 py-1 font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-200"
                            >
                                {{ formatAnalysisToken(flag) }}
                            </span>
                        </div>
                    </div>

                    <div
                        v-if="hasValueSignals()"
                        class="rounded-md border border-sidebar-border/80 bg-sidebar/30 p-3"
                    >
                        <div class="mb-2 flex items-center gap-2">
                            <div
                                class="inline-flex items-center gap-1.5 text-xs font-semibold tracking-wide uppercase"
                            >
                                <Sparkles class="h-3.5 w-3.5" />
                                {{
                                    hasLiveData()
                                        ? 'Live Signals'
                                        : 'Market Value'
                                }}
                            </div>
                            <div
                                v-if="
                                    !hasLiveData() &&
                                    prediction.betting_value?.length
                                "
                                class="text-xs text-muted-foreground"
                            >
                                Vegas
                            </div>
                        </div>
                        <div
                            v-if="
                                !hasLiveData() &&
                                (!prediction.betting_value ||
                                    prediction.betting_value.length === 0)
                            "
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
                            :actual-total="
                                isFinal() ? (prediction.actual_total ?? null) : null
                            "
                            :compact="true"
                        />
                    </div>
                </div>
            </div>
        </Link>

        <div
            v-if="canSavePick && !isFinal()"
            class="mt-4 border-t border-sidebar-border/70 pt-4"
        >
            <div class="flex items-center justify-between gap-2">
                <div>
                    <div
                        class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        Track This Pick
                    </div>
                    <div
                        v-if="isLoadingTracking"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Loading tracked picks...
                    </div>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger :as-child="true">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="gap-2"
                            @click.stop
                        >
                            Save Pick
                            <ChevronDown class="h-4 w-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56" @click.stop>
                        <DropdownMenuLabel>Choose a side</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            v-for="option in saveOptions()"
                            :key="`${option.betType}-${option.selectionSide}`"
                            @select.prevent="openSaveDialog(option)"
                        >
                            <div
                                class="flex w-full items-start justify-between gap-3"
                            >
                                <div class="min-w-0">
                                    <div
                                        class="truncate font-medium text-foreground"
                                    >
                                        {{ option.title }}
                                    </div>
                                    <div
                                        v-if="consensusForOption(option)"
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{
                                            consensusForOption(option)?.summary
                                        }}
                                    </div>
                                </div>
                                <span
                                    v-if="trackedBetForOption(option)"
                                    class="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300"
                                >
                                    Tracked
                                </span>
                            </div>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>

        <SavePickDialog
            v-if="predictionId() !== null && predictionModelClass()"
            :open="isSaveDialogOpen"
            :prediction-id="predictionId()!"
            :prediction-type="predictionModelClass()!"
            :option="activeSaveOption"
            :existing-bet="
                activeSaveOption ? trackedBetForOption(activeSaveOption) : null
            "
            :public-consensus="
                activeSaveOption ? consensusForOption(activeSaveOption) : null
            "
            @update:open="isSaveDialogOpen = $event"
            @saved="handleSaved"
        />
    </div>
</template>
