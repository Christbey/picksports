import { optionalPredictionNumber as numericValue } from '@/lib/predictionNormalization';
import { computed } from 'vue';
import { predictionWeather } from '@/lib/predictionWeather';
import { pregameBetCalls } from '@/lib/pregameBetCalls';
import {
    candidateRecommendation,
    getPredictionRecommendation,
    isPromotionBlocked,
    isBetRecommendation,
    isLeanRecommendation,
    isLiveMonitor,
} from '@/lib/predictionRecommendation';
import type {
    PredictionCardProps,
    CfbSignalBadge,
} from '@/types/prediction-card';
import type { usePredictionCardGame } from '@/composables/usePredictionCardGame';
export function usePredictionCardRecommendation(
    props: PredictionCardProps,
    game: ReturnType<typeof usePredictionCardGame>,
) {
    const {
        formatSignedNumber,
        dashboardChipClass,
        awayTeamLabel,
        homeTeamLabel,
        isLive,
        isFinal,
        preGameWinProbability,
        isMlbPrediction,
        isNflPrediction,
        isCfbPrediction,
        predictionType,
    } = game;
    const weatherForecast = computed(() =>
        predictionWeather(
            'weather_forecast' in props.prediction
                ? props.prediction.weather_forecast
                : null,
        ),
    );
    const pregameCalls = computed(() =>
        pregameBetCalls(props.prediction, homeTeamLabel(), awayTeamLabel()),
    );

    function preGameTeamLabel(): string {
        return preGameWinProbability() >= 0.5
            ? homeTeamLabel()
            : awayTeamLabel();
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

    function cfbSignalContext(): Record<string, unknown> {
        if (!isCfbPrediction()) return {};

        return asRecord(predictionType.value?.cfb_signal_context);
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

        const bookmakerHomeLine = numericValue(
            market.current_bookmaker_home_line,
        );
        if (bookmakerHomeLine !== null) {
            const movedToward = market.line_moved_toward_model === true;
            const movedAgainst = market.line_moved_against_model === true;
            badges.push({
                label: `Market ${formatSignedNumber(bookmakerHomeLine)}${
                    movedToward ? ' toward' : movedAgainst ? ' against' : ''
                }`,
                tone: movedAgainst
                    ? 'watch'
                    : movedToward
                      ? 'positive'
                      : 'neutral',
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
        if (
            playerAvailability.applied === true &&
            availabilitySpread !== null
        ) {
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

    function predictionAnalysis() {
        return props.prediction.prediction_analysis ?? null;
    }

    function aiAnalysis() {
        return props.prediction.ai_analysis ?? null;
    }

    function externalGameContext() {
        return aiAnalysis()?.external_game_context ?? null;
    }

    function hasExternalGameContext(): boolean {
        return externalGameContext() !== null;
    }

    function externalContextConfidenceLabel(): string | null {
        const confidence = externalGameContext()?.confidence;

        return typeof confidence === 'number'
            ? `${Math.round(confidence)} Context Trust`
            : null;
    }

    function externalContextAdjustmentLabel(): string | null {
        const adjustment = externalGameContext()?.deterministic_adjustment;
        if (!adjustment?.eligible) return null;

        const parts = [];
        if (typeof adjustment.home_margin_points === 'number') {
            parts.push(
                `Spread ${formatSignedNumber(adjustment.home_margin_points)} pts`,
            );
        }
        if (typeof adjustment.total_points === 'number') {
            parts.push(
                `Total ${formatSignedNumber(adjustment.total_points)} pts`,
            );
        }

        return parts.length ? parts.join(' · ') : null;
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

    function valueSignal() {
        return predictionType.value?.value_signal ?? null;
    }

    function valueSignalBest() {
        return valueSignal()?.best ?? null;
    }

    function canonicalRecommendation() {
        return getPredictionRecommendation(props.prediction);
    }

    function canonicalCandidateRecommendation() {
        return candidateRecommendation(props.prediction);
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
            valueSignal()?.decision_status === 'provisional' ||
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
            return 'border-sky-500/25 bg-card hover:border-sky-500/45';
        }

        return 'border-border/70 bg-card/80 hover:border-sky-500/30';
    }

    function dashboardRailClass(): string {
        const tone = dashboardCardTone();

        if (tone === 'positive') return 'bg-emerald-500';
        if (tone === 'negative') return 'bg-red-500';
        if (tone === 'candidate') return 'bg-sky-500';
        if (tone === 'live') return 'bg-sky-500';

        return 'bg-slate-500/40';
    }

    function dashboardPrimaryPickLabel(): string {
        return `Moneyline pick: ${preGameTeamLabel()}`;
    }

    function dashboardSignalLabel(): string | null {
        const classification =
            predictionAnalysis()?.bet_classification ??
            aiAnalysis()?.bet_classification;
        if (
            classification
                ?.replaceAll('_', ' ')
                .toLowerCase()
                .includes('research hold')
        ) {
            return null;
        }

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
                return null;
            }
        }

        const label =
            canonicalRecommendationLabel() ??
            betClassificationLabel() ??
            aiBetClassificationLabel();

        return label &&
            [
                'Model Bet',
                'Model Lean',
                'Bet',
                'Bettable Edge',
                'Lean',
                'Lean Edge',
            ].includes(label)
            ? label
            : null;
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
            return 'border-border/60 bg-muted/30 text-muted-foreground';
        }

        return 'border-muted bg-muted/40 text-muted-foreground';
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

    return {
        weatherForecast,
        pregameCalls,
        cfbSignalBadges,
        cfbSignalBadgeClass,
        externalGameContext,
        hasExternalGameContext,
        externalContextConfidenceLabel,
        externalContextAdjustmentLabel,
        valueSignal,
        finalResultBadgeClass,
        finalResultLabel,
        dashboardCardClass,
        dashboardRailClass,
        dashboardChipClass,
        dashboardPrimaryPickLabel,
        dashboardSignalLabel,
        dashboardSignalClass,
        totalPickPillLabel,
        totalPickPillStrong,
        totalResultLabel,
        totalResultBadgeClass,
    };
}
