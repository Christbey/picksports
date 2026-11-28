import { isFinalGameStatus } from '@/lib/gameStatus';
import { predictionHomeMargin } from '@/lib/predictionSpread';
import type {
    DashboardPrediction,
    LivePredictionData,
    PredictionListItem,
} from '@/types';

const LIVE_STATUSES = new Set([
    'STATUS_IN_PROGRESS',
    'STATUS_HALFTIME',
    'STATUS_END_PERIOD',
]);

type PredictionCardInput = DashboardPrediction | PredictionListItem;

interface NormalizedPredictionLiveState {
    isDashboard: boolean;
    isLive: boolean;
    isFinal: boolean;
    status: string | null;
    homeScore: number | null;
    awayScore: number | null;
    period: number | null;
    inning: number | null;
    gameClock: string | null;
    inningState: string | null;
    balls: number | null;
    strikes: number | null;
    outs: number | null;
    liveWinProbability: number | null;
    livePredictedSpread: number | null;
    livePredictedTotal: number | null;
    liveSecondsRemaining: number | null;
    liveOutsRemaining: number | null;
    preGameWinProbability: number | null;
    preGamePredictedSpread: number | null;
    preGamePredictedTotal: number | null;
}

export function isPredictionListItem(
    prediction: PredictionCardInput,
): prediction is PredictionListItem {
    return (
        (prediction as PredictionListItem).game !== null &&
        typeof (prediction as PredictionListItem).game === 'object'
    );
}

export function normalizePredictionLiveState(
    prediction: PredictionCardInput,
): NormalizedPredictionLiveState {
    if (!isPredictionListItem(prediction)) {
        return {
            isDashboard: true,
            isLive:
                !isFinalGameStatus(prediction.status) && !!prediction.is_live,
            isFinal:
                isFinalGameStatus(prediction.status) || !!prediction.is_final,
            status: prediction.status ?? null,
            homeScore: prediction.home_score ?? null,
            awayScore: prediction.away_score ?? null,
            period: prediction.period ?? null,
            inning: prediction.inning ?? null,
            gameClock: prediction.game_clock ?? null,
            inningState: prediction.inning_state ?? null,
            balls: prediction.balls ?? null,
            strikes: prediction.strikes ?? null,
            outs: prediction.outs ?? null,
            liveWinProbability: prediction.live_win_probability ?? null,
            livePredictedSpread: prediction.live_predicted_spread ?? null,
            livePredictedTotal: prediction.live_predicted_total ?? null,
            liveSecondsRemaining: prediction.live_seconds_remaining ?? null,
            liveOutsRemaining: prediction.live_outs_remaining ?? null,
            preGameWinProbability: prediction.win_probability ?? null,
            preGamePredictedSpread:
                predictionHomeMargin(prediction, true) ?? null,
            preGamePredictedTotal: prediction.predicted_total ?? null,
        };
    }

    const gameLiveProbability = prediction.game.live_win_probability;
    let liveWinProbability = prediction.live_win_probability ?? null;

    if (liveWinProbability === null && gameLiveProbability) {
        if (typeof gameLiveProbability.home_win_probability === 'number') {
            liveWinProbability = gameLiveProbability.home_win_probability;
        } else if (
            typeof gameLiveProbability.away_win_probability === 'number'
        ) {
            liveWinProbability = 1 - gameLiveProbability.away_win_probability;
        }
    }

    return {
        isDashboard: false,
        isLive:
            !isFinalGameStatus(prediction.game.status) &&
            ((gameLiveProbability?.is_live ?? false) ||
                LIVE_STATUSES.has(prediction.game.status)),
        isFinal: isFinalGameStatus(prediction.game.status),
        status: prediction.game.status ?? null,
        homeScore: prediction.game.home_score ?? null,
        awayScore: prediction.game.away_score ?? null,
        period: prediction.game.period ?? null,
        inning: prediction.game.inning ?? null,
        gameClock: prediction.game.clock ?? null,
        inningState: prediction.game.inning_half ?? null,
        balls: prediction.game.balls ?? null,
        strikes: prediction.game.strikes ?? null,
        outs: prediction.game.outs ?? null,
        liveWinProbability,
        livePredictedSpread: prediction.live_predicted_spread ?? null,
        livePredictedTotal: prediction.live_predicted_total ?? null,
        liveSecondsRemaining:
            prediction.live_seconds_remaining ??
            gameLiveProbability?.seconds_remaining ??
            null,
        liveOutsRemaining: prediction.live_outs_remaining ?? null,
        preGameWinProbability: prediction.win_probability ?? null,
        preGamePredictedSpread: predictionHomeMargin(prediction) ?? null,
        preGamePredictedTotal: prediction.predicted_total ?? null,
    };
}

export function hasPredictionLiveData(
    prediction: PredictionCardInput,
): boolean {
    const normalized = normalizePredictionLiveState(prediction);

    return normalized.isLive && normalized.liveWinProbability !== null;
}

export function buildPredictionLiveData(
    prediction: PredictionCardInput,
): LivePredictionData | undefined {
    const normalized = normalizePredictionLiveState(prediction);

    if (!normalized.isLive) {
        return undefined;
    }

    return {
        isLive: true,
        homeScore: normalized.homeScore,
        awayScore: normalized.awayScore,
        period: normalized.period,
        inning: normalized.inning,
        gameClock: normalized.gameClock,
        inningState: normalized.inningState,
        balls: normalized.balls,
        strikes: normalized.strikes,
        outs: normalized.outs,
        status: normalized.status,
        liveWinProbability: normalized.liveWinProbability,
        livePredictedSpread: normalized.livePredictedSpread,
        livePredictedTotal: normalized.livePredictedTotal,
        liveSecondsRemaining: normalized.liveSecondsRemaining,
        liveOutsRemaining: normalized.liveOutsRemaining,
        preGameWinProbability: normalized.preGameWinProbability,
        preGamePredictedSpread: normalized.preGamePredictedSpread,
        preGamePredictedTotal: normalized.preGamePredictedTotal,
    };
}
