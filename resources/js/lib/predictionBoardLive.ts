import type { ApiV2Prediction, PredictionListItem } from '@/types';

const numeric = (value: unknown): number | null =>
    typeof value === 'number' && Number.isFinite(value) ? value : null;

export function predictionBoardLiveFields(prediction: ApiV2Prediction) {
    return {
        live_predicted_spread: numeric(prediction.live_predicted_spread),
        live_predicted_total: numeric(prediction.live_predicted_total),
        live_win_probability: numeric(prediction.live_win_probability),
        live_seconds_remaining: numeric(prediction.live_seconds_remaining),
        live_outs_remaining: numeric(prediction.live_outs_remaining),
        live_updated_at:
            typeof prediction.live_updated_at === 'string'
                ? prediction.live_updated_at
                : null,
        live_status:
            typeof prediction.live_status === 'string'
                ? prediction.live_status
                : null,
        live_unavailable_reason:
            typeof prediction.live_unavailable_reason === 'string'
                ? prediction.live_unavailable_reason
                : null,
    };
}

export function liveForecastMessage(
    prediction: Pick<
        PredictionListItem,
        'live_status' | 'live_unavailable_reason'
    >,
): string | null {
    if (prediction.live_status === 'stale') return 'Live update delayed';
    if (prediction.live_status === 'updating')
        return 'Waiting for first live update';
    if (prediction.live_status !== 'unavailable') return null;
    switch (prediction.live_unavailable_reason) {
        case 'missing_baseline':
            return 'Live forecast unavailable: no valid pregame baseline';
        case 'incomplete_game_state':
            return 'Live forecast unavailable: incomplete score or clock';
        case 'overtime':
            return 'Live forecast unavailable during overtime';
        default:
            return 'Live forecast unavailable';
    }
}
