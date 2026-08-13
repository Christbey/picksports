<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Activity,
    ChevronDown,
    ChevronRight,
    Clock,
    Gauge,
    Radio,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
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
    hasPredictionLiveData,
    isPredictionListItem,
    normalizePredictionLiveState,
} from '@/composables/usePredictionLiveData';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { getCfbPostseasonLabel } from '@/lib/cfbPostseason';
import {
    candidateRecommendation,
    getPredictionRecommendation,
    isPromotionBlocked,
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

interface CfbSignalBadge {
    label: string;
    tone: 'neutral' | 'positive' | 'watch';
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

function gameBalls(): number | null {
    return normalizedLiveState.value.balls;
}

function gameStrikes(): number | null {
    return normalizedLiveState.value.strikes;
}

function gameOuts(): number | null {
    return normalizedLiveState.value.outs;
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

function showGameScore(): boolean {
    return (
        (isLive() || isFinal()) && awayScore() !== null && homeScore() !== null
    );
}

function teamScoreClass(team: 'away' | 'home'): string {
    if (!showGameScore()) {
        return '';
    }

    const away = awayScore();
    const home = homeScore();
    if (away === null || home === null || away === home) {
        return 'text-foreground';
    }

    const isWinning =
        (team === 'away' && away > home) || (team === 'home' && home > away);

    if (isFinal()) {
        return isWinning
            ? 'text-emerald-700 dark:text-emerald-300'
            : 'text-muted-foreground';
    }

    return isWinning
        ? 'text-sky-700 dark:text-sky-300'
        : 'text-muted-foreground';
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

    if (week === null || week === undefined || !seasonType) {
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
        const date = new Date(dashboardType.game_time);

        if (!Number.isNaN(date.getTime())) {
            return date.toLocaleString('en-US', {
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
            });
        }

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

function statusBadgeLabel(): string {
    const status = String(
        dashboardType?.status ?? predictionType?.game.status ?? '',
    ).toLowerCase();

    if (isFinal()) return 'Final';
    if (isLive()) return 'Live';
    if (status.includes('postponed')) return 'Postponed';

    return 'Pregame';
}

function statusBadgeClass(): string {
    if (isLive()) {
        return 'rounded-full border border-red-500/30 bg-red-500/10 px-2.5 py-1 text-xs font-semibold text-red-700 dark:text-red-300';
    }

    return dashboardChipClass();
}

function preGameWinProbability(): number {
    return props.prediction.win_probability ?? 0;
}

function liveWinProbability(): number | null {
    return normalizedLiveState.value.liveWinProbability;
}

function normalizedProbability(value: number | null): number | null {
    if (value === null || !Number.isFinite(value)) return null;

    const decimal = value > 1 ? value / 100 : value;

    return Math.max(0, Math.min(1, decimal));
}

function ordinal(value: number): string {
    const mod100 = value % 100;
    if (mod100 >= 11 && mod100 <= 13) return `${value}th`;

    const suffix =
        value % 10 === 1
            ? 'st'
            : value % 10 === 2
              ? 'nd'
              : value % 10 === 3
                ? 'rd'
                : 'th';

    return `${value}${suffix}`;
}

function liveGameStateLabel(): string {
    if (isMlbPrediction()) {
        const rawState = normalizedLiveState.value.inningState?.trim();
        const inning = normalizedLiveState.value.inning;

        if (rawState && /\d/.test(rawState)) return rawState;

        if (inning !== null) {
            const half = rawState
                ? rawState.charAt(0).toUpperCase() + rawState.slice(1)
                : 'Inning';

            return `${half} ${ordinal(inning)}`;
        }

        return rawState || 'Live inning';
    }

    const parts = [];
    if (normalizedLiveState.value.period !== null) {
        parts.push(`Period ${normalizedLiveState.value.period}`);
    }
    if (normalizedLiveState.value.gameClock) {
        parts.push(normalizedLiveState.value.gameClock);
    }

    return parts.join(' · ') || 'Live game';
}

function liveScoreContextLabel(): string {
    const away = awayScore();
    const home = homeScore();

    if (away === null || home === null) return 'Score updating';
    if (away === home) return `Tied at ${home}`;

    const leader = home > away ? homeTeamLabel() : awayTeamLabel();

    return `${leader} leads by ${Math.abs(home - away)}`;
}

function liveGameStateMeta(): string {
    if (!isMlbPrediction()) return liveScoreContextLabel();

    const parts = [];
    if (gameOuts() !== null) {
        parts.push(`${gameOuts()} ${gameOuts() === 1 ? 'out' : 'outs'}`);
    }
    if (gameBalls() !== null && gameStrikes() !== null) {
        parts.push(`Count ${gameBalls()}-${gameStrikes()}`);
    }

    return parts.join(' · ') || liveScoreContextLabel();
}

function liveHomeProbability(): number | null {
    return normalizedProbability(liveWinProbability());
}

function liveWinLeaderLabel(): string | null {
    const probability = liveHomeProbability();
    if (probability === null) return null;

    return probability >= 0.5 ? homeTeamLabel() : awayTeamLabel();
}

function liveWinOutlookValue(): string {
    const probability = liveHomeProbability();
    const leader = liveWinLeaderLabel();

    if (probability === null || !leader) return 'Model updating';

    return `${leader} ${(Math.max(probability, 1 - probability) * 100).toFixed(1)}%`;
}

function liveWinMovement(): number | null {
    const live = liveHomeProbability();
    const pregame = normalizedProbability(preGameWinProbability());

    return live === null || pregame === null ? null : (live - pregame) * 100;
}

function liveWinOutlookMeta(): string {
    const movement = liveWinMovement();
    if (movement === null) return 'Live probability pending';
    if (Math.abs(movement) < 1) return 'Holding near pregame';

    const beneficiary = movement > 0 ? homeTeamLabel() : awayTeamLabel();

    return `${beneficiary} +${Math.abs(movement).toFixed(1)} pts from pregame`;
}

function liveTotalOutlookValue(): string {
    const projected = normalizedLiveState.value.livePredictedTotal;
    const unit = isMlbPrediction() ? 'runs' : 'points';

    if (projected !== null) return `${projected.toFixed(1)} ${unit}`;

    const away = awayScore();
    const home = homeScore();

    return away !== null && home !== null
        ? `${away + home} ${unit} scored`
        : 'Model updating';
}

function liveTotalOutlookMeta(): string {
    const live = normalizedLiveState.value.livePredictedTotal;
    const pregame = normalizedLiveState.value.preGamePredictedTotal;

    if (live === null || !Number.isFinite(pregame)) {
        return 'Updated total pending';
    }

    const movement = live - pregame;
    if (Math.abs(movement) < 0.5) return 'Near pregame projection';

    return `${formatSignedNumber(movement)} vs pregame total`;
}

function preGameTeamLabel(): string {
    return preGameWinProbability() >= 0.5 ? homeTeamLabel() : awayTeamLabel();
}

function hasLiveData(): boolean {
    return hasPredictionLiveData(props.prediction);
}

function isMlbPrediction(): boolean {
    return (props.sport ?? '').toLowerCase() === 'mlb';
}

function isNflPrediction(): boolean {
    return (props.sport ?? '').toLowerCase() === 'nfl';
}

function isCfbPrediction(): boolean {
    return (props.sport ?? '').toLowerCase() === 'cfb';
}

function marketAwareProjection() {
    return isMlbPrediction()
        ? (props.prediction.market_aware_projection ?? null)
        : null;
}

function marketAwareSignalLabel(): string {
    const projection = marketAwareProjection();

    if (!projection) return 'Tracking Only';

    if (projection.agreement_status === 'agrees') {
        return 'Market-Aligned';
    }

    if (projection.agreement_status === 'disagrees') {
        return 'Market Disagree';
    }

    if (projection.agreement_status === 'market_missing') {
        return 'Market Missing';
    }

    return 'Tracking Only';
}

function asRecord(value: unknown): Record<string, unknown> {
    return value && typeof value === 'object' && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : {};
}

function asStringArray(value: unknown): string[] {
    return Array.isArray(value)
        ? value.filter((item): item is string => typeof item === 'string')
        : [];
}

function numericValue(value: unknown): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const numeric = Number(value);

    return Number.isFinite(numeric) ? numeric : null;
}

function cfbSignalContext(): Record<string, unknown> {
    if (!isCfbPrediction()) return {};

    return asRecord(predictionType?.cfb_signal_context);
}

function cfbSignalBadges(): CfbSignalBadge[] {
    const context = cfbSignalContext();
    if (!Object.keys(context).length) return [];

    const badges: CfbSignalBadge[] = [];
    const preseason = asRecord(context.preseason_layer);
    const gameContext = asRecord(context.game_context);
    const market = asRecord(context.market_movement);
    const playerAvailability = asRecord(context.player_availability);

    const preseasonSpread = numericValue(preseason.spread_adjustment);
    const alignedSignals = numericValue(preseason.aligned_signals);
    if (preseason.enabled === true) {
        const parts = [];
        if (preseasonSpread !== null) {
            parts.push(formatSignedNumber(preseasonSpread));
        }
        if (alignedSignals !== null) {
            parts.push(`${alignedSignals} aligned`);
        }

        badges.push({
            label: `Preseason${parts.length ? ` ${parts.join(' · ')}` : ''}`,
            tone: 'positive',
        });
    }

    const contextSpread = numericValue(gameContext.spread_adjustment);
    const contextTotal = numericValue(gameContext.total_adjustment);
    if (contextSpread !== null || contextTotal !== null) {
        const parts = [];
        if (contextSpread !== null) {
            parts.push(`S ${formatSignedNumber(contextSpread)}`);
        }
        if (contextTotal !== null) {
            parts.push(`T ${formatSignedNumber(contextTotal)}`);
        }

        badges.push({
            label: `Context ${parts.join(' ')}`,
            tone: 'neutral',
        });
    }

    const bookmakerHomeLine = numericValue(market.current_bookmaker_home_line);
    if (bookmakerHomeLine !== null) {
        const movedToward = market.line_moved_toward_model === true;
        const movedAgainst = market.line_moved_against_model === true;
        badges.push({
            label: `Market ${formatSignedNumber(bookmakerHomeLine)}${
                movedToward ? ' toward' : movedAgainst ? ' against' : ''
            }`,
            tone: movedAgainst ? 'watch' : movedToward ? 'positive' : 'neutral',
        });
    }

    const weather = asRecord(gameContext.weather);
    const weatherSignal = String(weather.signal ?? '');
    if (
        weather.available === true &&
        weatherSignal &&
        !['neutral', 'weather_not_available'].includes(weatherSignal)
    ) {
        badges.push({
            label: `Weather ${formatAnalysisToken(weatherSignal.split(',')[0])}`,
            tone: 'watch',
        });
    }

    const schedule = asRecord(gameContext.schedule);
    const scheduleFlags = asStringArray(schedule.risk_flags);
    if (scheduleFlags.length > 0) {
        badges.push({
            label: `Schedule ${scheduleFlags.length}`,
            tone: 'watch',
        });
    }

    const availabilitySpread = numericValue(
        playerAvailability.spread_adjustment,
    );
    if (playerAvailability.applied === true && availabilitySpread !== null) {
        badges.push({
            label: `Availability ${formatSignedNumber(availabilitySpread)}`,
            tone: 'watch',
        });
    }

    return badges.slice(0, 6);
}

function cfbSignalBadgeClass(tone: CfbSignalBadge['tone']): string {
    if (tone === 'positive') {
        return 'border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    }

    if (tone === 'watch') {
        return 'border-amber-500/25 bg-amber-500/10 text-amber-700 dark:text-amber-300';
    }

    return 'border-muted bg-muted/40 text-muted-foreground';
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
    if (isMlbPrediction()) {
        return marketAwareSignalLabel();
    }

    const absEdge = Math.abs(edgePercent());
    if (absEdge < 2) return 'Toss-up';
    if (absEdge < 7) return 'Lean model edge';
    if (absEdge < 14) return 'Playable model edge';
    return 'Strong model edge';
}

function predictionAnalysis() {
    return props.prediction.prediction_analysis ?? null;
}

function aiAnalysis() {
    return props.prediction.ai_analysis ?? null;
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

function trustScoreLabel(): string | null {
    const trust = predictionAnalysis()?.trust_score;
    return typeof trust === 'number' ? `${Math.round(trust)} Trust` : null;
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
        validated_winner_watchlist: isNflPrediction()
            ? 'Moneyline Watchlist'
            : 'Winner Watchlist',
        no_bet_no_edge: 'No Bet',
        no_bet_risk: 'No Bet',
        no_bet_rule_pass: 'No Bet',
    };

    return labels[classification] ?? classification.replaceAll('_', ' ');
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

function valueSignal() {
    return predictionType?.value_signal ?? null;
}

function hasValueSignal(): boolean {
    return valueSignal()?.best !== null && valueSignal()?.best !== undefined;
}

function valueSignalBest() {
    return valueSignal()?.best ?? null;
}

function valueSignalTitle(): string {
    const best = valueSignalBest();
    if (!best?.label) {
        return 'No qualifying market edge';
    }

    return best.label.replace(/^Bet\s+/i, '');
}

function valueSignalMeta(): string | null {
    const best = valueSignalBest();
    if (!best) return null;

    const parts = [];
    if (best.type) {
        parts.push(formatAnalysisToken(String(best.type)));
    }
    if (typeof best.edge === 'number') {
        const suffix = best.type === 'moneyline' ? '%' : ' pts';
        parts.push(
            `Edge ${best.edge > 0 ? '+' : ''}${best.edge.toFixed(1)}${suffix}`,
        );
    }
    if (best.grade) {
        parts.push(`Grade ${best.grade}`);
    }

    return parts.length ? parts.join(' · ') : null;
}

function canonicalRecommendation() {
    return getPredictionRecommendation(props.prediction);
}

function canonicalCandidateRecommendation() {
    return candidateRecommendation(props.prediction);
}

function canonicalPregameRecommendation() {
    return pregameRecommendation(props.prediction);
}

function canonicalRecommendationLabel(): string | null {
    if (isMlbPrediction() && isPromotionBlocked(props.prediction)) {
        return 'Tracking Only';
    }

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

function canonicalPregameEdgeLabel(): string | null {
    const edge = canonicalPregameRecommendation()?.raw_edge;
    return typeof edge === 'number'
        ? `${(edge * 100).toFixed(1)}% raw edge`
        : null;
}

function winnerCorrect(): boolean | null {
    const value = props.prediction.winner_correct;
    return typeof value === 'boolean' ? value : null;
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

function dashboardCardTone():
    | 'quiet'
    | 'positive'
    | 'negative'
    | 'candidate'
    | 'live' {
    if (isLive()) return 'live';

    if (isFinal()) {
        const correct = winnerCorrect();
        if (correct === true) return 'positive';
        if (correct === false) return 'negative';
    }

    if (
        isBetRecommendation(props.prediction) ||
        valueSignal()?.has_playable_value
    ) {
        return 'positive';
    }

    if (
        isLeanRecommendation(props.prediction) ||
        isLiveMonitor(props.prediction) ||
        aiBetClassificationLabel() === 'Bet' ||
        aiBetClassificationLabel() === 'Lean'
    ) {
        return 'candidate';
    }

    const candidate = canonicalCandidateRecommendation();
    if (
        isMlbPrediction() &&
        canonicalRecommendation()?.prediction_phase === 'pregame' &&
        ['bet', 'lean'].includes(candidate?.recommendation_type ?? '')
    ) {
        return 'candidate';
    }

    return 'quiet';
}

function dashboardCardClass(): string {
    const tone = dashboardCardTone();

    if (tone === 'positive') {
        return 'border-emerald-500/45 bg-emerald-950/[0.04] ring-1 ring-emerald-500/15 dark:bg-emerald-500/[0.06]';
    }

    if (tone === 'negative') {
        return 'border-red-500/35 bg-red-950/[0.03] dark:bg-red-500/[0.05]';
    }

    if (tone === 'candidate') {
        return 'border-sky-500/35 bg-sky-950/[0.03] dark:bg-sky-500/[0.05]';
    }

    if (tone === 'live') {
        return 'border-red-500/35 bg-red-950/[0.025] ring-1 ring-red-500/10 dark:bg-red-500/[0.045]';
    }

    return 'border-border/70 bg-card/80 hover:border-sky-500/30';
}

function dashboardRailClass(): string {
    const tone = dashboardCardTone();

    if (tone === 'positive') return 'bg-emerald-500';
    if (tone === 'negative') return 'bg-red-500';
    if (tone === 'candidate') return 'bg-sky-500';
    if (tone === 'live') return 'bg-red-500';

    return 'bg-slate-500/40';
}

function dashboardChipClass(): string {
    return 'rounded-full border bg-background/75 px-2.5 py-1 text-xs font-semibold text-muted-foreground';
}

function dashboardPrimaryPickLabel(): string {
    const prefix = isLive()
        ? 'Pregame'
        : isNflPrediction()
          ? 'Moneyline'
          : 'Pick';
    const pregamePercent = Math.max(
        preGameWinProbability() * 100,
        100 - preGameWinProbability() * 100,
    );

    return `${prefix}: ${preGameTeamLabel()} ${pregamePercent.toFixed(1)}%`;
}

function dashboardSignalLabel(): string {
    if (isMlbPrediction()) {
        const recommendation = canonicalRecommendation();
        const candidate = canonicalCandidateRecommendation();
        const isPregame = recommendation?.prediction_phase === 'pregame';

        if (recommendation?.recommendation_type === 'monitor') {
            return 'Live Monitor';
        }

        if (isPregame && candidate?.recommendation_type === 'bet') {
            return isPromotionBlocked(props.prediction)
                ? 'Tracking Bet'
                : 'Model Bet';
        }

        if (isPregame && candidate?.recommendation_type === 'lean') {
            return isPromotionBlocked(props.prediction)
                ? 'Tracking Lean'
                : 'Model Lean';
        }

        if (isPregame && candidate?.recommendation_type === 'no_play') {
            return 'Pass';
        }
    }

    return (
        canonicalRecommendationLabel() ??
        betClassificationLabel() ??
        aiBetClassificationLabel() ??
        (isFavorite() ? 'Favorite' : 'No signal')
    );
}

function dashboardSignalClass(): string {
    const tone = dashboardCardTone();

    if (tone === 'positive') {
        return 'border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    }

    if (tone === 'candidate') {
        return 'border-sky-500/25 bg-sky-500/10 text-sky-700 dark:text-sky-300';
    }

    if (tone === 'negative') {
        return 'border-red-500/25 bg-red-500/10 text-red-700 dark:text-red-300';
    }

    if (tone === 'live') {
        return 'border-red-500/25 bg-red-500/10 text-red-700 dark:text-red-300';
    }

    return 'border-muted bg-muted/40 text-muted-foreground';
}

function dashboardActionTextClass(): string {
    const tone = dashboardCardTone();

    if (tone === 'positive') return 'text-emerald-600 dark:text-emerald-400';
    if (tone === 'negative') return 'text-red-600 dark:text-red-400';
    if (tone === 'candidate') return 'text-sky-600 dark:text-sky-400';
    if (tone === 'live') return 'text-red-600 dark:text-red-400';

    return 'text-muted-foreground';
}

function formatSignedNumber(value: number, decimals = 1): string {
    return `${value > 0 ? '+' : ''}${value.toFixed(decimals)}`;
}

function dashboardFooterContextLabel(): string {
    if (isFinal() && totalResultLabel()) {
        return `O/U ${totalResultLabel()}`;
    }

    if (hasValueSignal()) {
        return valueSignalMeta()
            ? `${valueSignalTitle()} · ${valueSignalMeta()}`
            : valueSignalTitle();
    }

    if (isMlbPrediction()) {
        const recommendation = canonicalRecommendation();
        const candidate = canonicalCandidateRecommendation();
        const reason = candidate?.no_bet_reason;

        if (
            recommendation?.prediction_phase === 'pregame' &&
            candidate?.recommendation_type === 'no_play' &&
            reason
        ) {
            return `Pass · ${formatAnalysisToken(reason)}`;
        }

        if (
            ['bet', 'lean'].includes(candidate?.recommendation_type ?? '') &&
            recommendation?.prediction_phase === 'pregame' &&
            isPromotionBlocked(props.prediction)
        ) {
            const edge = candidate?.raw_edge;
            const edgeLabel =
                typeof edge === 'number'
                    ? ` · ${(edge * 100).toFixed(1)}% raw edge`
                    : '';

            return `Tracking only${edgeLabel}`;
        }
    }

    if (bettingValueDebugLabel()) {
        return bettingValueDebugLabel()!;
    }

    return edgeSignalLabel();
}

function totalPickPillLabel(): string | null {
    const best = valueSignalBest();
    const bestType = String(best?.type ?? '').toLowerCase();
    if (best?.label && bestType === 'total') {
        return best.label.replace(/^Bet\s+/i, '');
    }

    const totalBet = props.prediction.betting_value?.find(
        (bet) => bet.type === 'total',
    );
    if (!totalBet) return null;

    const direction = parseTotalPickDirection(totalBet.recommendation);
    const line = totalBet.market_line;
    if (!direction || typeof line !== 'number') return null;

    return `${direction === 'over' ? 'Over' : 'Under'} ${line.toFixed(1)}`;
}

function totalPickPillStrong(): boolean {
    const best = valueSignalBest();
    const bestType = String(best?.type ?? '').toLowerCase();

    if (bestType === 'total' && valueSignal()?.has_playable_value) {
        return true;
    }

    return Boolean(
        props.prediction.betting_value?.some(
            (bet) => bet.type === 'total' && bet.is_playable === true,
        ),
    );
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
    () =>
        predictionType !== null &&
        predictionId() !== null &&
        predictionModelClass() !== null,
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

function homeSpreadLineValue(): number | null {
    const spread = predictedSpreadValue();

    return spread !== null ? Number((-spread).toFixed(1)) : null;
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
            defaultLine: spread,
        },
        {
            betType: 'spread',
            selectionSide: 'home',
            teamLabel: homeTeamLabel(),
            title: `${homeTeamLabel()} spread`,
            defaultLine: spread !== null ? Number((-spread).toFixed(1)) : null,
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
        class="group relative flex min-h-[132px] w-full overflow-hidden rounded-2xl border p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md focus-visible:ring-2 focus-visible:ring-sky-500/35 focus-visible:outline-none"
        :class="dashboardCardClass()"
    >
        <div
            class="absolute inset-y-0 left-0 w-1"
            :class="dashboardRailClass()"
        />
        <div class="flex min-w-0 flex-1 flex-col gap-3 pl-1">
            <Link :href="href" class="flex min-w-0 flex-1 flex-col gap-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <div class="flex items-center gap-2 font-semibold">
                                <img
                                    v-if="showAwayLogo()"
                                    :src="awayLogo()!"
                                    :alt="awayTeamLabel()"
                                    class="h-6 w-6 rounded-full object-contain"
                                    @error="handleAwayLogoError"
                                />
                                <span
                                    class="inline-flex items-center gap-1.5"
                                    :class="teamScoreClass('away')"
                                >
                                    <span>{{ awayTeamLabel() }}</span>
                                    <span
                                        v-if="showGameScore()"
                                        class="font-bold"
                                    >
                                        {{ awayScore() }}
                                    </span>
                                </span>
                                <span class="text-muted-foreground">@</span>
                                <img
                                    v-if="showHomeLogo()"
                                    :src="homeLogo()!"
                                    :alt="homeTeamLabel()"
                                    class="h-6 w-6 rounded-full object-contain"
                                    @error="handleHomeLogoError"
                                />
                                <span
                                    class="inline-flex items-center gap-1.5"
                                    :class="teamScoreClass('home')"
                                >
                                    <span>{{ homeTeamLabel() }}</span>
                                    <span
                                        v-if="showGameScore()"
                                        class="font-bold"
                                    >
                                        {{ homeScore() }}
                                    </span>
                                </span>
                            </div>
                            <span
                                v-if="gameDateTimeLabel()"
                                class="inline-flex items-center gap-1 rounded-full border bg-background/75 px-2.5 py-1 text-xs text-muted-foreground"
                            >
                                <Clock class="h-3.5 w-3.5" />
                                {{ gameDateTimeLabel() }}
                            </span>
                            <span
                                v-if="weekLabel()"
                                :class="dashboardChipClass()"
                            >
                                {{ weekLabel() }}
                            </span>
                            <span
                                class="inline-flex items-center gap-1.5"
                                :class="statusBadgeClass()"
                            >
                                <span
                                    v-if="isLive()"
                                    class="h-1.5 w-1.5 rounded-full bg-red-500 motion-safe:animate-pulse"
                                />
                                {{ statusBadgeLabel() }}
                            </span>
                            <span
                                v-if="predictionFreshnessLabel()"
                                :class="dashboardChipClass()"
                            >
                                {{ predictionFreshnessLabel() }}
                            </span>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span
                                class="rounded-full border border-sky-500/25 bg-sky-500/10 px-2.5 py-1 text-xs font-semibold text-sky-700 dark:text-sky-300"
                            >
                                {{ dashboardPrimaryPickLabel() }}
                            </span>
                            <span
                                class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                                :class="dashboardSignalClass()"
                            >
                                {{ dashboardSignalLabel() }}
                            </span>
                            <span
                                v-if="totalPickPillLabel()"
                                class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                                :class="
                                    totalPickPillStrong()
                                        ? 'border-sky-500/25 bg-sky-500/10 text-sky-700 dark:text-sky-300'
                                        : 'border-muted bg-muted/40 text-muted-foreground'
                                "
                            >
                                {{ totalPickPillLabel() }}
                            </span>
                            <span
                                v-if="isFinal() && finalResultLabel()"
                                class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                                :class="finalResultBadgeClass()"
                            >
                                Projection {{ finalResultLabel() }}
                            </span>
                            <span
                                v-if="isFinal() && totalResultLabel()"
                                class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                                :class="totalResultBadgeClass()"
                            >
                                O/U {{ totalResultLabel() }}
                            </span>
                            <span
                                v-if="trustScoreLabel()"
                                :class="dashboardChipClass()"
                            >
                                {{ trustScoreLabel() }}
                            </span>
                            <span
                                v-for="badge in cfbSignalBadges()"
                                :key="badge.label"
                                class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                                :class="cfbSignalBadgeClass(badge.tone)"
                            >
                                {{ badge.label }}
                            </span>
                        </div>
                    </div>

                    <span
                        class="hidden shrink-0 rounded-full border bg-background/75 px-3 py-1 text-xs font-semibold text-muted-foreground sm:inline-flex"
                    >
                        {{ dashboardSignalLabel() }}
                    </span>
                </div>

                <section
                    v-if="isLive()"
                    data-testid="live-game-insights"
                    class="overflow-hidden border-y border-red-500/15 bg-red-500/[0.035]"
                    aria-label="Live game insights"
                >
                    <div
                        class="flex items-center justify-between gap-3 px-3 py-2"
                    >
                        <div
                            class="flex items-center gap-2 text-xs font-semibold tracking-wide text-red-700 uppercase dark:text-red-300"
                        >
                            <Radio class="h-3.5 w-3.5" />
                            Live read
                        </div>
                        <span class="text-xs text-muted-foreground">
                            {{ liveScoreContextLabel() }}
                        </span>
                    </div>

                    <div
                        class="grid divide-y divide-border/60 border-t border-red-500/10 sm:grid-cols-3 sm:divide-x sm:divide-y-0"
                    >
                        <div
                            class="flex min-h-16 items-center gap-3 px-3 py-2.5"
                        >
                            <div
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-background/75 text-red-600 dark:text-red-400"
                            >
                                <Radio class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div
                                    class="text-[11px] font-medium text-muted-foreground uppercase"
                                >
                                    Game state
                                </div>
                                <div class="truncate text-sm font-semibold">
                                    {{ liveGameStateLabel() }}
                                </div>
                                <div
                                    class="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground"
                                >
                                    <span>{{ liveGameStateMeta() }}</span>
                                    <span
                                        v-if="
                                            isMlbPrediction() &&
                                            gameOuts() !== null
                                        "
                                        class="inline-flex gap-1"
                                        :aria-label="`${gameOuts()} outs`"
                                    >
                                        <span
                                            v-for="outNumber in 3"
                                            :key="outNumber"
                                            class="h-1.5 w-1.5 rounded-full border border-red-500/40"
                                            :class="
                                                outNumber <= gameOuts()!
                                                    ? 'bg-red-500'
                                                    : 'bg-transparent'
                                            "
                                        />
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div
                            class="flex min-h-16 items-center gap-3 px-3 py-2.5"
                        >
                            <div
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-background/75 text-sky-600 dark:text-sky-400"
                            >
                                <Activity class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div
                                    class="text-[11px] font-medium text-muted-foreground uppercase"
                                >
                                    Win outlook
                                </div>
                                <div class="truncate text-sm font-semibold">
                                    {{ liveWinOutlookValue() }}
                                </div>
                                <div
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ liveWinOutlookMeta() }}
                                </div>
                            </div>
                        </div>

                        <div
                            class="flex min-h-16 items-center gap-3 px-3 py-2.5"
                        >
                            <div
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-background/75 text-amber-600 dark:text-amber-400"
                            >
                                <Gauge class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div
                                    class="text-[11px] font-medium text-muted-foreground uppercase"
                                >
                                    Updated total
                                </div>
                                <div class="truncate text-sm font-semibold">
                                    {{ liveTotalOutlookValue() }}
                                </div>
                                <div
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ liveTotalOutlookMeta() }}
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-t pt-3"
                >
                    <div class="min-w-0 text-xs text-muted-foreground">
                        {{ dashboardFooterContextLabel() }}
                        <span v-if="homeSpreadLineValue() !== null">
                            · Home spread
                            {{ formatSignedNumber(homeSpreadLineValue()!) }}
                        </span>
                        <span v-if="predictedTotalValue() !== null">
                            · Total {{ predictedTotalValue()!.toFixed(1) }}
                        </span>
                        <span v-if="canonicalPregameEdgeLabel()">
                            · {{ canonicalPregameEdgeLabel() }}
                        </span>
                    </div>

                    <span
                        class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold"
                        :class="dashboardActionTextClass()"
                    >
                        Open
                        <ChevronRight
                            class="h-3.5 w-3.5 transition group-hover:translate-x-0.5"
                        />
                    </span>
                </div>
            </Link>

            <div
                v-if="canSavePick && !isFinal()"
                class="mt-4 border-t border-border/70 pt-4"
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
                        <DropdownMenuContent
                            align="end"
                            class="w-56"
                            @click.stop
                        >
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
                                                consensusForOption(option)
                                                    ?.summary
                                            }}
                                        </div>
                                    </div>
                                    <span
                                        v-if="trackedBetForOption(option)"
                                        class="shrink-0 rounded-full border border-emerald-500/25 bg-emerald-500/10 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:text-emerald-300"
                                    >
                                        Tracked
                                    </span>
                                </div>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
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
