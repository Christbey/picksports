import { nflBoardPresentation } from '@/lib/nflBoardPresentation';
import { teamText } from '@/lib/matchupBoardPresentation';
import type { ApiV2Prediction, ApiV2Record } from '@/types';
import type { BoardFilter } from '@/types/nfl-matchup-board';
export function record(value: unknown): ApiV2Record {
    return value && typeof value === 'object' ? (value as ApiV2Record) : {};
}

export function proLayer(prediction: ApiV2Prediction): ApiV2Record {
    return record(prediction.pro_signal_layer);
}

export function marketScores(prediction: ApiV2Prediction): ApiV2Record {
    return record(proLayer(prediction).market_scores);
}

export function analysis(prediction: ApiV2Prediction): ApiV2Record {
    return record(prediction.prediction_analysis);
}

export function classification(prediction: ApiV2Prediction): string {
    return String(
        analysis(prediction).bet_classification ??
            proLayer(prediction).bet_classification ??
            proLayer(prediction).classification ??
            '',
    )
        .trim()
        .toLowerCase();
}

export function scoreTier(
    prediction: ApiV2Prediction,
    market: 'winner' | 'spread' | 'total',
): string {
    return String(record(marketScores(prediction)[market]).tier ?? '')
        .trim()
        .toLowerCase();
}

export function filterMatches(
    prediction: ApiV2Prediction,
    filter: BoardFilter,
): boolean {
    const view = nflBoardPresentation(prediction);
    const spreadEdge = view.spreadEdge;
    const totalEdge = view.totalEdge;
    const cls = classification(prediction);

    if (filter === 'bets')
        return (
            cls === 'bet' &&
            view.researchStatus === 'reviewed' &&
            view.researchDecision === 'candidate'
        );
    if (filter === 'watchlist') {
        return (
            cls.includes('watchlist') ||
            cls === 'lean' ||
            scoreTier(prediction, 'winner') === 'watchlist'
        );
    }
    if (filter === 'spread') {
        return (
            scoreTier(prediction, 'spread') === 'watchlist' ||
            (spreadEdge !== null && Math.abs(spreadEdge) >= 2.5)
        );
    }
    if (filter === 'total') {
        return (
            scoreTier(prediction, 'total') === 'watchlist' ||
            (totalEdge !== null && Math.abs(totalEdge) >= 2)
        );
    }
    if (filter === 'winner') {
        return ['lean', 'watchlist', 'official_candidate'].includes(
            scoreTier(prediction, 'winner'),
        );
    }
    if (filter === 'finals') {
        return String(prediction.status ?? prediction.game?.status ?? '')
            .toLowerCase()
            .includes('final');
    }

    return true;
}

export function searchHaystack(prediction: ApiV2Prediction): string {
    return [
        prediction.game?.short_name,
        teamText(prediction.game?.away_team),
        teamText(prediction.game?.home_team),
        classification(prediction),
        scoreTier(prediction, 'winner'),
        scoreTier(prediction, 'spread'),
        scoreTier(prediction, 'total'),
        ...(Array.isArray(proLayer(prediction).reason_codes)
            ? (proLayer(prediction).reason_codes as unknown[]).map((code) =>
                  String(code),
              )
            : []),
    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();
}
