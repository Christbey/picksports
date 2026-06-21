import {
    candidateRecommendation,
    getPredictionRecommendation,
    promotionStatus,
    type PredictionWithRecommendation,
} from '@/lib/predictionRecommendation';

type PredictionTimingPhase = 'pregame' | 'live' | 'final';

export type PredictionTiming = {
    phase: PredictionTimingPhase;
    label: string;
    description: string;
    tone: 'pregame' | 'live' | 'final';
};

type PredictionWithGameState = PredictionWithRecommendation & {
    status?: string | null;
    game?: {
        status?: string | null;
        is_live?: boolean | null;
        [key: string]: unknown;
    } | null;
    live_win_probability?: number | string | null;
    live_predicted_spread?: number | string | null;
    live_predicted_total?: number | string | null;
    live_updated_at?: string | null;
    [key: string]: unknown;
};

export function predictionTiming(
    prediction: PredictionWithGameState | null | undefined,
): PredictionTiming {
    const status = String(
        prediction?.status ?? prediction?.game?.status ?? '',
    ).toLowerCase();
    const publicRecommendation = prediction
        ? getPredictionRecommendation(prediction)
        : null;
    const trackingRecommendation = prediction
        ? candidateRecommendation(prediction)
        : null;
    const promotion = prediction ? promotionStatus(prediction) : null;

    const explicitPhase = String(
        publicRecommendation?.prediction_phase ??
            trackingRecommendation?.prediction_phase ??
            promotion?.game_phase ??
            '',
    ).toLowerCase();

    const hasLiveFields = [
        prediction?.live_win_probability,
        prediction?.live_predicted_spread,
        prediction?.live_predicted_total,
        prediction?.live_updated_at,
    ].some((value) => value !== null && value !== undefined && value !== '');

    const isLive =
        explicitPhase === 'live' ||
        publicRecommendation?.recommendation_type === 'monitor' ||
        trackingRecommendation?.recommendation_type === 'monitor' ||
        prediction?.game?.is_live === true ||
        hasLiveFields ||
        status.includes('live') ||
        status.includes('in_progress');

    if (isLive) {
        return {
            phase: 'live',
            label: 'Live Prediction',
            description:
                'Updated during the game from live score/state context. Do not treat this as the locked pregame pick.',
            tone: 'live',
        };
    }

    if (status.includes('final')) {
        return {
            phase: 'final',
            label: 'Pregame Result',
            description:
                'Game is final. This reflects the stored pregame prediction and its grading result.',
            tone: 'final',
        };
    }

    return {
        phase: 'pregame',
        label: 'Pregame Prediction',
        description:
            'Locked before first pitch from pregame model, market, odds, weather, and lineup context.',
        tone: 'pregame',
    };
}

export function predictionTimingBadgeClass(timing: PredictionTiming): string {
    if (timing.tone === 'live') {
        return 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-300';
    }

    if (timing.tone === 'final') {
        return 'border-slate-500/30 bg-slate-500/10 text-slate-700 dark:text-slate-300';
    }

    return 'border-sky-500/25 bg-sky-500/10 text-sky-700 dark:text-sky-300';
}
